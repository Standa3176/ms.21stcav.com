<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Marketing;

use App\Domain\Agents\Tools\TruncatingTool;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Products\Models\Product;
use Prism\Prism\Facades\Tool as PrismToolFacade;
use Prism\Prism\Tool;

/**
 * Phase 15 Plan 15b-01 — read_margin_opportunity (advice-only AdOptimisationAgent).
 *
 * Own-data read that surfaces the highest-margin, in-stock, published products
 * alongside their competitor position + 90-day demand — so the agent can spot
 * high-margin SKUs worth advertising harder (or over-invested SKUs worth
 * pulling spend from). Margin is the simple sell − buy spread (GBP); the app
 * has no single "margin" column, so this is a focused read (the D-03
 * byte-locked RuleResolver/PriceCalculator money path is NOT touched — this
 * only READS Product prices + CompetitorPrice sightings).
 *
 * READ-ONLY. Per-tool 3 KB soft cap with `_truncated`/`_total_available` hints
 * (mirrors the Phase 10 Pricing read tools). Competitor position reuses the
 * same CompetitorPrice 90-day window the Pricing read_competitor_prices tool
 * reads — grouped per SKU in a single query (no N+1).
 *
 * Schema returned:
 * {
 *   "window_days": 90,
 *   "products": [
 *     {
 *       "sku": "LOGI-MEETUP",
 *       "name": "Logitech MeetUp",
 *       "sell_price_gbp": 700.0,
 *       "buy_price_gbp": 400.0,
 *       "margin_gbp": 300.0,
 *       "margin_pct": 42.86,
 *       "sales_90d": 27,
 *       "stock_status": "instock",
 *       "min_competitor_price_ex_vat_gbp": 650.0,
 *       "competitor_count": 3
 *     }
 *   ],
 *   "_truncated": false,
 *   "_total_available": 20
 * }
 */
final class ReadMarginOpportunityTool extends TruncatingTool
{
    private const QUERY_LIMIT = 20;

    private const COMPETITOR_WINDOW_DAYS = 90;

    public function name(): string
    {
        return 'read_margin_opportunity';
    }

    public function description(): string
    {
        return 'Read the top high-margin, in-stock, published products (sell − buy spread) with their 90-day sales demand and competitor price position. Use to spot high-margin SKUs worth advertising, or over-invested SKUs worth reducing spend on. Advice-only.';
    }

    public function asPrismTool(): Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->using(fn (): string => $this->execute());
    }

    private function execute(): string
    {
        $products = Product::query()
            ->where('status', 'publish')
            ->where('stock_status', 'instock')
            ->whereNotNull('sell_price')->where('sell_price', '>', 0)
            ->whereNotNull('buy_price')->where('buy_price', '>', 0)
            ->whereColumn('sell_price', '>', 'buy_price')
            ->whereNotNull('sku')
            ->orderByRaw('(sell_price - buy_price) DESC')
            ->limit(self::QUERY_LIMIT)
            ->get(['sku', 'name', 'sell_price', 'buy_price', 'stock_status', 'last_sales_count_90d']);

        $skus = $products->pluck('sku')->filter()->values()->all();

        // Single grouped query — competitor position for the candidate SKUs.
        $competitorBySku = collect();
        if ($skus !== []) {
            $competitorBySku = CompetitorPrice::query()
                ->whereIn('sku', $skus)
                ->where('recorded_at', '>=', now()->subDays(self::COMPETITOR_WINDOW_DAYS))
                ->selectRaw('sku')
                ->selectRaw('MIN(price_pennies_ex_vat) as min_price')
                ->selectRaw('COUNT(DISTINCT competitor_id) as competitor_count')
                ->groupBy('sku')
                ->get()
                ->keyBy('sku');
        }

        $items = $products->map(function (Product $p) use ($competitorBySku): array {
            $sell = (float) $p->sell_price;
            $buy = (float) $p->buy_price;
            $margin = round($sell - $buy, 2);
            $comp = $competitorBySku->get($p->sku);

            return [
                'sku' => (string) $p->sku,
                'name' => (string) $p->name,
                'sell_price_gbp' => round($sell, 2),
                'buy_price_gbp' => round($buy, 2),
                'margin_gbp' => $margin,
                'margin_pct' => $sell > 0 ? round(($margin / $sell) * 100, 2) : 0.0,
                'sales_90d' => (int) $p->last_sales_count_90d,
                'stock_status' => (string) $p->stock_status,
                'min_competitor_price_ex_vat_gbp' => $comp !== null
                    ? round(((int) $comp->min_price) / 100, 2)
                    : null,
                'competitor_count' => $comp !== null ? (int) $comp->competitor_count : 0,
            ];
        })->values()->all();

        $total = count($items);

        $payload = [
            'window_days' => self::COMPETITOR_WINDOW_DAYS,
            'products' => $items,
            '_truncated' => false,
            '_total_available' => $total,
        ];

        return $this->capJson($payload, $total);
    }

    /**
     * Trim the products array (already margin-sorted) to fit under the soft
     * cap. Halves the entry count each invocation, preserving the top rows.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        if (! isset($payload['products']) || ! is_array($payload['products'])) {
            return $payload;
        }
        $count = count($payload['products']);
        if ($count <= 1) {
            return $payload;
        }
        $newCount = max(1, (int) floor($count / 2));
        $payload['products'] = array_slice($payload['products'], 0, $newCount);

        return $payload;
    }
}
