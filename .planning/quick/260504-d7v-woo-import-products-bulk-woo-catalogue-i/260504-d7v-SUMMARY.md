---
quick_id: 260504-d7v
description: woo:import-products bulk Woo catalogue import (+ optional supplier enrichment)
date: 2026-05-04
commit: 78d43ea
status: completed
---

# Quick Task 260504-d7v — Summary

## What changed

New artisan command `php artisan woo:import-products` that bulk-imports the WooCommerce catalogue into the local `products` table. Closes the bootstrap gap where the table couldn't be populated from an existing Woo store — the Phase 2 `sync:supplier` command updates existing rows but never creates them.

## Files (3 new, 1 modified — +339 / 0)

- `app/Domain/Sync/Commands/WooImportProductsCommand.php` (NEW, 188 lines)
- `app/Providers/AppServiceProvider.php` (MODIFIED, +6 lines — explicit registration alongside SyncSupplierCommand per Phase 2 precedent)
- `tests/Feature/Sync/WooImportProductsCommandTest.php` (NEW, 145 lines)

## Behaviour

```
woo:import-products
    {--with-supplier : Also enrich buy_price from the 21stcav.com supplier feed}
    {--limit=0 : Stop after N simple products (0 = no limit)}
    {--dry-run : Report what would change without writing}
```

- Pages `/wc/v3/products` at `per_page=100` via `WooClient::get()` (auto-resolves WooRest credentials)
- For each Woo product: `Product::updateOrCreate(['woo_product_id' => $id], $payload)` populated from Woo response
- Maps Woo → Product: `sku`, `name`, `type`, `status`, `stock_status`, `slug`, `short_description`, `long_description` (Woo `description`), `sell_price` (`regular_price` ?: `price`), `last_synced_at`
- With `--with-supplier`: also calls `SupplierClient::fetchAllProducts()` upfront, fills `buy_price` for SKUs that exist in both feeds
- Variations skipped with count (ProductVariant import has its own complexity; out of scope)
- Per-row `try/catch` writes to `import_issues` table on `QueryException` so one bad row doesn't abort the import
- Default DRY-RUN-style safety follows Phase 2 D-04 precedent — `--dry-run` reports counts without DB writes

## Tests

- `it('--dry-run reports planned changes without writing rows')` ✓
- `it('persists Woo products with sku/name/sell_price populated')` ✓
- `it('--limit caps the number of simple products imported')` ✓
- `it('skips type=variation rows')` ✓
- `it('--with-supplier fills buy_price for SKUs in the supplier feed')` ✓

5/5 passing, 21 assertions, 36s. Deptrac: 0 violations.

## Operator usage

After entering Woo + Supplier credentials at /admin → Integration Credentials, run:

```bash
# Smoke test — dry-run, only first 10 products
php artisan woo:import-products --dry-run --limit=10

# Real one-shot bootstrap import (gets all products + supplier buy_price)
php artisan woo:import-products --with-supplier

# Re-run anytime to refresh sell_price/stock_status from Woo + buy_price from supplier
php artisan woo:import-products --with-supplier
```

After the first live run:
1. Visit /admin → Catalogue → Products — should show all imported rows
2. Visit /admin → Catalogue → Competitor Prices — Match Status filter now shows real Matched (green) badges for SKUs in both Woo + competitor CSVs

## Out of scope (deferred)

- ProductVariant import (variations skipped with count summary)
- Wiring into Laravel scheduler — operator runs manually until Phase 7 cutover decides cadence
- Webhook-based incremental sync — future work
- Bidirectional sync (this is one-way Woo → local mirror; Phase 2 sync:supplier handles the local → Woo direction for buy_price-driven sell_price recomputes)

## Commit

`78d43ea` — feat(sync): woo:import-products command for bulk Woo catalogue import (+ optional supplier enrichment)
