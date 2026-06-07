<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Quick task 260607-pys — single golden-ad-target predicate.
 *
 * Computes the live set of products that meet the "we should be running
 * Google Ads against this SKU" criteria:
 *
 *   - status = 'publish' AND type = 'simple'
 *   - buy_price > 0 AND sell_price > 0
 *   - margin (sell - buy) >= minMarginPence (default £199 / 19900p)
 *   - lowest current competitor (gross) within 30-day window EXISTS
 *   - sell < lowest_comp                       (when beatRequired = true)
 *   - has supplier_offer_snapshot stock > 0 within last 7 days
 *                                              (when stockRequired = true)
 *   - brand_id in $brandIds                    (when $brandIds !== [])
 *
 * Used by:
 *   - app/Filament/Pages/AdCandidatesPage.php       (operator-facing UI)
 *   - app/Console/Commands/BackfillMerchantFeedCommand.php (golden-target
 *                                                            selection)
 *   - app/Domain/Dashboard/Services/SnapshotAggregator::computeAdCandidatesHealth
 *
 * ONE SQL surface — the page, the backfill command, and the dashboard tile
 * all share this predicate. Drift between surfaces is structurally
 * impossible: change the rule here, every consumer picks it up.
 *
 * Returns an Illuminate Collection of stdClass rows (aggregated/decorated
 * shape, not Eloquent) — mirrors CompetitorPositionScanner::compute().
 *
 * Performance: one windowed SQL pass on competitor_prices + one windowed
 * pass on supplier_offer_snapshots + one batched Products chunk loop.
 * Brand-name map resolved ONCE before the loop via TaxonomyResolver
 * (cached 1h in the resolver itself — never per-row).
 *
 * Brand-name decoration uses TaxonomyResolver; Pricing → ProductAutoCreate
 * is already in the deptrac allow-list (260606-rld). All filter values are
 * parameter-bound in DB::select — never interpolated (T-pys-01 mitigation).
 */
final class AdCandidateScanner
{
    /** Default 30-day window — mirrors CompetitorPositionScanner. */
    private const COMPETITOR_WINDOW_DAYS = 30;

    /** Default 7-day window for "supplier currently in stock". */
    private const SUPPLIER_STOCK_WINDOW_DAYS = 7;

    public function __construct(private readonly TaxonomyResolver $taxonomy) {}

    /**
     * Compute the golden-ad-target candidate set.
     *
     * @param  array<int, int>  $brandIds  empty = all brands
     * @param  int  $minMarginPence  minimum (sell - buy) in pence; default £199
     * @param  bool  $stockRequired  require supplier stock > 0 in last 7d
     * @param  bool  $beatRequired  require sell < lowest current competitor gross
     */
    public function scan(
        array $brandIds = [],
        int $minMarginPence = 19900,
        bool $stockRequired = true,
        bool $beatRequired = true,
    ): Collection {
        // 1) Build base Product query — narrowed to publish/simple with
        //    positive prices and the optional brand filter.
        $query = Product::query()
            ->where('status', 'publish')
            ->where('type', 'simple')
            ->whereNotNull('buy_price')
            ->whereNotNull('sell_price')
            ->where('buy_price', '>', 0)
            ->where('sell_price', '>', 0);

        if ($brandIds !== []) {
            // whereIn binds the array — never interpolated (T-pys-01).
            $query->whereIn('brand_id', array_values(array_map('intval', $brandIds)));
        }

        // 2) Precompute the lookup maps ONCE so the row loop stays O(N).
        $lowestComp = $this->lowestCompetitorGrossByKey();
        $supplierStock = $this->latestSupplierByProductId();
        $brandNameById = $this->brandNameMap();

        /** @var array<int, stdClass> $rows */
        $rows = [];

        $query->orderBy('id')->chunkById(500, function ($products) use (
            &$rows,
            $lowestComp,
            $supplierStock,
            $brandNameById,
            $minMarginPence,
            $stockRequired,
            $beatRequired,
        ): void {
            foreach ($products as $product) {
                $sku = (string) $product->sku;
                $key = strtolower(trim($sku));
                if ($key === '') {
                    continue;
                }

                $sellPence = (int) round(((float) $product->sell_price) * 100);
                $buyPence = (int) round(((float) $product->buy_price) * 100);
                $marginPence = $sellPence - $buyPence;
                if ($marginPence < $minMarginPence) {
                    continue;
                }

                // Competitor gate — must exist + (optionally) we beat it.
                if (! isset($lowestComp[$key])) {
                    continue;
                }
                $lowestCompPence = (int) $lowestComp[$key];
                if ($beatRequired && $sellPence >= $lowestCompPence) {
                    continue;
                }

                // Supplier-stock gate — require a fresh row with stock > 0.
                $productId = (int) $product->id;
                $supplier = $supplierStock[$productId] ?? null;
                if ($stockRequired) {
                    if ($supplier === null) {
                        continue;
                    }
                    if ((int) ($supplier['stock'] ?? 0) <= 0) {
                        continue;
                    }
                }

                $stock = $supplier !== null ? (int) ($supplier['stock'] ?? 0) : 0;
                $bestSupplier = $supplier !== null
                    ? ($supplier['supplier_name'] ?? null)
                    : null;

                $brandId = $product->brand_id === null ? null : (int) $product->brand_id;
                $brandName = $brandId !== null
                    ? ($brandNameById[$brandId] ?? null)
                    : null;

                // beat_pct_bps in basis-points (negative = undercut).
                $beatPctBps = $lowestCompPence > 0
                    ? intdiv(($sellPence - $lowestCompPence) * 10000, $lowestCompPence)
                    : 0;

                $row = new stdClass;
                $row->sku = $sku;
                $row->name = (string) $product->name;
                $row->product_id = $productId;
                $row->woo_product_id = $product->woo_product_id === null
                    ? null
                    : (int) $product->woo_product_id;
                $row->slug = (string) ($product->slug ?? '');
                $row->brand_id = $brandId;
                $row->brand_name = $brandName;
                $row->sell_price_pence = $sellPence;
                $row->buy_price_pence = $buyPence;
                $row->margin_pence = $marginPence;
                $row->lowest_comp_pence = $lowestCompPence;
                $row->beat_pct_bps = $beatPctBps;
                $row->stock = $stock;
                $row->best_supplier = $bestSupplier !== null ? (string) $bestSupplier : null;

                $rows[] = $row;
            }
        });

        // Most undercut (most-negative beat_pct_bps) first — operator-friendly
        // sort for ad-spend planning. Secondary tie-break: highest margin
        // first.
        usort(
            $rows,
            static fn (stdClass $a, stdClass $b): int => $a->beat_pct_bps <=> $b->beat_pct_bps
                ?: $b->margin_pence <=> $a->margin_pence,
        );

        return collect($rows);
    }

    /**
     * lowercase match-key (competitor sku AND mpn) → lowest CURRENT
     * competitor GROSS pennies within the 30-day window. "Current" = latest
     * row per (competitor, sku). Operator-facing (Google Ads buyers see
     * gross prices), so we use price_pennies_gross — distinct from
     * CompetitorPositionScanner which uses ex_vat for the cost/floor math.
     *
     * @return array<string, int>
     */
    private function lowestCompetitorGrossByKey(): array
    {
        $cutoff = now()->subDays(self::COMPETITOR_WINDOW_DAYS)->toDateTimeString();

        $rows = DB::select(
            'SELECT competitor_id, sku, mpn, price_pennies_gross FROM ('
            .'SELECT competitor_id, sku, mpn, price_pennies_gross, '
            .'ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC) AS rn '
            .'FROM competitor_prices WHERE recorded_at >= ? AND price_pennies_gross > 0'
            .') t WHERE t.rn = 1',
            [$cutoff],
        );

        /** @var array<string, int> $lowest */
        $lowest = [];
        foreach ($rows as $r) {
            $price = (int) $r->price_pennies_gross;
            if ($price <= 0) {
                continue;
            }
            foreach ([(string) $r->sku, (string) $r->mpn] as $raw) {
                $k = strtolower(trim($raw));
                if ($k === '') {
                    continue;
                }
                $lowest[$k] = isset($lowest[$k]) ? min($lowest[$k], $price) : $price;
            }
        }

        return $lowest;
    }

    /**
     * product_id → latest supplier offer snapshot (last 7 days) carrying
     * stock + supplier_name. Window function gives ONE row per product.
     *
     * @return array<int, array{stock:int, supplier_name:?string}>
     */
    private function latestSupplierByProductId(): array
    {
        $cutoffDate = today()->subDays(self::SUPPLIER_STOCK_WINDOW_DAYS)->toDateString();

        $rows = DB::select(
            'SELECT product_id, stock, supplier_name FROM ('
            .'SELECT product_id, stock, supplier_name, '
            .'ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY recorded_at DESC, stock DESC) AS rn '
            .'FROM supplier_offer_snapshots '
            .'WHERE recorded_at >= ? AND product_id IS NOT NULL'
            .') t WHERE t.rn = 1',
            [$cutoffDate],
        );

        /** @var array<int, array{stock:int, supplier_name:?string}> $out */
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            if ($pid <= 0) {
                continue;
            }
            $out[$pid] = [
                'stock' => (int) ($r->stock ?? 0),
                'supplier_name' => $r->supplier_name ?? null,
            ];
        }

        return $out;
    }

    /**
     * Build brand_id → name map ONCE per scan. TaxonomyResolver itself
     * caches the underlying Woo terms list 1h.
     *
     * @return array<int, string>
     */
    private function brandNameMap(): array
    {
        $out = [];
        foreach ($this->taxonomy->allBrands() as $term) {
            $id = (int) ($term['id'] ?? 0);
            $name = (string) ($term['name'] ?? '');
            if ($id > 0 && $name !== '') {
                $out[$id] = $name;
            }
        }

        return $out;
    }
}
