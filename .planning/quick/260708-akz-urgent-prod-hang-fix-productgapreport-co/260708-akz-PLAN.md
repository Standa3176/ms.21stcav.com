---
phase: 260708-akz-urgent-prod-hang-fix-productgapreport-co
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/Products/Services/ProductGapReport.php
  - app/Filament/Widgets/WooMaintenanceGapsWidget.php
  - tests/Feature/Products/ProductGapReportTest.php
must_haves:
  truths:
    - "PROD HANG FIX. ProductGapReport::counts() no longer runs 5-6 separate full-table scans with a per-row JSON function (JSON_LENGTH(gallery_image_urls)=0) over the whole live catalogue — which exceeded PHP's 30s max_execution_time, and because it timed out INSIDE Cache::remember it never cached → every render (incl. the auto-discovered dashboard widget) re-ran it and hung. counts() is now ONE aggregate query (conditional SUMs) over liveBase() with plain, index-friendly predicates (no JSON function)."
    - "The empty-gallery check is now `gallery_image_urls IS NULL OR gallery_image_urls = '[]' OR gallery_image_urls = ''` (Laravel array-cast stores an empty gallery as the literal '[]' — a plain string compare, no JSON_LENGTH/json_array_length). Used in BOTH counts() and apply('missing_images'), so the Catalogue Gaps filter is also cheap. Verdict is identical to the old JSON_LENGTH=0 (empty array), so counts don't change."
    - "counts() cache TTL raised to 300s; WooMaintenanceGapsWidget auto-poll disabled (protected static ?string $pollingInterval = null) so the dashboard/overview don't re-run it every 30s."
    - "Counts are unchanged vs the pre-fix logic for the same data (existing ProductGapReportTest assertions still hold); apply() for ean/stock_status/brand/category is unchanged; only the query SHAPE + the images predicate changed (JSON func → plain string compare)."
  artifacts:
    - path: "app/Domain/Products/Services/ProductGapReport.php"
      provides: "single-aggregate counts() + no-JSON empty-gallery predicate + 300s cache"
      contains: "SUM(CASE WHEN"
    - path: "app/Filament/Widgets/WooMaintenanceGapsWidget.php"
      provides: "auto-poll disabled"
      contains: "pollingInterval"
    - path: "tests/Feature/Products/ProductGapReportTest.php"
      provides: "counts still correct with the new predicate/aggregate"
      contains: "counts"
  key_links:
    - from: "ProductGapReport::counts()"
      to: "single conditional-SUM aggregate over liveBase() (no per-row JSON function)"
      via: "one scan instead of six JSON scans → completes < 30s → caches → no hang"
      pattern: "SUM(CASE WHEN"
---

<objective>
URGENT: the admin hangs (30s max_execution_time fatals) because ProductGapReport::counts() runs 5-6 full
JSON scans (JSON_LENGTH(gallery_image_urls)=0) over the whole live catalogue, times out, and never caches —
and the auto-discovered WooMaintenanceGapsWidget runs it on the Dashboard (and auto-polls). Rewrite counts()
as ONE cheap aggregate query with a plain (no-JSON) empty-gallery check, raise the cache TTL, and disable the
widget poll. Counts stay identical; the page just stops hanging.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260708-akz-urgent-prod-hang-fix-productgapreport-co/
@CLAUDE.md
@app/Domain/Products/Services/ProductGapReport.php
@app/Filament/Widgets/WooMaintenanceGapsWidget.php
@tests/Feature/Products/ProductGapReportTest.php
</context>

<interfaces>
=== ProductGapReport ===
Add a shared empty-gallery predicate constant/method (no JSON function):
```php
/** Empty gallery, index-friendly (Laravel array-cast stores [] as the literal '[]'). No JSON func. */
private const EMPTY_GALLERY_SQL = "(gallery_image_urls IS NULL OR gallery_image_urls = '[]' OR gallery_image_urls = '')";
```

REPLACE the JSON-based image checks:
- In `apply()`, `'missing_images'` → `$query->whereRaw(self::EMPTY_GALLERY_SQL)` (drop the emptyImagesExpr / JSON_LENGTH path). Delete the now-unused `emptyImagesExpr()` helper.
- `'missing_ean'` → `$query->whereRaw("(ean IS NULL OR TRIM(ean) = '')")` (keep).
- `'missing_stock_status'` → `$query->whereRaw("(stock_status IS NULL OR TRIM(stock_status) = '')")` (keep).
- brand/category → whereNull (unchanged).

REWRITE `counts()` as a SINGLE aggregate (one scan; 300s cache):
```php
public function counts(): array
{
    return Cache::remember('woo_maintenance.gap_counts', 300, function (): array {
        $row = $this->liveBase()->selectRaw(
            'COUNT(*) as total'
            .', SUM(CASE WHEN '.self::EMPTY_GALLERY_SQL." THEN 1 ELSE 0 END) as missing_images"
            .", SUM(CASE WHEN (ean IS NULL OR TRIM(ean) = '') THEN 1 ELSE 0 END) as missing_ean"
            .", SUM(CASE WHEN (stock_status IS NULL OR TRIM(stock_status) = '') THEN 1 ELSE 0 END) as missing_stock_status"
            .', SUM(CASE WHEN brand_id IS NULL THEN 1 ELSE 0 END) as missing_brand'
            .', SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) as missing_category'
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'gaps' => [
                'missing_images' => (int) ($row->missing_images ?? 0),
                'missing_ean' => (int) ($row->missing_ean ?? 0),
                'missing_stock_status' => (int) ($row->missing_stock_status ?? 0),
                'missing_brand' => (int) ($row->missing_brand ?? 0),
                'missing_category' => (int) ($row->missing_category ?? 0),
            ],
        ];
    });
}
```
Keep the GAPS const + liveBase() as-is. (liveBase()->selectRaw(...)->first() = one query over the
status=publish + woo_product_id set.)

=== WooMaintenanceGapsWidget ===
Disable the StatsOverviewWidget auto-poll (default 30s) so the dashboard/overview don't repeatedly re-run
counts():
```php
protected static ?string $pollingInterval = null;
```
(getStats() still reads app(ProductGapReport::class)->counts() — now cheap + cached.)
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: cheap single-query counts() + no-JSON gallery predicate + disable widget poll</name>
  <files>
    app/Domain/Products/Services/ProductGapReport.php,
    app/Filament/Widgets/WooMaintenanceGapsWidget.php,
    tests/Feature/Products/ProductGapReportTest.php
  </files>
  <behavior>
    Apply the <interfaces> changes. The EXISTING ProductGapReportTest must still pass unchanged — it seeds a
    live product with an empty gallery (cast [] → '[]'), null ean, '' stock_status, null brand_id, null
    category_id + a complete one + excluded draft/not-on-Woo; assert counts()['total']==6 and each gap==1.
    The new EMPTY_GALLERY_SQL must still classify the empty-gallery row as missing_images (it does: '[]').
    Add one assertion that a product with a NON-empty gallery (e.g. ['http://x/img.jpg']) is NOT counted as
    missing_images (guards the '[]'-only match). Also assert apply(liveBase(),'missing_images') matches the
    empty-gallery product and not the populated one.
    (Cache::flush() at test start so the 300s cache doesn't bleed across cases.)
  </behavior>
  <action>
    Edit ProductGapReport (const + apply images + counts single-aggregate + drop emptyImagesExpr) and the
    widget (pollingInterval null). Update/extend the test. Run it + the Catalogue Gaps test (wa9) as
    regression + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/ProductGapReportTest.php tests/Feature/Products/CatalogueGapsPageTest.php 2>&1 | tail -18</automated>
    Expected: GREEN — counts unchanged (total 6, each gap 1); populated gallery not flagged; Catalogue Gaps filter still narrows.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/Products/Services/ProductGapReport.php app/Filament/Widgets/WooMaintenanceGapsWidget.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - counts() is one cheap aggregate (no per-row JSON func), empty-gallery is a plain string compare, cache 300s, widget poll off; counts identical for the same data; Catalogue Gaps filter still works; tests + pint green.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Products/ProductGapReportTest.php tests/Feature/Products/CatalogueGapsPageTest.php` → GREEN
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy IMMEDIATELY: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- This resolves the admin hang: the Woo Maintenance gap counts are now one cheap aggregate query (no
  per-row JSON scan), so the Maintenance Overview + the auto-discovered Dashboard widget + Catalogue Gaps all
  load fast; counts cache 300s; the widget no longer polls.
- Confirm on prod after deploy (should be sub-second):
    php artisan tinker --execute='Cache::forget("woo_maintenance.gap_counts"); $t=microtime(true); $r=app(App\Domain\Products\Services\ProductGapReport::class)->counts(); echo json_encode($r)." in ".round((microtime(true)-$t)*1000)."ms\n";'
- If pages OTHER than the dashboard / Woo Maintenance were also hanging, tell me — this fix covers the gap-count path; anything else would be separate.
</verification>

<success_criteria>
- The admin no longer hangs: ProductGapReport::counts() is a single cheap aggregate with a no-JSON empty-gallery predicate (identical counts), 300s cache, widget poll disabled; existing + Catalogue Gaps tests green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260708-akz-urgent-prod-hang-fix-productgapreport-co/260708-akz-SUMMARY.md` documenting
the hang root cause (JSON full-scan ×5 timing out uncached, run on the auto-discovered dashboard widget), the
single-aggregate + no-JSON-predicate + cache + poll-off fix, and the prod timing probe.
</output>
