<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Support\Facades\DB;

/**
 * Quick task 260722-shc — Google Shopping shortlist predicate (READ-ONLY).
 *
 * Answers: "which SKUs should we trial on Google Shopping first?" by ranking
 * the products that are simultaneously SALEABLE, PROFITABLE and
 * MERCHANT-ELIGIBLE, using competitor breadth as the best internal demand
 * proxy we have.
 *
 * ── Why this exists alongside AdCandidateScanner (and does NOT modify it) ──
 * AdCandidateScanner answers a DIFFERENT question — "where are we already
 * undercutting the market on a fat-margin SKU?" — and its output drives the
 * operator-facing Ad Candidates page + the Merchant-feed backfill. Its gates
 * (beatRequired, no competitor-count floor, no GTIN gate) are wrong for a
 * Shopping trial shortlist:
 *   - a Shopping listing is worth trialling even when we are currently ABOVE
 *     the cheapest competitor (price is a lever; presence is the question),
 *   - Google DISAPPROVES items without a GTIN (or brand+MPN), so `products.ean`
 *     is a hard eligibility gate here and irrelevant there,
 *   - "how many competitors list this" is the demand signal here and unused
 *     there.
 * Editing AdCandidateScanner to carry both shapes would fork its predicate and
 * risk the live page, so this scanner is built ALONGSIDE it and deliberately
 * MIRRORS its proven SQL patterns (windowed latest-per-(competitor,sku) pass;
 * windowed latest-supplier-offer pass with SupplierFreshnessResolver
 * stale-supplier exclusion; chunkById over products).
 *
 * ── Deptrac ──
 * Pricing must NOT depend on the Competitor domain. Competitor prices are
 * therefore read via a parameter-bound raw DB::select on `competitor_prices`
 * — never via Competitor models — exactly as AdCandidateScanner and
 * CompetitorPositionScanner already do. Brand names are resolved from the
 * product's OWN local `attributes_json` (no TaxonomyResolver, because that
 * hits the Woo REST API and this command is contractually no-Woo).
 *
 * ── Gates (funnel order; each drop is counted and reported) ──
 *   1. status = 'publish' AND type = 'simple'
 *   2. non-empty sku AND buy_price > 0 AND sell_price > 0
 *   3. margin (sell - buy) >= $minMarginPence          (default 19900 = £199)
 *   4. current fresh in-stock supplier offer            (stock > 0 within 7d,
 *      stale suppliers excluded via SupplierFreshnessResolver)
 *   5. distinct competitors with a CURRENT price >= $minCompetitors
 *      (default 2, within $competitorWindowDays = 30)
 *   6. has GTIN — products.ean non-empty — unless $allowMissingGtin
 *
 * ── Ranking ──
 *   score        = competitor_count × margin_pence   (demand proxy × value)
 *   margin       = margin_pence
 *   competitors  = competitor_count
 * every mode tie-breaks margin_pence DESC then sku ASC (stable + deterministic
 * across MySQL/SQLite because the ordering happens in PHP, not SQL).
 *
 * ── Performance ──
 * ONE windowed pass over competitor_prices, ONE windowed pass over
 * supplier_offer_snapshots, ONE chunked pass over products. No per-product
 * competitor or supplier query — the N+1 shape the Ad Candidates work already
 * ruled out.
 *
 * ── Read-only ──
 * Emits SELECT only. Never writes, never calls Woo/Google/any HTTP API.
 *
 * Not `final` so command-level tests can subclass with a stubbed scan().
 */
class ShoppingCandidateScanner
{
    public const DEFAULT_MIN_MARGIN_PENCE = 19900;

    public const DEFAULT_MIN_COMPETITORS = 2;

    public const DEFAULT_COMPETITOR_WINDOW_DAYS = 30;

    public const DEFAULT_LIMIT = 200;

    /** Mirrors AdCandidateScanner::SUPPLIER_STOCK_WINDOW_DAYS. */
    private const SUPPLIER_STOCK_WINDOW_DAYS = 7;

    /** @var array<int, string> */
    public const SORTS = ['score', 'margin', 'competitors'];

    public function __construct(
        private readonly SupplierFreshnessResolver $freshness,
        private readonly bool $excludeStaleSupplierStock = true,
    ) {}

    /**
     * @param  int  $minMarginPence  minimum (sell - buy) in pence
     * @param  int  $minCompetitors  minimum DISTINCT current competitors
     * @param  int  $competitorWindowDays  competitor-price recency window
     * @param  bool  $allowMissingGtin  keep (and flag) rows with no `ean`
     * @param  string  $sort  one of self::SORTS
     * @param  int  $limit  shortlist size (funnel still counts every eligible row)
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   funnel: array<string, int>,
     *   params: array<string, mixed>
     * }
     */
    public function scan(
        int $minMarginPence = self::DEFAULT_MIN_MARGIN_PENCE,
        int $minCompetitors = self::DEFAULT_MIN_COMPETITORS,
        int $competitorWindowDays = self::DEFAULT_COMPETITOR_WINDOW_DAYS,
        bool $allowMissingGtin = false,
        string $sort = 'score',
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'score';
        $competitorWindowDays = max(1, $competitorWindowDays);
        $limit = max(1, $limit);

        // Precompute the two lookup maps ONCE — the row loop stays O(N).
        $competitors = $this->currentCompetitorsByKey($competitorWindowDays);
        $supplierStock = $this->latestSupplierByProductId();

        $funnel = [
            'products_total' => Product::query()->count(),
            'dropped_not_publish_simple' => 0,
            'dropped_no_price_or_sku' => 0,
            'dropped_below_min_margin' => 0,
            'dropped_no_fresh_stock' => 0,
            'dropped_below_min_competitors' => 0,
            'dropped_missing_gtin' => 0,
            'eligible' => 0,
            'returned' => 0,
        ];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];
        $publishSimple = 0;

        Product::query()
            ->where('status', 'publish')
            ->where('type', 'simple')
            ->orderBy('id')
            ->chunkById(500, function ($products) use (
                &$rows,
                &$funnel,
                &$publishSimple,
                $competitors,
                $supplierStock,
                $minMarginPence,
                $minCompetitors,
                $allowMissingGtin,
            ): void {
                foreach ($products as $product) {
                    $publishSimple++;

                    // ── Gate 2: identity + prices ────────────────────────
                    $sku = trim((string) $product->sku);
                    $key = strtolower($sku);
                    $sellPence = (int) round(((float) $product->sell_price) * 100);
                    $buyPence = (int) round(((float) $product->buy_price) * 100);
                    if ($key === '' || $sellPence <= 0 || $buyPence <= 0) {
                        $funnel['dropped_no_price_or_sku']++;

                        continue;
                    }

                    // ── Gate 3: margin floor ────────────────────────────
                    $marginPence = $sellPence - $buyPence;
                    if ($marginPence < $minMarginPence) {
                        $funnel['dropped_below_min_margin']++;

                        continue;
                    }

                    // ── Gate 4: current fresh in-stock supplier offer ───
                    $supplier = $supplierStock[(int) $product->id] ?? null;
                    $stock = $supplier !== null ? (int) ($supplier['stock'] ?? 0) : 0;
                    if ($supplier === null || $stock <= 0) {
                        $funnel['dropped_no_fresh_stock']++;

                        continue;
                    }

                    // ── Gate 5: distinct current competitors ────────────
                    $competitorCount = isset($competitors[$key])
                        ? count($competitors[$key]['competitor_ids'])
                        : 0;
                    if ($competitorCount < $minCompetitors) {
                        $funnel['dropped_below_min_competitors']++;

                        continue;
                    }

                    // ── Gate 6: GTIN (Google Merchant hard requirement) ─
                    $ean = trim((string) ($product->ean ?? ''));
                    $hasGtin = $ean !== '';
                    if (! $hasGtin && ! $allowMissingGtin) {
                        $funnel['dropped_missing_gtin']++;

                        continue;
                    }

                    $lowestCompPence = $competitors[$key]['lowest_gross'] ?? 0;
                    $delta = $sellPence - $lowestCompPence;

                    $funnel['eligible']++;

                    $rows[] = [
                        'product_id' => (int) $product->id,
                        'woo_product_id' => $product->woo_product_id === null
                            ? null
                            : (int) $product->woo_product_id,
                        'sku' => $sku,
                        'name' => (string) $product->name,
                        'brand' => $this->brandNameFromAttributes($product->attributes_json),
                        'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
                        'ean' => $ean === '' ? null : $ean,
                        'has_gtin' => $hasGtin,
                        'buy_price_pence' => $buyPence,
                        'sell_price_pence' => $sellPence,
                        'margin_pence' => $marginPence,
                        // margin % OF THE SELL PRICE, in basis points.
                        'margin_pct_bps' => intdiv($marginPence * 10000, $sellPence),
                        'competitor_count' => $competitorCount,
                        'lowest_comp_pence' => $lowestCompPence,
                        'position' => $delta < 0 ? 'beat' : ($delta > 0 ? 'above' : 'level'),
                        'delta_vs_lowest_pence' => $delta,
                        'stock' => $stock,
                        'supplier_name' => $supplier['supplier_name'] ?? null,
                        // Demand proxy × value. See the class docblock: this is
                        // NOT search volume — validate in Keyword Planner.
                        'score' => $competitorCount * $marginPence,
                    ];
                }
            });

        $funnel['dropped_not_publish_simple'] = max(0, $funnel['products_total'] - $publishSimple);

        usort($rows, $this->comparator($sort));

        $rows = array_slice($rows, 0, $limit);
        $funnel['returned'] = count($rows);

        return [
            'rows' => $rows,
            'funnel' => $funnel,
            'params' => [
                'min_margin_pence' => $minMarginPence,
                'min_competitors' => $minCompetitors,
                'competitor_window_days' => $competitorWindowDays,
                'allow_missing_gtin' => $allowMissingGtin,
                'sort' => $sort,
                'limit' => $limit,
                'supplier_stock_window_days' => self::SUPPLIER_STOCK_WINDOW_DAYS,
            ],
        ];
    }

    /**
     * Ranking comparator. Every mode falls through to margin DESC then sku ASC
     * so the order is total and reproducible run-to-run (and driver-agnostic —
     * the sort happens in PHP, never in SQL).
     *
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function comparator(string $sort): callable
    {
        return static function (array $a, array $b) use ($sort): int {
            $primary = match ($sort) {
                'margin' => $b['margin_pence'] <=> $a['margin_pence'],
                'competitors' => $b['competitor_count'] <=> $a['competitor_count'],
                default => $b['score'] <=> $a['score'],
            };

            return $primary
                ?: ($b['margin_pence'] <=> $a['margin_pence'])
                ?: ($b['competitor_count'] <=> $a['competitor_count'])
                ?: strcmp((string) $a['sku'], (string) $b['sku']);
        };
    }

    /**
     * lowercase match-key (competitor sku AND mpn) → the set of DISTINCT
     * competitor ids currently listing it, plus the lowest CURRENT competitor
     * GROSS price in pence.
     *
     * "Current" = the latest row per (competitor_id, sku) inside the window —
     * the same windowed reduction AdCandidateScanner uses, so a competitor
     * with a year of daily rows counts ONCE, not 365 times.
     *
     * Gross (not ex-VAT) because Shopping shoppers and the Merchant feed both
     * see gross — mirrors AdCandidateScanner::lowestCompetitorGrossByKey.
     *
     * Read with a parameter-bound raw DB::select on `competitor_prices`
     * (never a Competitor model) to keep Pricing ↛ Competitor at deptrac 0.
     *
     * @return array<string, array{competitor_ids: array<int, true>, lowest_gross: int}>
     */
    private function currentCompetitorsByKey(int $windowDays): array
    {
        $cutoff = now()->subDays($windowDays)->toDateTimeString();

        $priceRows = DB::select(
            'SELECT competitor_id, sku, mpn, price_pennies_gross FROM ('
            .'SELECT competitor_id, sku, mpn, price_pennies_gross, '
            .'ROW_NUMBER() OVER (PARTITION BY competitor_id, sku ORDER BY recorded_at DESC) AS rn '
            .'FROM competitor_prices WHERE recorded_at >= ? AND price_pennies_gross > 0'
            .') t WHERE t.rn = 1',
            [$cutoff],
        );

        /** @var array<string, array{competitor_ids: array<int, true>, lowest_gross: int}> $out */
        $out = [];
        foreach ($priceRows as $r) {
            $price = (int) $r->price_pennies_gross;
            if ($price <= 0) {
                continue;
            }
            $competitorId = (int) $r->competitor_id;
            foreach ([(string) $r->sku, (string) ($r->mpn ?? '')] as $raw) {
                $k = strtolower(trim($raw));
                if ($k === '') {
                    continue;
                }
                if (! isset($out[$k])) {
                    $out[$k] = ['competitor_ids' => [], 'lowest_gross' => $price];
                }
                $out[$k]['competitor_ids'][$competitorId] = true;
                $out[$k]['lowest_gross'] = min($out[$k]['lowest_gross'], $price);
            }
        }

        return $out;
    }

    /**
     * product_id → latest supplier offer snapshot (inside the 7-day window)
     * carrying stock + supplier_name. ONE row per product via ROW_NUMBER().
     *
     * Intentionally MIRRORS AdCandidateScanner::latestSupplierByProductId —
     * that method is private and the 260722-shc guardrail forbids editing
     * AdCandidateScanner (it powers the live Ad Candidates page), so the
     * pattern is reproduced rather than extracted. If a third consumer ever
     * needs it, promote it to a shared Pricing service and update BOTH.
     *
     * Stale suppliers are dropped via SupplierFreshnessResolver (260608-g8x):
     * a supplier whose feed stopped updating must never assert "in stock".
     *
     * @return array<int, array{stock: int, supplier_name: ?string}>
     */
    private function latestSupplierByProductId(): array
    {
        $cutoffDate = today()->subDays(self::SUPPLIER_STOCK_WINDOW_DAYS)->toDateString();

        $freshIds = $this->excludeStaleSupplierStock
            ? $this->freshness->freshSupplierIds()->all()
            : null;

        // No fresh suppliers at all → nothing can be "currently in stock".
        // Also sidesteps the `IN ()` syntax error on MySQL.
        if ($freshIds !== null && $freshIds === []) {
            return [];
        }

        $sql = 'SELECT product_id, stock, supplier_name FROM ('
            .'SELECT product_id, stock, supplier_name, '
            .'ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY recorded_at DESC, stock DESC) AS rn '
            .'FROM supplier_offer_snapshots '
            .'WHERE recorded_at >= ? AND product_id IS NOT NULL';
        $bindings = [$cutoffDate];

        if ($freshIds !== null) {
            // Always parameter-bound — never interpolated.
            $placeholders = implode(',', array_fill(0, count($freshIds), '?'));
            $sql .= ' AND supplier_id IN ('.$placeholders.')';
            $bindings = array_merge($bindings, $freshIds);
        }

        $sql .= ') t WHERE t.rn = 1';

        /** @var array<int, array{stock: int, supplier_name: ?string}> $out */
        $out = [];
        foreach (DB::select($sql, $bindings) as $r) {
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
     * Brand name from the product's OWN local `attributes_json` spec rows
     * ([{name, value}, …]) — the same "Brand" row PublishProductJob writes to
     * the storefront spec table.
     *
     * Deliberately NOT TaxonomyResolver::allBrands(): that resolves brand ids
     * through the Woo REST API, and products:shopping-candidates is
     * contractually a no-Woo, no-network command. brand_id is exported
     * alongside so the operator can still join back to Woo terms manually.
     */
    private function brandNameFromAttributes(mixed $attributes): ?string
    {
        if (! is_array($attributes)) {
            return null;
        }

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $name = strtolower(trim((string) ($attribute['name'] ?? '')));
            if ($name !== 'brand') {
                continue;
            }
            $value = trim((string) ($attribute['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
