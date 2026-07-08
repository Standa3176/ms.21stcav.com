---
phase: 260708-cey-woo-maintenance-reconciliation-pass-2-re
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Products/Services/ProductGapReport.php
  - app/Filament/Widgets/WooMaintenanceGapsWidget.php
  - app/Filament/Pages/CatalogueGapsPage.php
  - tests/Feature/Products/ProductGapReportTest.php
  - tests/Feature/Products/CatalogueGapsPageTest.php
must_haves:
  truths:
    - "The Woo Maintenance dashboard now reports TRUE whole-shop gaps from the reconciled woo_* fields (validated on prod: 180 missing images / 628 missing EAN / 0 missing category over 4,604 live), NOT the misleading local-mirror emptiness. ProductGapReport gaps are computed over RECONCILED live products only (woo_reconciled_at IS NOT NULL): missing_images = woo_image_count = 0; missing_ean = woo_gtin IS NULL; missing_category = woo_category_count = 0."
    - "counts() (single cached aggregate) additionally returns total (all live), reconciled, not_reconciled, and last_reconciled_at, so the Overview can show coverage + freshness and warn if reconciliation hasn't run. GAPS const = images/EAN/category (brand + stock dropped: brand isn't in the Woo /products reconciliation, and stock_status is always set on Woo so it's never a gap — documented)."
    - "The Overview widget shows: Live total, a 'Reconciled X / Y (last: <time>)' stat (warning if not_reconciled>0 or never reconciled), and the 3 gap stats (each linking to Catalogue Gaps pre-filtered). The Catalogue Gaps page's Gap filter = the 3 gaps (apply reads woo_* + woo_reconciled_at); columns surface woo_image_count / woo_gtin / woo_category_count / woo_reconciled_at."
    - "Still cheap (plain indexed columns; no JSON scan) — no regression of the hang fix. The fix actions (Source images / Backfill EAN / Resync) on Catalogue Gaps are unchanged. The reconciliation command + columns from pass 1 are unchanged."
  artifacts:
    - path: "app/Domain/Products/Services/ProductGapReport.php"
      provides: "gaps from reconciled woo_* fields + reconciled/last_reconciled_at in counts()"
      contains: "woo_reconciled_at"
    - path: "app/Filament/Widgets/WooMaintenanceGapsWidget.php"
      provides: "coverage + last-reconciled stat + 3 real gap stats"
      contains: "reconciled"
    - path: "tests/Feature/Products/ProductGapReportTest.php"
      provides: "counts/apply over seeded woo_* reconciled fields"
      contains: "woo_image_count"
  key_links:
    - from: "ProductGapReport gaps"
      to: "reconciled woo_image_count / woo_gtin / woo_category_count"
      via: "whereNotNull(woo_reconciled_at) + the woo_* predicate — true shop state"
      pattern: "woo_reconciled_at"
---

<objective>
Rewire the Woo Maintenance dashboard onto the reconciled woo_* fields so it shows TRUE whole-shop gaps
(prod-validated: 180 images / 628 EAN / 0 category over 4,604 live) instead of local-mirror emptiness, plus
reconciliation coverage/freshness. Cheap plain-column queries (keeps the hang fix). Brand + stock dropped
from the gap set (not reconciled / always-present).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260708-cey-woo-maintenance-reconciliation-pass-2-re/
@CLAUDE.md
@app/Domain/Products/Services/ProductGapReport.php
@app/Filament/Widgets/WooMaintenanceGapsWidget.php
@app/Filament/Pages/CatalogueGapsPage.php
@app/Domain/Products/Models/Product.php
@tests/Feature/Products/ProductGapReportTest.php
@tests/Feature/Products/CatalogueGapsPageTest.php
---
Pass 1 added woo_image_count / woo_gtin / woo_category_count / woo_stock_status / woo_reconciled_at
(populated by products:reconcile-woo-maintenance). Reconciliation is proven on prod (4,604/4,604).
</context>

<interfaces>
=== ProductGapReport ===
```php
public const GAPS = [
    'missing_images'   => 'Missing images',
    'missing_ean'      => 'Missing EAN',
    'missing_category' => 'Missing category',
];

// liveBase() unchanged: Product where status=publish + whereNotNull(woo_product_id).

/** Gaps are only meaningful for RECONCILED products (we know their real Woo state). */
public function apply(Builder $query, string $gap): Builder
{
    $query->whereNotNull('woo_reconciled_at');
    return match ($gap) {
        'missing_images'   => $query->where('woo_image_count', 0),
        'missing_ean'      => $query->whereNull('woo_gtin'),
        'missing_category' => $query->where('woo_category_count', 0),
        default            => $query,
    };
}

/** @return array{total:int,reconciled:int,not_reconciled:int,last_reconciled_at:?string,gaps:array<string,int>} cached 300s. */
public function counts(): array
{
    return Cache::remember('woo_maintenance.gap_counts', 300, function (): array {
        $row = $this->liveBase()->selectRaw(
            'COUNT(*) as total'
            .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL THEN 1 ELSE 0 END) as reconciled'
            .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_image_count = 0 THEN 1 ELSE 0 END) as missing_images'
            .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_gtin IS NULL THEN 1 ELSE 0 END) as missing_ean'
            .', SUM(CASE WHEN woo_reconciled_at IS NOT NULL AND woo_category_count = 0 THEN 1 ELSE 0 END) as missing_category'
            .', MAX(woo_reconciled_at) as last_reconciled_at'
        )->first();

        $total = (int) ($row->total ?? 0);
        $reconciled = (int) ($row->reconciled ?? 0);
        return [
            'total' => $total,
            'reconciled' => $reconciled,
            'not_reconciled' => max(0, $total - $reconciled),
            'last_reconciled_at' => $row->last_reconciled_at ?? null,
            'gaps' => [
                'missing_images' => (int) ($row->missing_images ?? 0),
                'missing_ean' => (int) ($row->missing_ean ?? 0),
                'missing_category' => (int) ($row->missing_category ?? 0),
            ],
        ];
    });
}
```
Drop the old EMPTY_GALLERY_SQL / gallery / ean / stock_status / brand predicates (no longer used). Remove
the emptyImagesExpr remnants if any remain.

=== WooMaintenanceGapsWidget ===
getStats() (reads counts()):
- `Stat::make('Live on Woo', total)`.
- `Stat::make('Reconciled', reconciled.' / '.total)` with description "last: <last_reconciled_at diffForHumans, or 'never — run products:reconcile-woo-maintenance'>"; color 'warning' if not_reconciled>0 OR reconciled==0 else 'success'.
- One Stat per gap (label from GAPS, value = count) → `->color($count>0?'warning':'success')->url(CatalogueGapsPage::getUrl().'?tableFilters[gap][value]='.$gapKey)`.

=== CatalogueGapsPage ===
- The Gap SelectFilter ->options(ProductGapReport::GAPS) (now 3), default 'missing_images', ->query reuses $report->apply (which now gates on woo_reconciled_at + reads woo_*). Base query stays liveBase().
- Columns: replace the old images/ean state columns with the reconciled truth — `woo_image_count` (label 'Images', danger when 0), `woo_gtin` (label 'EAN', placeholder '— none', danger when null), `woo_category_count` (label 'Categories', danger when 0), keep sku/name, `woo_reconciled_at` (dateTime, label 'Reconciled'). Keep the fix actions (Source images / Backfill EAN / Resync) unchanged.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: rewire ProductGapReport + widget + Catalogue Gaps to reconciled woo_* fields</name>
  <files>
    app/Domain/Products/Services/ProductGapReport.php,
    app/Filament/Widgets/WooMaintenanceGapsWidget.php,
    app/Filament/Pages/CatalogueGapsPage.php,
    tests/Feature/Products/ProductGapReportTest.php,
    tests/Feature/Products/CatalogueGapsPageTest.php
  </files>
  <behavior>
    Apply the <interfaces>. REWRITE the two tests to seed the woo_* reconciled fields instead of local
    gallery/ean:
    ProductGapReportTest — seed live products (publish + woo_product_id), all with woo_reconciled_at set:
      R1 woo_image_count=0 (missing image), R2 woo_gtin=null (missing ean), R3 woo_category_count=0 (missing
      category), R4 fully populated (image_count=3, gtin='123', category_count=2), + R5 UNRECONCILED
      (woo_reconciled_at NULL, all woo_* null) which must NOT be counted in any gap. Assert counts(): total=5,
      reconciled=4, not_reconciled=1, gaps missing_images=1/missing_ean=1/missing_category=1,
      last_reconciled_at not null. Assert apply(liveBase(),'missing_images') = [R1] only.
    CatalogueGapsPageTest — seed reconciled products; filterTable('gap','missing_images') shows the
      image_count=0 one, not the populated one; filterTable('gap','missing_ean') shows the gtin-null one;
      keep the fix-action assertions (source_images/resync call the --skus commands). Update column
      references to the woo_* columns.
    (Cache::flush() at test start.)
  </behavior>
  <action>
    Rewire ProductGapReport (GAPS, apply, counts), the widget (coverage + 3 gap stats), and CatalogueGapsPage
    (filter + columns). Rewrite both tests to the woo_* fields. Run both tests + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/ProductGapReportTest.php tests/Feature/Products/CatalogueGapsPageTest.php 2>&1 | tail -20</automated>
    Expected: GREEN — gaps from reconciled woo_* fields; unreconciled excluded; filter + fix actions work.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Products/Services/ProductGapReport.php app/Filament/Widgets/WooMaintenanceGapsWidget.php app/Filament/Pages/CatalogueGapsPage.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Dashboard reports gaps from reconciled woo_* fields (images/EAN/category) over reconciled products; Overview shows coverage + last-reconciled; Catalogue Gaps filters + columns on woo_*; fix actions intact; cheap queries; tests rewritten + green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Products/ProductGapReportTest.php tests/Feature/Products/CatalogueGapsPageTest.php` → GREEN
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
- Maintenance Overview now shows the TRUE shop-wide gaps from reconciliation: ~180 missing images, ~628
  missing EAN, 0 missing category (over 4,604 live), plus a Reconciled X/Y + last-reconciled stat. Cards
  drill into Catalogue Gaps → Source images / Backfill EAN / Resync (cap batches — API cost).
- Freshness: the nightly `products:reconcile-woo-maintenance` (04:30) keeps woo_* current; the counts cache
  300s. If 'Reconciled' shows 0 / stale, run the command manually.
- Dropped from the gap set: brand (not in the Woo /products reconciliation — would need a product_brand
  scan) and stock status (Woo always sets one — never a gap). Note for a future brand-reconciliation pass.
</verification>

<success_criteria>
- The Woo Maintenance dashboard shows true whole-shop gap counts (images/EAN/category) from the reconciled woo_* fields over reconciled live products, with coverage + last-reconciled shown; Catalogue Gaps filters/columns/fix-actions work on the reconciled data; queries stay cheap; both tests rewritten + green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260708-cey-woo-maintenance-reconciliation-pass-2-re/260708-cey-SUMMARY.md` documenting
the rewire to reconciled woo_* fields (3 real gaps + coverage/last-reconciled), the dropped brand/stock gaps,
the test rewrite, and that the Woo Maintenance feature now reflects the true whole-shop state.
</output>
