---
phase: 260708-gab-woo-maintenance-bulk-fix-batch-size-safe
plan: 01
subsystem: woo-maintenance
tags: [filament, catalogue-gaps, safety-rail, suggestions, ux]
requires:
  - ProductGapReport::liveBase()/apply() (Catalogue Gaps drill-down, 260708-cey/fyh)
provides:
  - services.woo.maintenance_fix_batch_limit (config, default 25)
  - Catalogue Gaps BULK fix actions capped at the batch limit + cap notice
  - Suggestions 'Competitor count' column label (was 'Comp')
affects:
  - app/Filament/Pages/CatalogueGapsPage.php
  - config/services.php
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
tech-stack:
  added: []
  patterns:
    - "env()-only-in-config guardrail: batch limit read via config('services.woo.maintenance_fix_batch_limit')"
key-files:
  created:
    - .planning/quick/260708-gab-woo-maintenance-bulk-fix-batch-size-safe/260708-gab-SUMMARY.md
  modified:
    - config/services.php
    - app/Filament/Pages/CatalogueGapsPage.php
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php
    - tests/Feature/Products/CatalogueGapsPageTest.php
decisions:
  - "Cap read live inside the bulk action closure (not captured once) so config()->set in tests + env changes in prod take effect without a rebuild."
  - "Reused the existing resync_bulk action in the cap tests because Resync applies to every row (no per-row visibility gate), keeping the assertion on SKU count clean."
metrics:
  duration: ~15m
  completed: 2026-07-08
---

# Phase 260708-gab Plan 01: Woo Maintenance Bulk-Fix Batch-Size Safety Rail Summary

Cap the Catalogue Gaps BULK fix actions to a config batch limit (default 25) so an operator can't accidentally fire hundreds of money-costing Source-images / Backfill-EAN calls (or blow the ~30s web timeout) in one click, and rename the cryptic Suggestions 'Comp' column to 'Competitor count'.

## What Changed

### (A) Bulk fix batch cap
- **`config/services.php`** — added `'maintenance_fix_batch_limit' => (int) env('WOO_MAINTENANCE_FIX_BATCH_LIMIT', 25)` inside the `woo` array (env read stays in config per the EnvUsage guardrail).
- **`app/Filament/Pages/CatalogueGapsPage.php`** — `bulkFixAction()` now:
  - reads `config('services.woo.maintenance_fix_batch_limit', 25)` (floored at 1),
  - collects + filters the selected SKUs, records `$selected = count`, then `->take($limit)` before building the `--skus` CSV,
  - dispatches the same `Artisan::call($command, ['--skus' => $csv])` synchronously — just bounded,
  - appends `(capped at N of M selected — run again for the rest)` to the success notification when the selection exceeds the limit,
  - logs `dispatched` / `selected` / `limit` alongside the existing fields,
  - surfaces the cap in the confirmation `modalDescription`.
  - **Per-row `fixAction()` is unchanged** (single SKU — no cap).

### (C) Suggestions column rename
- **`app/Domain/Suggestions/Filament/Resources/SuggestionResource.php`** — the `supporting_competitors` count column label changed from `'Comp'` to `'Competitor count'`. Tooltip, badge, colour, state and sortable are untouched. The separate `competitors` (names) column is untouched — no label clash.

### Test
- **`tests/Feature/Products/CatalogueGapsPageTest.php`** — added a `seedFourMissingImageProducts()` helper (4 live reconciled products all carrying the `missing_images` gap so they survive the default filter's re-resolution) plus two cases:
  - limit set to 2, select 4 → `products:resync-to-woo` called once with exactly 2 SKUs (cap enforced),
  - limit set to 10, select 4 → called once with all 4 SKUs (no cap under the limit).

  Assertions use `Artisan::shouldReceive('call')->withArgs(...)` counting comma-separated SKUs, so they don't depend on selection order.

## Verification

```
pest tests/Feature/Products/CatalogueGapsPageTest.php   → Tests: 9 passed (50 assertions)
pest tests/Feature/Suggestions/SuggestionReadinessFilterTest.php → Tests: 4 passed (19 assertions)
pint --test config/services.php CatalogueGapsPage.php SuggestionResource.php CatalogueGapsPageTest.php → {"result":"pass"}
```

All pre-existing CatalogueGaps cases (filter narrowing, per-row source-images/resync actions, missing_brand, widget deep-links, admin gate) stayed green.

## Deviations from Plan

None functionally. The plan's example test seeded the mixed `seedCatalogueGapsMatrix()` (R1–R4, one gap each); the first run failed because Filament re-resolves a bulk selection against the active table query and the default `missing_images` filter only matched R1, so <2 SKUs reached the action. Fixed by seeding four products that all match the default filter (`seedFourMissingImageProducts()`). This is a test-fixture adjustment (Rule 3 — blocking issue), not a behaviour change to the cap logic.

## Driver Portability

No SQL/query changes. The cap is pure PHP (`Collection::take`) and a config int — identical on SQLite (tests) and MariaDB (prod).

## Operator Notes / Deploy

- **Deploy:** push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Catalogue Gaps bulk fixes now process at most `WOO_MAINTENANCE_FIX_BATCH_LIMIT` (default **25**) products per click. Select more and it processes the first 25 and tells you to run again. Tune via that env var. Guards against a giant Source-images / Backfill-EAN run (API cost + 30s timeout). Per-row fixes are unchanged.
- Suggestions **'Comp'** column now reads **'Competitor count'** (clearer; same tooltip/behaviour).

## Self-Check: PASSED
