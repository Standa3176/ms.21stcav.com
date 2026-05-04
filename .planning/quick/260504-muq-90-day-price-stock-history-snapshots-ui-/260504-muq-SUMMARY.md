---
quick_id: 260504-muq
title: 90-day price + stock history snapshots with per-supplier breakdown
date: 2026-05-04
commit: 864a14e
tests_passed: 13
tests_assertions: 39
deptrac_violations: 0
---

# Quick task 260504-muq — Summary

## Commit

`864a14e` — feat(products): 90-day price + stock history snapshots with per-supplier breakdown

## Files

**Created (10):**

1. `database/migrations/2026_05_04_163008_create_history_snapshot_tables.php`
2. `app/Domain/Products/Models/ProductPriceSnapshot.php`
3. `app/Domain/Products/Models/SupplierOfferSnapshot.php`
4. `app/Domain/Products/Console/Commands/SnapshotsPruneCommand.php` (signature `history:prune`)
5. `app/Domain/Products/Filament/Pages/PriceHistoryPage.php`
6. `resources/views/filament/pages/price-history.blade.php`
7. `config/history.php`
8. `tests/Feature/Products/ProductPriceSnapshotTest.php`
9. `tests/Feature/Products/SupplierOfferSnapshotTest.php`
10. `tests/Feature/Products/SnapshotsPruneCommandTest.php`

**Modified (5):**

1. `app/Domain/Sync/Commands/WooImportProductsCommand.php` — adds ProductPriceSnapshot write-hook + `snapshots=` counter.
2. `app/Domain/Sync/Commands/SupplierDbSyncCommand.php` — adds ProductPriceSnapshot overwrite inside the per-product loop + new `syncSupplierOfferSnapshots()` private helper that queries `feeds_products LEFT JOIN feeds`.
3. `app/Providers/AppServiceProvider.php` — registers `SnapshotsPruneCommand` (artisan signature `history:prune`).
4. `app/Providers/Filament/AdminPanelProvider.php` — adds `discoverPages(Domain/Products/Filament/Pages)`.
5. `routes/console.php` — schedule entry for `history:prune` daily 04:00 Europe/London.

## Tests

13 passed / 39 assertions (sqlite in-memory).

- `ProductPriceSnapshotTest` — 4 tests (casts, BelongsTo, unique constraint, multi-day rows).
- `SupplierOfferSnapshotTest` — 5 tests (casts, nullable product_id, BelongsTo, unique constraint, multi-supplier same-day).
- `SnapshotsPruneCommandTest` — 4 tests (>90d prune, --days override, no-op, --days=0 safety guard).

Deptrac: **0 violations, 0 errors** (3,687 uncovered, 634 allowed — unchanged from baseline).

## Live verification

| Probe | Result |
|---|---|
| `php artisan migrate` | applied `2026_05_04_163008_create_history_snapshot_tables` (36ms) |
| `php artisan woo:import-products` | created=0, updated=5632, errored=0, **snapshots=5632** |
| `php artisan supplier:db-sync` (1st run, fp.supplierid bug) | matched=3939, updated=1868, **offer_snapshots=0** + 3 prepare warnings |
| `php artisan supplier:db-sync` (2nd run, after Rule 1 fix) | matched=3939, updated=0 (idempotent), **offer_snapshots=8017** |
| `ProductPriceSnapshot::count()` | **5,632** |
| `SupplierOfferSnapshot::count()` | **7,913** |
| `SupplierOfferSnapshot::distinct('supplier_name')->count()` | **9** distinct suppliers |
| `php artisan schedule:list \| grep history:prune` | `0 4 * * *  php artisan history:prune` registered, next due 11h |
| `curl /admin/price-history-page` | **HTTP 302** → /admin/login (auth gate; no 500) |
| `PriceHistoryPage::getViewData()` server-side smoke (productId=176) | product=B5NH6AA#ABU, snapshots=1, offers_today=4, cheapest_per_day=1 |

### Sample cheapest-supplier output

For SKU `939-002180`: **Tech Data at £458.14**.

For product 176 (4 suppliers today, sorted cheapest first):
- Ingram: £44.76 (stock 0)
- WestCoast: £45.89 (stock 64)
- Tech Data: £47.20 (stock 46)
- Nuvias: £47.65 (stock 6)

## Deviations from plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Command name collision: snapshots:prune already exists**

- **Found during:** Task 5 implementation (registering the prune command in AppServiceProvider).
- **Issue:** Phase 7 Plan 02 already owns `snapshots:prune` (PruneDashboardSnapshotsCommand for `dashboard_snapshots`). Registering a second command with the same signature would silently shadow one based on AppServiceProvider commands() registration order — operator runs `php artisan snapshots:prune` and it's a coin flip which command fires.
- **Fix:** Renamed signature to `history:prune` everywhere (command class still `SnapshotsPruneCommand`, but its signature is `history:prune {--days=}`). Schedule entry, AppServiceProvider registration, test invocations, route description and SUMMARY all consistent.
- **Files modified:** `SnapshotsPruneCommand.php` (signature), `routes/console.php` (schedule entry), `SnapshotsPruneCommandTest.php` (3 artisan invocations).
- **Commit:** 864a14e.

**2. [Rule 1 — Bug] Wrong supplier table referenced in syncSupplierOfferSnapshots**

- **Found during:** Task 7 first live run of supplier:db-sync. Output: `prepare failed on chunk 0: Unknown column 'fp.supplierid' in 'SELECT'`. offer_snapshots=0 across all 3 chunks.
- **Issue:** Initial implementation queried `supplier_products` (the deduped winner table used by the main sync loop, schema: id, suppliersku, title, manufacturer, mpn, stock, price, rrp, ean, ...). That table has no `supplierid` column; the per-supplier offer matrix lives in `feeds_products` (646,985 rows × 14 feeds).
- **Verification:** Probed remote with `SHOW COLUMNS FROM feeds_products` → confirmed `supplierid (varchar(5))` is present, plus `feeds.id (bigint)` for the human-readable name join.
- **Fix:** Changed `FROM supplier_products` to `FROM feeds_products` in the helper's SQL. Re-ran supplier:db-sync → 8,017 offer snapshots written across 9 distinct suppliers.
- **Files modified:** `app/Domain/Sync/Commands/SupplierDbSyncCommand.php` (one-line table swap + clarifying comment).
- **Commit:** 864a14e.

### Auth gates

None. Both test/live runs ran with already-resolved IntegrationCredential rows.

## Operator next steps

1. Production deploy: this branch contains only additive changes (new tables + new commands + new Filament page). Existing daily 03:00 woo:import-products + 03:30 supplier:db-sync continue to work; they now also write snapshot rows. The new 04:00 history:prune slots into the existing retention cascade.
2. Smoke-test the Filament page at `/admin/price-history-page` after login. Pick any of the SKUs that appeared in today's offer pull (e.g. `939-002180`) to see all four chart sections populated.
3. Optional `.env` knob: `HISTORY_RETENTION_DAYS=90` (or shrink during disk-pressure windows; lengthen for ad-hoc trend studies). Default is 90.
4. Day-2 watchpoint: 90 days × ~5,633 product snapshots = ~507k rows; 90 days × ~8,000 offers = ~720k rows. Both well within MySQL/SQLite capability. Re-evaluate at >5M rows (Phase 7 sparkline-history split precedent).

## Self-Check

- [x] `database/migrations/2026_05_04_163008_create_history_snapshot_tables.php` — FOUND
- [x] `app/Domain/Products/Models/ProductPriceSnapshot.php` — FOUND
- [x] `app/Domain/Products/Models/SupplierOfferSnapshot.php` — FOUND
- [x] `app/Domain/Products/Console/Commands/SnapshotsPruneCommand.php` — FOUND
- [x] `app/Domain/Products/Filament/Pages/PriceHistoryPage.php` — FOUND
- [x] `resources/views/filament/pages/price-history.blade.php` — FOUND
- [x] `config/history.php` — FOUND
- [x] `tests/Feature/Products/{ProductPriceSnapshot,SupplierOfferSnapshot,SnapshotsPruneCommand}Test.php` — all FOUND
- [x] Commit `864a14e` — FOUND in `git log`

## Self-Check: PASSED
