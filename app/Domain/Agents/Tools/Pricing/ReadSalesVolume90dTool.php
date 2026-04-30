<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Products\Models\Product;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 10 Plan 02 — read_sales_volume_90d real implementation.
 *
 * Returns the cached 90-day sales count for a SKU. Reads
 * `products.last_sales_count_90d` + `products.last_sales_count_computed_at`
 * (RESEARCH schema correction — `last_` prefix; CONTEXT.md said
 * `sales_count_computed_at` but the migration
 * `2026_04_21_090600_add_sales_count_90d_to_products.php` uses the prefixed
 * names; A8 from RESEARCH §Assumptions is honoured here).
 *
 * No live aggregation — Phase 5 SalesCounterService recaches nightly into
 * the cached column; this tool reads it in O(1).
 *
 * Schema returned:
 * {
 *   "sku": "LOGI-MEETUP",
 *   "window_days": 90,
 *   "sales_count": 27,
 *   "_cache_age_hours": 4,
 *   "_cache_computed_at": "2026-04-29T06:00:00Z"
 * }
 *
 * `_cache_age_hours` is included whenever the cache has been computed (the
 * agent uses freshness to inform confidence). When the cache is older than
 * 24h the value reflects that staleness; when never computed, `_note` is
 * included instead.
 *
 * Unknown SKU returns sales_count=0 + _note (CONTEXT D-07 sparse-data
 * → low-confidence path); never throws. Single integer + timestamp payload
 * — never truncates in practice but extends TruncatingTool for the
 * architecture-test invariant (PricingToolsObserveSoftCapTest).
 */
final class ReadSalesVolume90dTool extends TruncatingTool
{
    private const WINDOW_DAYS = 90;

    public function name(): string
    {
        return 'read_sales_volume_90d';
    }

    public function description(): string
    {
        return 'Read the cached 90-day sales count for a SKU (units sold). Returns the count plus _cache_age_hours when stale (>24h). Use to gauge demand strength before proposing margin.';
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
            ->select(['sku', 'last_sales_count_90d', 'last_sales_count_computed_at'])
            ->first();

        if ($product === null) {
            return $this->capJson([
                'sku' => $sku,
                'window_days' => self::WINDOW_DAYS,
                'sales_count' => 0,
                '_note' => 'product not found',
            ], 0);
        }

        $payload = [
            'sku' => $sku,
            'window_days' => self::WINDOW_DAYS,
            'sales_count' => (int) $product->last_sales_count_90d,
        ];

        if ($product->last_sales_count_computed_at !== null) {
            $ageHours = (int) round(now()->diffInMinutes($product->last_sales_count_computed_at, true) / 60);
            $payload['_cache_age_hours'] = $ageHours;
            $payload['_cache_computed_at'] = $product->last_sales_count_computed_at->toIso8601String();
        } else {
            $payload['_note'] = 'sales count not yet cached';
        }

        return $this->capJson($payload, 1);
    }

    /**
     * Single integer + timestamp payload — no array to reduce. Returned
     * as-is; the TruncatingTool contract is satisfied for architecture-test
     * discoverability without ever truncating in practice.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        return $payload;
    }
}
