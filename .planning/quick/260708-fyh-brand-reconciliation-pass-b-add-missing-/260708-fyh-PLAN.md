---
phase: 260708-fyh-brand-reconciliation-pass-b-add-missing-
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Products/Services/ProductGapReport.php
  - app/Filament/Pages/CatalogueGapsPage.php
  - tests/Feature/Products/ProductGapReportTest.php
  - tests/Feature/Products/CatalogueGapsPageTest.php
must_haves:
  truths:
    - "The Woo Maintenance dashboard now shows all FOUR reconciled gaps — missing_brand joins images/EAN/category. missing_brand = reconciled live products (woo_reconciled_at NOT NULL) with woo_brand_count = 0 (no product_brand on Woo). Prod-verified real count: 391."
    - "ProductGapReport::GAPS gains 'missing_brand' => 'Missing brand'; apply('missing_brand') = whereNotNull('woo_reconciled_at')->where('woo_brand_count', 0); counts() adds a missing_brand SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_brand_count = 0 THEN 1 ELSE 0 END) to the single cached aggregate."
    - "The Overview widget renders the 4th gap stat (via the GAPS loop — no widget change needed if it iterates GAPS) linking to Catalogue Gaps pre-filtered to missing_brand; the Catalogue Gaps Gap filter now offers 4 options and shows a woo_brand_count column ('Brand', danger when 0)."
    - "Cheap plain-column query (no JSON scan). Fix actions on Catalogue Gaps unchanged (Resync re-pushes the product_brand link when a local brand_id exists; products with no local brand_id need manual assignment). Reconciliation command/columns unchanged."
  artifacts:
    - path: "app/Domain/Products/Services/ProductGapReport.php"
      provides: "missing_brand gap (GAPS + apply + counts)"
      contains: "missing_brand"
    - path: "app/Filament/Pages/CatalogueGapsPage.php"
      provides: "woo_brand_count column (Brand)"
      contains: "woo_brand_count"
    - path: "tests/Feature/Products/ProductGapReportTest.php"
      provides: "missing_brand count + apply over reconciled woo_brand_count"
      contains: "woo_brand_count"
  key_links:
    - from: "ProductGapReport missing_brand"
      to: "reconciled woo_brand_count = 0"
      via: "whereNotNull(woo_reconciled_at)->where(woo_brand_count,0)"
      pattern: "woo_brand_count"
---

<objective>
Complete the reconciled Woo Maintenance gap set: add missing_brand (woo_brand_count = 0 over reconciled
live products — prod-verified 391) to ProductGapReport + the Overview + Catalogue Gaps, so the dashboard
shows all four real, Woo-backed gaps (images / EAN / category / brand).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260708-fyh-brand-reconciliation-pass-b-add-missing-/
@CLAUDE.md
@app/Domain/Products/Services/ProductGapReport.php
@app/Filament/Widgets/WooMaintenanceGapsWidget.php
@app/Filament/Pages/CatalogueGapsPage.php
@tests/Feature/Products/ProductGapReportTest.php
@tests/Feature/Products/CatalogueGapsPageTest.php
---
woo_brand_count is populated by products:reconcile-woo-maintenance (pass A, prod-verified: 4,612 reconciled,
391 with woo_brand_count = 0). The dashboard already reads reconciled woo_* fields (260708-cey).
</context>

<interfaces>
=== ProductGapReport ===
- Add to GAPS (after missing_category): `'missing_brand' => 'Missing brand',`.
- apply(): add case `'missing_brand' => $query->where('woo_brand_count', 0),` (the shared
  `$query->whereNotNull('woo_reconciled_at')` at the top of apply() already gates it — confirm apply() gates
  ALL gaps on woo_reconciled_at; if it does per-case, add the whereNotNull to this case too).
- counts(): add to the selectRaw aggregate:
  `.', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_brand_count = 0 THEN 1 ELSE 0 END) as missing_brand'`
  and add `'missing_brand' => (int) ($row->missing_brand ?? 0),` to the returned gaps array.

=== WooMaintenanceGapsWidget ===
If getStats() iterates `ProductGapReport::GAPS` to build the gap stats, NO change is needed (the new key
renders automatically). If gaps are hardcoded, add the missing_brand Stat mirroring the others
(->url(CatalogueGapsPage deep-link, ?tableFilters[gap][value]=missing_brand)). Verify which and act accordingly.

=== CatalogueGapsPage ===
- The Gap SelectFilter ->options(ProductGapReport::GAPS) now shows 4 (automatic if it reads GAPS).
- Add a `woo_brand_count` column (label 'Brand', badge, danger when 0, placeholder handling) alongside the
  existing woo_image_count / woo_gtin / woo_category_count columns. Fix actions unchanged.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: add missing_brand gap to report + dashboard</name>
  <files>
    app/Domain/Products/Services/ProductGapReport.php,
    app/Filament/Pages/CatalogueGapsPage.php,
    tests/Feature/Products/ProductGapReportTest.php,
    tests/Feature/Products/CatalogueGapsPageTest.php
  </files>
  <behavior>
    Add missing_brand per <interfaces> (GAPS + apply + counts + the widget/column if not GAPS-driven).
    Extend ProductGapReportTest: add a reconciled product with woo_brand_count = 0 → assert
    counts()['gaps']['missing_brand'] == 1 and apply(liveBase(),'missing_brand') returns it; a reconciled
    product with woo_brand_count >= 1 is NOT counted; an UNRECONCILED product (woo_reconciled_at null) is NOT
    counted. Update the existing seed rows to set woo_brand_count where relevant so the other gap counts stay
    correct. Extend CatalogueGapsPageTest: filterTable('gap','missing_brand') shows the woo_brand_count=0
    product only. (Cache::flush() at test start.)
    Keep all existing gap assertions (images/EAN/category) green.
  </behavior>
  <action>
    Add the gap to ProductGapReport (GAPS/apply/counts); add the woo_brand_count column to CatalogueGapsPage;
    check the widget renders the 4th stat (GAPS-driven or add it). Extend both tests. Run them + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/ProductGapReportTest.php tests/Feature/Products/CatalogueGapsPageTest.php 2>&1 | tail -20</automated>
    Expected: GREEN — missing_brand counted from reconciled woo_brand_count=0; filter narrows to it; other gaps unchanged.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Products/Services/ProductGapReport.php app/Filament/Pages/CatalogueGapsPage.php app/Filament/Widgets/WooMaintenanceGapsWidget.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - missing_brand (reconciled woo_brand_count=0) is in GAPS/apply/counts, the Overview shows its stat, Catalogue Gaps filters + shows a Brand column; existing gaps unchanged; cheap query; tests green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Products/ProductGapReportTest.php tests/Feature/Products/CatalogueGapsPageTest.php` → GREEN
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Maintenance Overview now shows all four reconciled gaps: ~180 images / ~628 EAN / 0 category / ~391 brand
  (over 4,604 reconciled live). The Missing brand card drills into Catalogue Gaps (gap=missing_brand).
- Fixing brand: products with a local brand_id → Resync re-pushes the product_brand link; products with no
  local brand_id need a brand assigned first (Suggestions/Brands-to-Add or manual). No blind one-click.
- This completes the Woo Maintenance feature: all four gap types (images/EAN/category/brand) backed by the
  nightly Woo+WP reconciliation (products:reconcile-woo-maintenance).
</verification>

<success_criteria>
- The Woo Maintenance dashboard reports all four reconciled gaps incl. missing_brand (woo_brand_count=0 over reconciled), with a Catalogue Gaps filter + Brand column; existing gaps unchanged; queries stay cheap; both tests green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260708-fyh-brand-reconciliation-pass-b-add-missing-/260708-fyh-SUMMARY.md` documenting
the missing_brand gap (reconciled woo_brand_count=0), the GAPS/apply/counts + Catalogue Gaps column additions,
the tests, and that the Woo Maintenance feature is now complete across all four reconciled gap types.
</output>
