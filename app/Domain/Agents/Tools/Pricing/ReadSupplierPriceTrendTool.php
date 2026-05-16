<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Tools\TruncatingTool;
use App\Domain\Products\Models\Product;
use Prism\Prism\Facades\Tool as PrismToolFacade;
use Spatie\Activitylog\Models\Activity;

/**
 * Phase 10 Plan 02 — read_supplier_price_trend real implementation.
 *
 * Tries Option A first (RESEARCH §Tool 3): query `activity_log` for entries
 * where `subject_type='App\Domain\Products\Models\Product'` AND
 * `properties.old.buy_price` exists, scoped to the SKU's product. When the
 * audit trail returns empty (Phase 2 doesn't currently log per-product
 * buy_price changes due to volume — RESEARCH A5), the tool DEGRADES to
 * `{"data_points": [], "current_buy_price_pennies": $product->buy_price * 100,
 *   "_note": "supplier price history not retained ..."}` — never throws.
 *
 * The conversion `buy_price * 100` reflects the v1 schema: `products.buy_price`
 * is `decimal:4` (pounds.pence); the LLM-facing surface is consistently
 * pennies (matches `competitor_prices.price_pennies_ex_vat` and
 * `pricing_rules.margin_basis_points` which are also penny/bps integers).
 *
 * Schema returned (best case — Option A audit_log entries available):
 * {
 *   "sku": "LOGI-MEETUP",
 *   "window_days": 90,
 *   "data_points": [
 *     {"date": "2026-04-22", "buy_price_pennies": 120000, "old_buy_price_pennies": 122000}
 *   ],
 *   "current_buy_price_pennies": 120000
 * }
 *
 * Unknown SKU returns `{sku, data_points:[], current_buy_price_pennies:0}` +
 * `_note` (never throws).
 */
final class ReadSupplierPriceTrendTool extends TruncatingTool
{
    private const QUERY_LIMIT = 30;

    private const WINDOW_DAYS = 90;

    public function name(): string
    {
        return 'read_supplier_price_trend';
    }

    public function description(): string
    {
        return 'Read the last 90 days of supplier price changes for a SKU. Returns up to 30 data points (downsampled if more exist) showing buy-price trajectory. Use to gauge cost-side volatility before proposing margin.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'The SKU to look up')
            ->using(fn (string $sku): string => $this->execute($sku));
    }

    private function execute(string $sku): string
    {
        $product = Product::query()
            ->where('sku', $sku)
            ->select(['id', 'sku', 'buy_price'])
            ->first();

        if ($product === null) {
            return $this->capJson([
                'sku' => $sku,
                'window_days' => self::WINDOW_DAYS,
                'data_points' => [],
                'current_buy_price_pennies' => 0,
                '_note' => 'product not found',
            ], 0);
        }

        $since = now()->subDays(self::WINDOW_DAYS);

        // Option A — query activity_log for per-product buy_price changes.
        // SQLite-portable approach: filter post-fetch by checking the
        // properties JSON contains old.buy_price (avoids whereJsonContainsKey
        // which behaves differently across MySQL/SQLite).
        $logRows = Activity::query()
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(self::QUERY_LIMIT * 2) // Over-fetch then filter to dampen false-negatives
            ->get()
            ->filter(function (Activity $row): bool {
                $props = $row->properties->toArray();

                return isset($props['old']['buy_price']);
            })
            ->take(self::QUERY_LIMIT)
            ->values();

        $points = $logRows->map(fn (Activity $row): array => [
            'date' => $row->created_at?->toDateString(),
            'buy_price_pennies' => (int) round(((float) data_get($row->properties, 'attributes.buy_price', 0)) * 100),
            'old_buy_price_pennies' => (int) round(((float) data_get($row->properties, 'old.buy_price', 0)) * 100),
        ])->all();

        $currentBuyPricePennies = (int) round(((float) $product->buy_price) * 100);

        $payload = [
            'sku' => $sku,
            'window_days' => self::WINDOW_DAYS,
            'data_points' => $points,
            'current_buy_price_pennies' => $currentBuyPricePennies,
        ];

        if (empty($points)) {
            // Degraded fallback per RESEARCH A5 — Phase 2 doesn't log per-SKU
            // buy_price changes (volume protection); current snapshot remains
            // useful context for the agent.
            $payload['_note'] = 'supplier price history not retained — see current_buy_price_pennies for latest snapshot';
        }

        return $this->capJson($payload, count($points));
    }

    /**
     * Trim oldest data_points to reduce payload size. Halves the cap on
     * each invocation.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        if (! isset($payload['data_points']) || ! is_array($payload['data_points'])) {
            return $payload;
        }
        $count = count($payload['data_points']);
        if ($count <= 1) {
            return $payload;
        }
        $newCount = max(1, (int) floor($count / 2));
        $payload['data_points'] = array_slice($payload['data_points'], 0, $newCount);

        return $payload;
    }
}
