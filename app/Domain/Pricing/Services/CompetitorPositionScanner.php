<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Pricing-Operations read model: where do we sit against competitors right now?
 *
 * Buckets every simple product with a supplier cost + a CURRENT competitor price
 * into the two "needs attention" states the Pricing Operations dashboard shows:
 *
 *   - below_cost : the lowest current competitor sells at or under our ex-VAT
 *     cost (margin ≤ 0). We can never undercut profitably — a supply problem.
 *   - at_floor   : winnable (competitor above cost) but the achievable undercut
 *     margin is below the configured floor (default 6%), so the undercut command
 *     holds us at the floor price rather than chase them down.
 *
 * The margin math MIRRORS PricingFloorReportCommand exactly (all ex-VAT):
 *   marginBps = (lowestCompetitorExVat − costEx) × 10000 / costEx
 *   below_cost ⇔ marginBps ≤ 0 ;  at_floor ⇔ 0 < marginBps < floorBps
 * so the dashboard numbers agree with `pricing:floor-report` and the
 * CompetitorUndercutPricer's `competitor_floor` decision.
 *
 * Performance: one windowed SQL pass reduces competitor_prices to the latest
 * row per (competitor, sku) within the window; the rest is in-memory. Cheap
 * enough to run on page load behind a short cache (the page wraps it in
 * Cache::remember). Lists are capped (worst-margin first) — the counts are exact.
 */
final class CompetitorPositionScanner
{
    /** Hard cap on rows returned per bucket (counts stay exact). */
    private const LIST_CAP = 200;

    /**
     * @return array{
     *   below_cost: array<int, array{sku:string,name:string,cost_ex:int,comp_ex:int,margin_bps:int}>,
     *   at_floor: array<int, array{sku:string,name:string,cost_ex:int,comp_ex:int,margin_bps:int}>,
     *   below_cost_count:int, at_floor_count:int, winnable_count:int, matched_count:int,
     *   floor_bps:int, max_age_days:int, computed_at:string
     * }
     */
    public function compute(int $maxAgeDays = 30, ?int $floorBps = null): array
    {
        $floorBps = $floorBps ?? (int) config('competitor.min_margin_floor_bps', 600);
        $maxAgeDays = max(1, $maxAgeDays);
        $cutoff = now()->subDays($maxAgeDays)->toDateTimeString();

        $lowestByKey = $this->lowestCompetitorExVatByKey($cutoff);

        $belowCost = [];
        $atFloor = [];
        $belowCostCount = 0;
        $atFloorCount = 0;
        $winnable = 0;
        $matched = 0;

        Product::query()
            ->where('type', 'simple')
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($products) use (
                &$belowCost, &$atFloor, &$belowCostCount, &$atFloorCount, &$winnable, &$matched,
                $lowestByKey, $floorBps
            ): void {
                foreach ($products as $product) {
                    $key = strtolower(trim((string) $product->sku));
                    if ($key === '' || ! isset($lowestByKey[$key])) {
                        continue; // no current competitor → cost-plus margin applies, not the floor
                    }
                    $costEx = (int) round(((float) $product->buy_price) * 100);
                    if ($costEx <= 0) {
                        continue;
                    }

                    $compEx = $lowestByKey[$key];
                    $matched++;
                    $marginBps = intdiv(($compEx - $costEx) * 10000, $costEx);

                    $row = [
                        'sku' => (string) $product->sku,
                        'name' => (string) $product->name,
                        'cost_ex' => $costEx,
                        'comp_ex' => $compEx,
                        'margin_bps' => $marginBps,
                    ];

                    if ($marginBps <= 0) {
                        $belowCostCount++;
                        if (count($belowCost) < self::LIST_CAP) {
                            $belowCost[] = $row;
                        }
                    } elseif ($marginBps < $floorBps) {
                        $atFloorCount++;
                        if (count($atFloor) < self::LIST_CAP) {
                            $atFloor[] = $row;
                        }
                    } else {
                        $winnable++;
                    }
                }
            });

        // Worst (lowest margin) first — the most urgent rows surface at the top.
        usort($belowCost, static fn (array $a, array $b): int => $a['margin_bps'] <=> $b['margin_bps']);
        usort($atFloor, static fn (array $a, array $b): int => $a['margin_bps'] <=> $b['margin_bps']);

        return [
            'below_cost' => $belowCost,
            'at_floor' => $atFloor,
            'below_cost_count' => $belowCostCount,
            'at_floor_count' => $atFloorCount,
            'winnable_count' => $winnable,
            'matched_count' => $matched,
            'floor_bps' => $floorBps,
            'max_age_days' => $maxAgeDays,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * lowercase match-key (competitor sku AND mpn) → lowest CURRENT competitor
     * ex-VAT pennies. "Current" = latest row per (competitor, sku) within the
     * window; "lowest" = min across competitors. Indexing each competitor row
     * under both its sku and mpn replicates floor-report's `sku OR mpn` match
     * from the product side (we look up by product.sku).
     *
     * @return array<string, int>
     */
    private function lowestCompetitorExVatByKey(string $cutoff): array
    {
        // Window function: one row per (competitor_id, sku) = its latest price.
        // Supported by MySQL 8 (prod) and SQLite ≥ 3.25 (local/tests).
        $rows = DB::select(
            'SELECT competitor_id, sku, mpn, price_pennies_ex_vat FROM ('
            .'SELECT competitor_id, sku, mpn, price_pennies_ex_vat, '
            .'ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC) AS rn '
            .'FROM competitor_prices WHERE recorded_at >= ? AND price_pennies_ex_vat > 0'
            .') t WHERE t.rn = 1',
            [$cutoff],
        );

        /** @var array<string, array<int, int>> $perKeyComp  key => [competitor_id => latest ex-VAT] */
        $perKeyComp = [];
        foreach ($rows as $r) {
            $price = (int) $r->price_pennies_ex_vat;
            if ($price <= 0) {
                continue;
            }
            $cid = (int) $r->competitor_id;
            foreach ([(string) $r->sku, (string) $r->mpn] as $raw) {
                $k = strtolower(trim($raw));
                if ($k === '') {
                    continue;
                }
                $perKeyComp[$k][$cid] = isset($perKeyComp[$k][$cid])
                    ? min($perKeyComp[$k][$cid], $price)
                    : $price;
            }
        }

        $lowest = [];
        foreach ($perKeyComp as $k => $comps) {
            $lowest[$k] = min($comps);
        }

        return $lowest;
    }
}
