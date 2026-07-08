---
phase: 260708-fyh-brand-reconciliation-pass-b-add-missing-
plan: 01
subsystem: Woo Maintenance
tags: [woo-maintenance, catalogue-gaps, brand, product-gap-report, filament]
requires:
  - woo_brand_count column + WP-REST brand pass (260708-dyy, Pass A)
  - reconciled woo_* mirror + dashboard rewire (260708-cey, Pass 2)
provides:
  - missing_brand gap (reconciled woo_brand_count = 0) in ProductGapReport
  - 4th Overview stat + Catalogue Gaps filter option + Brand column
affects:
  - app/Domain/Products/Services/ProductGapReport.php
  - app/Filament/Pages/CatalogueGapsPage.php
tech-stack:
  added: []
  patterns: [single-cached-aggregate, plain-column-predicate, GAPS-driven-ui]
key-files:
  created: []
  modified:
    - app/Domain/Products/Services/ProductGapReport.php
    - app/Filament/Pages/CatalogueGapsPage.php
    - tests/Feature/Products/ProductGapReportTest.php
    - tests/Feature/Products/CatalogueGapsPageTest.php
decisions:
  - "missing_brand predicate is where(woo_brand_count, 0) under the shared woo_reconciled_at gate at the top of apply() — no per-case gate needed (the gate is shared)."
  - "Overview widget needed NO change — getStats() iterates ProductGapReport::GAPS so the 4th stat renders automatically with its Catalogue Gaps deep-link."
metrics:
  duration: ~25min
  completed: 2026-07-08
---

# Phase 260708-fyh Plan 01: Brand Reconciliation Pass B — add missing_brand Summary

Added the 4th reconciled Woo Maintenance gap — `missing_brand` (reconciled live
products with `woo_brand_count = 0`, prod-verified 391) — to `ProductGapReport`,
the Maintenance Overview, and the Catalogue Gaps drill-down, completing the gap
set (images / EAN / category / brand) over the nightly Woo+WP reconciliation.

## What Changed

### ProductGapReport (`app/Domain/Products/Services/ProductGapReport.php`)
- `GAPS` gains `'missing_brand' => 'Missing brand'` (now 4 gaps).
- `apply()` gains the case `'missing_brand' => $query->where('woo_brand_count', 0)`.
  The existing shared `$query->whereNotNull('woo_reconciled_at')` at the top of
  `apply()` gates ALL gaps (confirmed shared, not per-case) — so unreconciled
  products are automatically excluded from missing_brand too.
- `counts()` adds `SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_brand_count = 0 THEN 1 ELSE 0 END) as missing_brand`
  to the single cached 300s aggregate, plus `'missing_brand'` to the returned
  gaps array. Cheap plain-column predicate — no JSON scan (keeps the 260708-akz
  hang fix).

### CatalogueGapsPage (`app/Filament/Pages/CatalogueGapsPage.php`)
- New `woo_brand_count` column (label 'Brand', badge, danger when 0, gray/`—`
  when null) alongside Images / EAN / Categories.
- The Gap `SelectFilter` reads `ProductGapReport::GAPS` so it now offers 4
  options automatically; `->query()` reuses `apply()`. Fix actions unchanged.

### WooMaintenanceGapsWidget — NO CHANGE
`getStats()` already iterates `ProductGapReport::GAPS`, so the 4th stat renders
automatically with its `?tableFilters[gap][value]=missing_brand` deep-link.

## Tests
- `ProductGapReportTest`: seed matrix extended to 6 (R4 = reconciled
  woo_brand_count=0, R5 = complete, R6 = unreconciled all-null); set
  `woo_brand_count => 2` on the base helper so the other gaps stay correct.
  Asserts `counts()['gaps']['missing_brand'] == 1`, `apply(missing_brand)`
  returns R4 only (branded R5 + unreconciled R6 excluded), coverage totals
  (total=6/reconciled=5/not_reconciled=1), and the images/EAN/category counts
  stay at 1 each.
- `CatalogueGapsPageTest`: added R4 (woo_brand_count=0) and a
  `filterTable('gap','missing_brand')` test — sees R4 only, not R1/R2/R3.
  Existing images/EAN/action/widget/access tests stay green.

## Verification
- `pest ProductGapReportTest CatalogueGapsPageTest` → **13 passed (56 assertions)**.
- `pint` on the 3 source files + 2 tests → `{"result":"pass"}`.
- Confirmed: missing_brand is counted from reconciled `woo_brand_count = 0`
  (unreconciled + branded excluded) and the Catalogue Gaps Gap filter offers it.

## Deviations from Plan
None — `<interfaces>` used as written. RED → GREEN, no refactor commit needed.
The apply() gate was confirmed shared (single `whereNotNull('woo_reconciled_at')`
at the top), so no per-case gate was added. The widget required no change.

## Operator Notes
- **Deploy:** push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration — code only; the `woo_brand_count` migration shipped with Pass A / 260708-dyy).
- The Maintenance Overview now shows all four reconciled gaps: ~180 images / ~628 EAN / 0 category / ~391 brand over ~4,604 reconciled live. The Missing brand card drills into Catalogue Gaps (gap=missing_brand).
- Fixing brand: products with a local `brand_id` → Resync re-pushes the product_brand link; products with no local brand_id need a brand assigned first (Suggestions / Brands-to-Add / manual). No blind one-click.
- This completes the Woo Maintenance feature: all four gap types backed by the nightly `products:reconcile-woo-maintenance`.

## TDD Gate Compliance
RED gate: `test(260708-fyh)` `edf8e1e` (3 failing missing_brand expectations).
GREEN gate: `feat(260708-fyh)` `0ac5ea8` (implementation → 13 passed).

## Self-Check: PASSED
- Files modified exist (ProductGapReport.php, CatalogueGapsPage.php, both tests).
- Commits exist: edf8e1e (test), 0ac5ea8 (feat).
