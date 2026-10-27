---
phase: quick-261027-duz
plan: 01
subsystem: sync / supplier cost
tags: [supplier-db-sync, buy_price, sku-collision, outlier-guard, filament, cli]
requires: []
provides:
  - product_supplier_blocks table + ProductSupplierBlock model
  - ProductSupplierBlockResolver (singleton)
  - SupplierCostOutlierGuard::detect (pure)
  - ImportIssue::SUPPLIER_COST_OUTLIER
  - supplier:block-offer command
  - ProductSupplierBlockResource (Filament, Sync & CRM)
affects:
  - supplier:db-sync (buildBestOfferMap + product loop)
  - products:hydrate-stock-from-offers
  - supplier:explain-cost
  - ImportIssueResource
tech-stack:
  added: []
  patterns: [per-request cached resolver singleton, pure static guard, driver-guarded enum migration]
key-files:
  created:
    - database/migrations/2026_10_27_100000_create_product_supplier_blocks_table.php
    - database/migrations/2026_10_27_100100_add_supplier_cost_outlier_to_import_issues_issue_type.php
    - app/Domain/Sync/Models/ProductSupplierBlock.php
    - app/Domain/Sync/Services/ProductSupplierBlockResolver.php
    - app/Domain/Sync/Services/SupplierCostOutlierGuard.php
    - app/Domain/Sync/Commands/BlockSupplierOfferCommand.php
    - app/Domain/Sync/Filament/Resources/ProductSupplierBlockResource.php
    - app/Domain/Sync/Filament/Resources/ProductSupplierBlockResource/Pages/ManageProductSupplierBlocks.php
    - tests/Unit/Domain/Sync/Services/SupplierCostOutlierGuardTest.php
    - tests/Unit/Domain/Sync/Services/ProductSupplierBlockResolverTest.php
    - tests/Feature/Domain/Sync/SupplierDbSyncOfferBlockTest.php
    - tests/Feature/Domain/Sync/SupplierDbSyncCostOutlierTest.php
    - tests/Feature/Domain/Sync/BlockSupplierOfferCommandTest.php
    - tests/Feature/Filament/Resources/ProductSupplierBlockResourceTest.php
  modified:
    - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
    - app/Console/Commands/HydrateProductStockFromOffersCommand.php
    - app/Domain/Sync/Commands/ExplainSupplierCostCommand.php
    - app/Domain/Sync/Models/ImportIssue.php
    - app/Domain/Sync/Filament/Resources/ImportIssueResource.php
    - app/Providers/AppServiceProvider.php
    - config/supplier.php
    - tests/Feature/Console/HydrateProductStockFromOffersCommandTest.php
decisions:
  - "Outlier guard compares like with like: the pool is in-stock suppliers when anything is in stock, all suppliers only on the nothing-in-stock fallback (keeps the pinned MUYHSMFFADW case — Ingram 42p in stock vs Westcoast £4.78 OOS — from being flagged)"
  - "Guard uses the LOWER median of the other suppliers and strict less-than, default ratio 3, config-only (no env)"
  - "Held outlier keeps existing buy_price (null stays null); outlier supplier's stock is not counted; hold applies even to auto-update carve-out products"
  - "Blocks are per MATCH KEY (normalised lowercase+trim) and per supplier_id; they apply before the guard"
metrics:
  duration: ~85 min
  completed: 2026-10-27
  tasks: 3
  files: 23
---

# Quick 261027-duz: Block wrong supplier offers per product Summary

Per-(sku, supplier) offer blocks plus an automatic lower-median cost outlier guard, both applied at the cost source in `supplier:db-sync` AND the second buy_price writer `products:hydrate-stock-from-offers`, so a supplier SKU collision (Midwich 1301222 at £7.20 vs the real £88.20 Yealink T74U) can no longer set a product's cost.

## What was built

**Task 1 — foundations** (`39b3770` RED, `105dea6` GREEN)
- `product_supplier_blocks` (sku 191 normalised match key, nullable product_id display FK, supplier_id 16, reason NOT NULL, created_by_user_id, timestamps). Names: `psb_sku_supplier_unique`, `psb_product_id_index`, `psb_product_id_fk`, `psb_created_by_fk`.
- `ProductSupplierBlock` model: `saving` hook lowercases/trims sku, trims supplier_id, resolves product_id from the SKU.
- `ProductSupplierBlockResolver` singleton: `blocksFor`, `isBlocked`, `forget` (one query per run).
- `SupplierCostOutlierGuard::detect($pricesBySupplier, $chosenSid, $ratio)`: pure; drops non-positive prices; inert for ratio <= 1, chosen missing, no others; fires when chosen < lower-median(others) / ratio.
- Enum migration adds `supplier_cost_outlier` (MySQL MODIFY with all six values / SQLite column rebuild); `ImportIssue::SUPPLIER_COST_OUTLIER`.
- `config('supplier.cost_outlier_guard')` = `['enabled' => true, 'ratio' => 3]` (literals).

**Task 2 — both buy_price writers** (`7d7578c` RED, `da263da` GREEN)
- `buildBestOfferMap`: blocked (key, supplier) offers are skipped per key (`$this->blocks->isBlocked`) — price and stock — while the same row still counts under its other key. Per-supplier pool is reduced and `SupplierCostOutlierGuard::detect` is run; each entry now carries `cost_outlier` (null, or evidence plus `chosen_supplier` / `other_suppliers` names). When it fires, the outlier supplier's stock is taken out; `buy` stays the raw cheapest so logs can see it.
- New public `resolveBuyForProduct()`: holds the existing buy_price for an outlier row and writes or refreshes one unresolved `supplier_cost_outlier` ImportIssue. The notes give the SKU, supplier name and id, both prices, the held value, a "block with supplier:block-offer" hint and an `evidence=` JSON blob, plus an "already contaminated" sentence when the stored cost equals the outlier price. Dry-run writes nothing. The product loop uses it, logs `supplier_db_sync.cost_outlier_held`, and adds `cost_outlier_held=N` (or `would_hold_cost_outlier=N` in dry-run) to the summary line.
- `products:hydrate-stock-from-offers`: offer query `whereNotIn` the blocked supplier_ids. The guard runs over fresh, in-stock, non-blocked prices at the key's latest `recorded_at`. If it fires, buy_price is left untouched and stock comes from the cheapest in-stock non-outlier supplier. Adds a `cost_outlier_held` counter row and a `hydrate_stock.cost_outlier_held` info log.
- `ImportIssueResource`: label "Supplier cost outlier (held)", colour danger, filter option.

**Task 3 — operator surfaces** (`9fd218d` RED, `5d334e4` GREEN)
- `supplier:block-offer {sku} {supplier} --reason= | --remove`. Supplier is matched first by exact supplier_id, then by case-insensitive name, and otherwise stored raw with a warning. Adding is an idempotent upsert, `--reason` is required when adding, and removing a missing block warns and exits 0.
- `supplier:explain-cost`: marks blocked offers `BLOCKED` in the EXCL column (width 8) plus `— BLOCKED (reason)`, leaves them out of the cheapest selection, prints an `OUTLIER GUARD:` warning when db-sync would hold the cost, and lists the blocks for that key.
- Filament `ProductSupplierBlockResource` (Sync & CRM, sort 26, `heroicon-o-no-symbol`, "Supplier offer blocks"): one ManageRecords page with a modal create and a row delete. Only admin and pricing_manager can create or delete. sales and read_only can view the page but not change it. `created_by_user_id` is set to the logged-in user. A duplicate pair gets a friendly validation error instead of a 500.

## Test results (real counts, sqlite, `php84/php.exe vendor/bin/pest`)

| Suite | Result |
|---|---|
| Task 1 verify: 2 new unit files + `tests/Architecture` | 148 passed (604 assertions) |
| Task 2 verify: `tests/Feature/Domain/Sync tests/Feature/Sync HydrateProductStockFromOffersCommandTest tests/Unit/Domain/Sync` | 163 passed (524 assertions) |
| Task 3 verify: BlockSupplierOfferCommandTest + ProductSupplierBlockResourceTest + SupplierResourceTest | 24 passed (113 assertions) |
| `php -l ExplainSupplierCostCommand.php` | no syntax errors |
| `artisan list supplier \| grep -c block-offer` | 1 |
| `tests/Architecture` final re-run (EnvUsageTest, all Deptrac*LayerTest) | 132 passed (567 assertions) |
| Full suite, run in 5 chunks | Unit 801 passed / 46 failed; Pricing+ProductAutoCreate 689 passed; Console+Agents+Competitor 673 passed / 1 failed; CRM+Filament+Products+Dashboard+TradePricing 648 passed / 1 failed; Integrations+Domain+Cutover+Sync+Suggestions+Alerting+Webhooks+Security+root 675 passed / 9 failed / 4 skipped |

The 57 full-suite failures were already failing on `main` before this work. I checked by running the same files on `main` and got identical counts:
- Unit: 46. `GoldenFixtureV2TradeTest`, `TradeRuleResolverPurityTest`, `Quotes/*` fail with "no such table customer_groups", and `ShoppingCandidateScannerTest` calls an undefined helper `seedShoppingCandidate()`.
- `Agents/Marketing/ReadMarketingToolsTest` read_margin_opportunity: 1.
- `Dashboard/HomeDashboardPageTest` "renders 9 widget class names": 1.
- `Feature/Domain/Quotes/*`: 9.

These match the test-suite rot already listed under STATE.md "Known debt", and none of them touch files changed here.

Grep gates: `blocks->isBlocked` 1, `SupplierCostOutlierGuard::detect` in SupplierDbSyncCommand 1, `blocksFor` in hydrate 1. No new `env(` calls (the one match is a comment saying "no env()").

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 1 - Bug] Outlier guard pool must be like-for-like (in-stock vs in-stock)**
- **Found during:** Task 2 GREEN run.
- **Issue:** The plan's guard pool was every supplier's lowest price. That flagged the existing pinned test `SupplierDbSyncCommandTest` "MUYHSMFFADW case", where Ingram's 42p in-stock price is correct and Westcoast's £4.78 is an out-of-stock list price. The plan required pre-existing tests to stay green and unchanged.
- **Fix:** The guard now compares against the same pool the winner was picked from: in-stock suppliers when anything is in stock, and every supplier only when nothing is in stock. Hydrate's guard query is limited to `stock > 0` to match its in-stock offer pick, and explain-cost uses the same rule. The Yealink, Epson and Poly incident shapes still fire because the real supplier has stock.
- **Trade-off (operator awareness):** a collision where the genuine supplier is out of stock and the wrong one is in stock will not be caught automatically. Use `supplier:block-offer` for those.
- **Tests added:** an "MUYHSMFFADW like-for-like" non-fire case, and a "nothing-in-stock fallback still fires" case.
- **Files:** SupplierDbSyncCommand.php, HydrateProductStockFromOffersCommand.php, ExplainSupplierCostCommand.php. **Commit:** `da263da` (explain-cost in `5d334e4`).

**2. [Rule 2 - Missing critical] Filament duplicate-pair validation**
- Creating a block for a (sku, supplier) pair that already existed would hit `psb_sku_supplier_unique` and return a 500. Added a normalised duplicate check on the sku field, with a test. **Commit:** `5d334e4`.

**3. [Rule 2] Block cache reset per run**
- `supplier:db-sync` and hydrate call `forget()` on the block resolver at the start of `perform()`, so a long-lived process always reads the current blocks.

### Minor
- Supplier keys: buildBestOfferMap groups suppliers by `supplierid`, and falls back to `supplier_name` when the id is empty, as the plan specified.
- Pint made cosmetic changes (import ordering, FQCN to `use`) to the touched test file and SupplierDbSyncCommand. No behaviour changed.

## Known Stubs

None.

## Threat Flags

None. The new write surface (Filament create/delete) is the one listed in the plan's threat model as T-261027-02 and is gated and tested.

## Deferred Issues

- 57 full-suite failures on `main` (see the test results table). Out of scope and not touched.

## Post-deploy runbook (VPS, as the app user, after deploy)

> Get the exact Midwich and Tech Data `supplier_id` values from `php artisan supplier:explain-cost 1301222` or the Suppliers page. The CLI also accepts the supplier **name** (case-insensitive), e.g. `Midwich`, and prints the id it resolved. Read that line to confirm it picked the right supplier. If it says "not a known supplier", stop and use the numeric id.
>
> Blocks are per **match key**. If a product matches through an alternative-SKU alias (`product_supplier_skus`), block the alias key as well.

1. `php artisan migrate --force` (creates `product_supplier_blocks` and extends the `import_issues.issue_type` enum)
2. `php artisan supplier:block-offer 1301222 <Midwich id> --reason="Midwich 1301222 is an unrelated £7.20 item; Nimans 1301222 = Yealink T74U (incident 2026-10-27)"`
3. `php artisan supplier:block-offer cp05osswh815 <Midwich id> --reason="Midwich £50/£58 vs others £189–£225 — wrong item"`
4. Same for the remaining Midwich keys:
   - `php artisan supplier:block-offer cp05osswh740 <Midwich id> --reason="Midwich price far below other suppliers — wrong item"`
   - `php artisan supplier:block-offer cp05osswh988 <Midwich id> --reason="Midwich price far below other suppliers — wrong item"`
   - `php artisan supplier:block-offer cp05rtbsha29 <Midwich id> --reason="Midwich price far below other suppliers — wrong item"`
5. `php artisan supplier:block-offer "83z49aa#abu" <Tech Data id> --reason="Tech Data £564.82 vs Ingram £3,941.83 — pending confirmation"` (quote the key because of `#`)
6. Run the explain-cost checks. Each should show the blocked supplier as `BLOCKED (reason)` and a different supplier chosen:
   - `php artisan supplier:explain-cost 1301222` — expect Midwich BLOCKED and Nimans chosen at £88.20
   - `php artisan supplier:explain-cost cp05osswh815`, `cp05osswh740`, `cp05osswh988`, `cp05rtbsha29`, `"83z49aa#abu"`
7. Re-run the sync: `php artisan supplier:db-sync --dry-run` (check `would_hold_cost_outlier=` in the summary), then `php artisan supplier:db-sync`
8. Verify product **5969** `buy_price = 88.20`: `php artisan supplier:explain-cost 1301222` (the LOCAL line), or in tinker `App\Domain\Products\Models\Product::find(5969)->buy_price`
9. Let the 08:00 `pricing:undercut-competitors` run, or trigger it, and confirm the T74U sell price recovers from £10.80 inc VAT.
10. After the next 07:00 run, review new **Supplier cost outlier (held)** ImportIssues in Filament (Import Issues, filter by type). For each one, either block the bad supplier (`supplier:block-offer`), which lets the next sync adopt the right cost, or, if the cheap price is genuine, mark the issue resolved and accept that the guard will keep holding. The way out of that case is to block the expensive supplier or raise `supplier.cost_outlier_guard.ratio`.

**Kill switch:** set `supplier.cost_outlier_guard.enabled` to `false` in `config/supplier.php`, then redeploy and run `config:cache`. This restores the old cheapest-wins behaviour exactly. Blocks are independent of this switch.

**Note:** if the stored buy_price is *already* the contaminated £7.20, the guard alone will hold £7.20 (the issue notes say "already contaminated"). Only the block fixes it, which is why step 2 must run before step 7.

## Self-Check: PASSED

- All 14 created files present on branch `quick/261027-duz-supplier-offer-block`.
- Commits present: 39b3770, 105dea6, 7d7578c, da263da, 9fd218d, 5d334e4.
