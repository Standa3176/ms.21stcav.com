<?php

declare(strict_types=1);

namespace App\Domain\Sync\Concerns;

/**
 * Shared SQL fragments for the supplier_db dual-file stock architecture.
 *
 * Extracted 2026-06-09 (quick task 260609-rie). The bug this trait prevents:
 *
 *   Ingram (feeds.id=10, is_stock_separate=1, path_to_stock_file=AVAIL/TOTUKHRL.ZIP)
 *   stores stock in a separate `stockseparate` table keyed by
 *   (supplier_id + suppliersku), NOT in feeds_products.stock. The probe on
 *   2026-06-09 showed feeds_products.stock=0 for ~99% of Ingram's 192,011 rows
 *   while stockseparate held 125,319 rows with real stock — meaning 123,901 SKUs
 *   were silently invisible to MS (zero stock → excluded from ads → revenue leak).
 *   Sample SKU CP15851 (Sennheiser HA310-2EP): fp.stock=0 vs ss.stock=5659.
 *
 *   WestCoast (feeds.id=39, is_stock_separate=0) — and every other supplier —
 *   keeps stock inline in feeds_products.stock. The JOIN gate
 *   `f.is_stock_separate = 1` ensures byte-identical output for them.
 *
 * Single source of truth — any new `FROM feeds_products` query that reads
 * `.stock` MUST use these helpers OR carry a
 * `// stock-separate-not-applicable: <reason>` annotation. The regression guard
 * in tests/Architecture/StockSeparateJoinTest.php enforces this.
 *
 * Current consumer sites (mirrors the pattern in App\Console\Concerns\NormalisesEan):
 *   - app/Domain/Sync/Commands/SupplierDbSyncCommand.php:139    (buildBestOfferMap source query)
 *   - app/Domain/Sync/Commands/SupplierDbSyncCommand.php:392    (syncSupplierOfferSnapshots source query)
 *   - app/Domain/Sync/Commands/ExplainSupplierCostCommand.php:72 (per-SKU diagnostic dump)
 *
 * Invariants the canonical SQL must satisfy:
 *   - LEFT JOIN feeds (not INNER) — defensive: missing feeds row degrades to fp.stock
 *     instead of dropping the row entirely.
 *   - stockseparate JOIN gated by `f.is_stock_separate = 1` IN THE ON CLAUSE —
 *     for is_stock_separate=0 suppliers the JOIN never matches, the CASE falls
 *     through to fp.stock, output is byte-identical to pre-fix.
 *   - COALESCE(..., 0) — for is_stock_separate=1 rows with no matching
 *     stockseparate row (Ingram catalogue lists the SKU but the separate file
 *     hasn't shipped that part yet), output is 0 not NULL.
 *   - Case-insensitive + trimmed SKU match — feeds_products.suppliersku has
 *     trailing whitespace in real prod data ("CP15851     ").
 *   - stockseparate.sku ↔ feeds_products.suppliersku (NOT mpn) — confirmed via
 *     the 2026-06-09 probe: stockseparate.sku='CP15851' matched fp.suppliersku.
 */
trait JoinsStockSeparate
{
    /**
     * Build the SELECT-list fragment that resolves stock from the dual-file
     * architecture. Drop-in replacement for the existing `fp.stock` token in
     * a SELECT list — emits the column as `stock` so downstream fetched-row
     * consumers (buildBestOfferMap, table renderers) keep reading $row['stock'].
     *
     * @param  string  $feedsProductsAlias  alias used for feeds_products (default fp)
     * @param  string  $stockSeparateAlias  alias used for stockseparate (default ss)
     * @param  string  $feedsAlias          alias used for feeds (default f)
     */
    protected function stockColumnSelect(
        string $feedsProductsAlias = 'fp',
        string $stockSeparateAlias = 'ss',
        string $feedsAlias = 'f',
    ): string {
        return sprintf(
            'COALESCE(CASE WHEN %s.is_stock_separate = 1 THEN %s.stock ELSE %s.stock END, 0) AS stock',
            $feedsAlias,
            $stockSeparateAlias,
            $feedsProductsAlias,
        );
    }

    /**
     * Build the JOIN-clause fragment. Includes BOTH the feeds JOIN (replacing
     * the existing `LEFT JOIN feeds f ON fp.supplierid = f.id` line in each
     * call site) and the new stockseparate JOIN. Order matters — the
     * stockseparate JOIN references `f.is_stock_separate`, so the feeds JOIN
     * MUST appear first.
     *
     * @param  string  $feedsProductsAlias  alias used for feeds_products (default fp)
     * @param  string  $stockSeparateAlias  alias used for stockseparate (default ss)
     * @param  string  $feedsAlias          alias used for feeds (default f)
     */
    protected function stockSeparateJoinClause(
        string $feedsProductsAlias = 'fp',
        string $stockSeparateAlias = 'ss',
        string $feedsAlias = 'f',
    ): string {
        return sprintf(
            'LEFT JOIN feeds %3$s ON %3$s.id = %1$s.supplierid'
            .' LEFT JOIN stockseparate %2$s'
            .' ON %3$s.is_stock_separate = 1'
            .' AND %2$s.supplier_id = %1$s.supplierid'
            .' AND LOWER(TRIM(%2$s.sku)) = LOWER(TRIM(%1$s.suppliersku))',
            $feedsProductsAlias,
            $stockSeparateAlias,
            $feedsAlias,
        );
    }
}
