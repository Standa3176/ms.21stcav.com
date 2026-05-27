<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
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
 * Each row also carries WHERE its two prices come from, for the bucket popup:
 *   - supplier_name : the supplier behind "Our cost (ex)" = the cheapest current
 *     SupplierOfferSnapshot for the product (the offer that set buy_price).
 *   - competitor_name : the competitor behind "Lowest comp (ex)" = the winning
 *     competitor in the lowest-across-competitors selection.
 * Both are resolved with BATCHED lookups only (no per-row queries in the
 * chunkById loop) and are nullable (string|null) when the source row is absent.
 * Competitor names are resolved via a raw DB::select on the `competitors` table
 * (NOT the Competitor model) so Pricing keeps zero dependency on the Competitor
 * domain — mirroring how competitor_prices are already read here.
 *
 * Performance: one windowed SQL pass reduces competitor_prices to the latest
 * row per (competitor, sku) within the window; the rest is in-memory. Cheap
 * enough to run on page load behind a short cache (the page wraps it in
 * Cache::remember). Lists are full + sorted worst-margin first.
 */
final class CompetitorPositionScanner
{
    /**
     * Lists are FULL (uncapped) so the dashboard modals + CSV export can show
     * every row; the page slices for the inline preview. competitor_prices is
     * small + the scan is cheap, so the full result caches fine.
     *
     * @return array{
     *   below_cost: array<int, array{sku:string,name:string,cost_ex:int,comp_ex:int,margin_bps:int,supplier_name:?string,competitor_name:?string}>,
     *   at_floor: array<int, array{sku:string,name:string,cost_ex:int,comp_ex:int,margin_bps:int,supplier_name:?string,competitor_name:?string}>,
     *   winnable: array<int, array{sku:string,name:string,cost_ex:int,comp_ex:int,margin_bps:int,supplier_name:?string,competitor_name:?string}>,
     *   below_cost_count:int, at_floor_count:int, winnable_count:int, matched_count:int,
     *   floor_bps:int, max_age_days:int, computed_at:string
     * }
     */
    public function compute(int $maxAgeDays = 30, ?int $floorBps = null): array
    {
        $floorBps = $floorBps ?? (int) config('competitor.min_margin_floor_bps', 600);
        $maxAgeDays = max(1, $maxAgeDays);
        $cutoff = now()->subDays($maxAgeDays)->toDateTimeString();

        $lowestByKey = $this->lowestCompetitorByKey($cutoff);

        $belowCost = [];
        $atFloor = [];
        $winnable = [];

        // product_id of each kept row → reference, so supplier names map back after batching.
        /** @var array<int, list<array{bucket:int, idx:int}>> $rowsByProductId */
        $rowsByProductId = [];
        // Distinct winning competitor ids actually used by kept rows (for one name query).
        /** @var array<int, true> $usedCompetitorIds */
        $usedCompetitorIds = [];

        Product::query()
            ->where('type', 'simple')
            ->where('status', 'publish') // pending/obsolete (no-supplier) products don't count — see SourcingGapScanner
            ->whereNotNull('buy_price')
            ->where('buy_price', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($products) use (
                &$belowCost, &$atFloor, &$winnable, &$rowsByProductId, &$usedCompetitorIds, $lowestByKey, $floorBps
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

                    $compEx = $lowestByKey[$key]['ex'];
                    $winningCompetitorId = $lowestByKey[$key]['competitor_id'];
                    $marginBps = intdiv(($compEx - $costEx) * 10000, $costEx);

                    $row = [
                        'sku' => (string) $product->sku,
                        'name' => (string) $product->name,
                        'cost_ex' => $costEx,
                        'comp_ex' => $compEx,
                        'margin_bps' => $marginBps,
                        'supplier_name' => null, // filled in batched after the loop
                        'competitor_name' => null, // filled in batched after the loop
                    ];

                    if ($marginBps <= 0) {
                        $bucket = 0;
                        $belowCost[] = $row;
                        $idx = count($belowCost) - 1;
                    } elseif ($marginBps < $floorBps) {
                        $bucket = 1;
                        $atFloor[] = $row;
                        $idx = count($atFloor) - 1;
                    } else {
                        $bucket = 2;
                        $winnable[] = $row;
                        $idx = count($winnable) - 1;
                    }

                    $productId = (int) $product->id;
                    $rowsByProductId[$productId][] = ['bucket' => $bucket, 'idx' => $idx];

                    if ($winningCompetitorId > 0) {
                        $usedCompetitorIds[$winningCompetitorId] = true;
                        // Stash the winning id on the row reference so we can map its name.
                        $this->setCompetitorIdRef($belowCost, $atFloor, $winnable, $bucket, $idx, $winningCompetitorId);
                    }
                }
            });

        // Batched name resolution (one query each; no per-row lookups above).
        $supplierNames = $this->cheapestSupplierNameByProductId(array_keys($rowsByProductId), $maxAgeDays);
        $competitorNames = $this->competitorNamesByIds(array_keys($usedCompetitorIds));

        foreach ($rowsByProductId as $productId => $refs) {
            $supplierName = $supplierNames[$productId] ?? null;
            foreach ($refs as $ref) {
                $bucket = $ref['bucket'];
                $idx = $ref['idx'];
                if ($bucket === 0) {
                    $this->applyNames($belowCost[$idx], $supplierName, $competitorNames);
                } elseif ($bucket === 1) {
                    $this->applyNames($atFloor[$idx], $supplierName, $competitorNames);
                } else {
                    $this->applyNames($winnable[$idx], $supplierName, $competitorNames);
                }
            }
        }

        // Worst (lowest margin) first — the most urgent rows surface at the top.
        $byMargin = static fn (array $a, array $b): int => $a['margin_bps'] <=> $b['margin_bps'];
        usort($belowCost, $byMargin);
        usort($atFloor, $byMargin);
        usort($winnable, $byMargin);

        // Strip the internal competitor-id helper key before returning the public shape.
        $strip = static function (array $rows): array {
            foreach ($rows as &$r) {
                unset($r['_competitor_id']);
            }

            return $rows;
        };
        $belowCost = $strip($belowCost);
        $atFloor = $strip($atFloor);
        $winnable = $strip($winnable);

        return [
            'below_cost' => $belowCost,
            'at_floor' => $atFloor,
            'winnable' => $winnable,
            'below_cost_count' => count($belowCost),
            'at_floor_count' => count($atFloor),
            'winnable_count' => count($winnable),
            'matched_count' => count($belowCost) + count($atFloor) + count($winnable),
            'floor_bps' => $floorBps,
            'max_age_days' => $maxAgeDays,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Stash the winning competitor_id on the just-appended row (by reference into
     * its bucket array) under a private `_competitor_id` key, stripped before return.
     *
     * @param  array<int, array<string, mixed>>  $belowCost
     * @param  array<int, array<string, mixed>>  $atFloor
     * @param  array<int, array<string, mixed>>  $winnable
     */
    private function setCompetitorIdRef(array &$belowCost, array &$atFloor, array &$winnable, int $bucket, int $idx, int $competitorId): void
    {
        if ($bucket === 0) {
            $belowCost[$idx]['_competitor_id'] = $competitorId;
        } elseif ($bucket === 1) {
            $atFloor[$idx]['_competitor_id'] = $competitorId;
        } else {
            $winnable[$idx]['_competitor_id'] = $competitorId;
        }
    }

    /**
     * Write supplier_name + competitor_name onto a row using the batched maps.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $competitorNames
     */
    private function applyNames(array &$row, ?string $supplierName, array $competitorNames): void
    {
        $row['supplier_name'] = $supplierName !== null ? (string) $supplierName : null;

        $cid = isset($row['_competitor_id']) ? (int) $row['_competitor_id'] : 0;
        $row['competitor_name'] = ($cid > 0 && isset($competitorNames[$cid]))
            ? (string) $competitorNames[$cid]
            : null;
    }

    /**
     * lowercase match-key (competitor sku AND mpn) → the lowest CURRENT competitor
     * ex-VAT pennies AND the competitor_id that achieved it. "Current" = latest row
     * per (competitor, sku) within the window; "lowest" = min across competitors,
     * with ties broken deterministically by the lowest competitor_id. Indexing each
     * competitor row under both its sku and mpn replicates floor-report's `sku OR mpn`
     * match from the product side (we look up by product.sku).
     *
     * @return array<string, array{ex:int, competitor_id:int}>
     */
    private function lowestCompetitorByKey(string $cutoff): array
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
            // Sort by competitor_id ASC so that, on a price tie, the lowest id wins
            // the argmin (we only replace on a STRICTLY lower price below).
            ksort($comps);
            $winnerId = 0;
            $winnerPrice = PHP_INT_MAX;
            foreach ($comps as $cid => $price) {
                if ($price < $winnerPrice) {
                    $winnerPrice = $price;
                    $winnerId = $cid;
                }
            }
            $lowest[$k] = ['ex' => $winnerPrice, 'competitor_id' => $winnerId];
        }

        return $lowest;
    }

    /**
     * Batched product_id → cheapest-current supplier_name map (Products domain —
     * deptrac-allowed). Mirrors PriceHistoryPage's cheapest-current selection
     * (recorded_at DESC, price ASC) so the displayed supplier MATCHES the offer
     * that set buy_price. Restricted to the same recency window as the scan so an
     * aged-out offer never surfaces a stale supplier. Returns no entry (→ null at
     * the call site) for products with no qualifying offer row.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, string>
     */
    private function cheapestSupplierNameByProductId(array $productIds, int $maxAgeDays): array
    {
        if ($productIds === []) {
            return [];
        }

        $cutoffDate = today()->subDays($maxAgeDays)->toDateString();

        // Single query; group + pick cheapest-current per product in PHP. The id
        // set is bounded by the kept rows so this stays one pass (no N+1).
        $offers = SupplierOfferSnapshot::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('price')
            ->where('recorded_at', '>=', $cutoffDate)
            ->orderBy('recorded_at', 'desc')
            ->orderBy('price', 'asc')
            ->get(['product_id', 'supplier_name', 'price', 'recorded_at']);

        /** @var array<int, string> $names */
        $names = [];
        foreach ($offers as $offer) {
            $pid = (int) $offer->product_id;
            if (isset($names[$pid])) {
                continue; // first row per product = cheapest current (ordering above)
            }
            $supplierName = $offer->supplier_name;
            if ($supplierName === null || $supplierName === '') {
                continue;
            }
            $names[$pid] = (string) $supplierName;
        }

        return $names;
    }

    /**
     * Batched competitor_id → name map via a raw DB::select on the `competitors`
     * table. Deliberately does NOT use App\Domain\Competitor\Models\Competitor:
     * Pricing must not depend on the Competitor domain (deptrac), so names are
     * read the same raw way competitor_prices already are. Ids are bound as
     * placeholders (never interpolated) — T-c0m-01 injection mitigation.
     *
     * @param  array<int, int>  $competitorIds
     * @return array<int, string>
     */
    private function competitorNamesByIds(array $competitorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $competitorIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select(
            'SELECT id, name FROM competitors WHERE id IN ('.$placeholders.')',
            $ids,
        );

        /** @var array<int, string> $names */
        $names = [];
        foreach ($rows as $r) {
            $name = $r->name;
            if ($name === null || $name === '') {
                continue;
            }
            $names[(int) $r->id] = (string) $name;
        }

        return $names;
    }
}
