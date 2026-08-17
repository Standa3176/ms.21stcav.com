---
phase: quick-260809-uza
plan: 01
subsystem: sync
tags: [pricing, cost-authority, supplier-sync, import-issues, woo-import]
requires:
  - products.buy_price
  - import_issues table
provides:
  - "woo:import-products conditional buy_price authority (seed-only, preserve-on-update)"
  - "supplier:db-sync STALE_COST_NO_SUPPLIER ImportIssue surfacing"
  - "ImportIssue::STALE_COST_NO_SUPPLIER type + driver-guarded ENUM migration"
affects:
  - app/Domain/Sync/Commands/WooImportProductsCommand.php
  - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
  - app/Domain/Sync/Models/ImportIssue.php
  - app/Domain/Sync/Filament/Resources/ImportIssueResource.php
  - import_issues.issue_type (ENUM)
tech-stack:
  added: []
  patterns:
    - "Conditional updateOrCreate payload key to preserve a column on update"
    - "Driver-guarded ENUM extension (MariaDB MODIFY / SQLite CHECK rebuild)"
    - "Public method seam for testing perform()-loop logic without the mysqli remote pull"
key-files:
  created:
    - database/migrations/2026_08_09_120000_add_stale_cost_no_supplier_to_import_issues_issue_type.php
    - tests/Feature/Sync/WooImportProductsCommandCostAuthorityTest.php
    - tests/Feature/Domain/Sync/SupplierDbSyncStaleCostTest.php
  modified:
    - app/Domain/Sync/Commands/WooImportProductsCommand.php
    - app/Domain/Sync/Commands/SupplierDbSyncCommand.php
    - app/Domain/Sync/Models/ImportIssue.php
    - app/Domain/Sync/Filament/Resources/ImportIssueResource.php
decisions:
  - "Woo COG meta may only SEED buy_price (create / null); supplier feed stays the sole authoritative overwrite"
  - "Stale-cost surfacing runs by default (independent of --flag-obsolete) and is NOT gated on publish status — a stale cost is a data-quality fact regardless of storefront visibility"
  - "Extracted a public surfaceStaleCostIssue() seam so the Product-loop decision is unit-testable without mocking the mysqli remote pull (mirrors buildBestOfferMap / isObsoleteCandidate)"
metrics:
  tasks: 2
  files_created: 3
  files_modified: 4
  tests_added: 17
  completed: 2026-08-17
---

# Quick Task 260809-uza: Supplier Cost Feedback Loop Summary

Broke the circular cost-authority loop (`woo:import-products` no longer overwrites an existing non-null `buy_price` from Woo COG meta — it only SEEDS on create/null, and `--with-supplier` remains the sole authoritative overwrite) and surfaced silent stale costs (`supplier:db-sync` now writes an idempotent `STALE_COST_NO_SUPPLIER` ImportIssue for a previously-costed product with no fresh in-stock supplier offer, without ever changing the cost), traced from the SKU 9C941AA pricing bug.

## What Was Built

### Task 1 — woo:import-products cost authority (commit `95c9fea`)
- Removed the unconditional `'buy_price' => parseDecimal($cogCost)` from the base `$payload`.
- Resolve the existing row once (`Product::where('woo_product_id', $wooProductId)->first(['id','buy_price'])`), reused for both the dry-run existence check and the live write.
- buy_price precedence: (1) `--with-supplier` valid price → authoritative on create AND update; (2) new row OR existing `buy_price IS NULL` → seed from COG only when COG is non-null; (3) otherwise omit the key so `updateOrCreate` leaves the cost untouched.
- `withoutEvents` echo-loop guard (260611-s2d) preserved; created/updated counters unchanged.
- New `WooImportProductsCommandCostAuthorityTest.php` (6 cases) covers preserve/seed-on-create/seed-on-null/supplier-override/null-COG/dry-run.

### Task 2 — supplier:db-sync stale-cost surfacing (commit `c7f7e76`)
- `ImportIssue::STALE_COST_NO_SUPPLIER` constant + docblock.
- Driver-guarded migration extends the `import_issues.issue_type` ENUM (MariaDB `MODIFY COLUMN`; SQLite drops the single-column `import_issues_issue_type_index`, rebuilds the column with the new value, restores the index; other drivers no-op).
- Extracted `hasAutoUpdateCarveOut(Product)` (shared by `isObsoleteCandidate` + the stale-cost path); `isObsoleteCandidate` external behaviour unchanged (still keeps its publish-status gate).
- New public `surfaceStaleCostIssue(Product, key, correlationId, dryRun): 'flagged'|'would-flag'|'skipped'` seam, wired into the unmatched branch of `perform()` AFTER `$unmatched++`, INDEPENDENT of `--flag-obsolete`, running in the default scheduled path. One `correlation_id` per run (`Str::uuid()`). Idempotent `updateOrCreate` on the unresolved tuple; cost is never mutated.
- New `stale_cost` / `would_flag_stale_cost` counters in the summary line.
- `ImportIssueResource`: form Select, table badge (`danger`), and SelectFilter arms for the new type.
- New `SupplierDbSyncStaleCostTest.php` (8 cases) covers flag/carve-outs (×3)/null-cost/empty-key/idempotency/dry-run, and the SQLite insertability of the new ENUM value (proving the migration).

## Deviations from Plan

None — plan executed as written. Pint applied cosmetic style fixes (unary spacing, blank-line-before-statement, class attribute separation, fully-qualified-strict-types) to the touched files; no behavioural changes.

## Testing

- `WooImportProductsCommandCostAuthorityTest` + `WooImportProductsCommandTest`: 14 passed (51 assertions).
- `SupplierDbSyncStaleCostTest` + `SupplierDbSyncStaleSupplierTest` + `SupplierDbSyncExclusionTest` + `SupplierDbSyncCommandTest`: 22 passed (84 assertions).
- Full regression: `tests/Feature/Sync tests/Feature/Domain/Sync` — 61 passed (224 assertions).

## Notes / Out of Scope

- `buildBestOfferMap` cheapest-in-stock selection untouched (pending operator confirmation — follow-up if buggy).
- Nightly `cutover:auto-sync --field=...,buy_price` push unchanged (now pushes the supplier-authoritative cost, which is correct).
- Local commits only — not pushed, not deployed.
- Pre-existing unrelated working-tree changes (`storage/app/research/supplier-probe.json` deletion, `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php` modification) left exactly as found — never staged.

## Commits

- `95c9fea` — fix(quick-260809-uza-01): woo:import-products seeds buy_price only, never overwrites
- `c7f7e76` — feat(quick-260809-uza-02): surface STALE_COST_NO_SUPPLIER import issue in supplier:db-sync

## Self-Check: PASSED

- All created/modified files verified present on disk.
- Both commits (`95c9fea`, `c7f7e76`) verified in git log.
- No unexpected file deletions in either commit.
- Pre-existing unrelated working-tree changes left untouched (never staged).
