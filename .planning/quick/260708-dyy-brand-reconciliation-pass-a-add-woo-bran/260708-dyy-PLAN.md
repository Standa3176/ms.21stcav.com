---
phase: 260708-dyy-brand-reconciliation-pass-a-add-woo-bran
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_07_08_010000_add_woo_brand_count_to_products_table.php
  - app/Domain/Products/Models/Product.php
  - app/Console/Commands/ReconcileWooMaintenanceCommand.php
  - tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
must_haves:
  truths:
    - "products:reconcile-woo-maintenance now ALSO records each live product's real brand state: a second pass pages the WP REST product list (`GET wp/v2/product`, status=publish, per_page=100, _fields=id,product_brand) via WpRestClient and sets woo_brand_count = count(product_brand term-id array) on the matched local Product (by woo_product_id). product_brand is the taxonomy that drives the storefront Brand link (verified on prod: rows come back as {id, product_brand:[termIds]})."
    - "A migration adds nullable `woo_brand_count` (int) to products. The WC pass (image/EAN/category/stock + woo_reconciled_at) is UNCHANGED; the brand pass runs after it and only sets woo_brand_count. Still READ-ONLY on Woo/WP (GET only). --dry-run writes nothing; the summary gains a brand_updated row."
    - "WP REST responses are plain arrays (WpRestClient decodes with assoc), but the loop still casts each item `(array) $p` defensively (mirrors the WC-pass stdClass fix)."
    - "Additive — ProductGapReport / the Maintenance dashboard are NOT touched here (Pass B adds missing_brand once this reconcile is verified on prod)."
  artifacts:
    - path: "app/Console/Commands/ReconcileWooMaintenanceCommand.php"
      provides: "WP REST brand pass → woo_brand_count"
      contains: "wp/v2/product"
    - path: "database/migrations/2026_07_08_010000_add_woo_brand_count_to_products_table.php"
      provides: "woo_brand_count column"
      contains: "woo_brand_count"
    - path: "tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php"
      provides: "brand pass populates woo_brand_count from product_brand"
      contains: "woo_brand_count"
  key_links:
    - from: "ReconcileWooMaintenanceCommand brand pass"
      to: "WpRestClient::get('wp/v2/product', paged) → woo_brand_count by woo_product_id"
      via: "count(product_brand) = real storefront brand presence"
      pattern: "woo_brand_count"
---

<objective>
Extend the Woo reconciliation to capture real brand state so the dashboard can report true "missing brand"
(the one gap not in the WC /products response). Add woo_brand_count + a WP-REST pass in
products:reconcile-woo-maintenance that pages /wp/v2/product and records each product's product_brand count.
Verify-first: this pass A ships the data; Pass B wires the dashboard once the numbers are confirmed on prod.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260708-dyy-brand-reconciliation-pass-a-add-woo-bran/
@CLAUDE.md
@app/Console/Commands/ReconcileWooMaintenanceCommand.php
@app/Domain/Sync/Services/WpRestClient.php
@app/Domain/Products/Models/Product.php
---
WpRestClient::get(path, query) returns plain (assoc) arrays. Verified on prod: `wp->get('wp/v2/product',
['per_page'=>5,'_fields'=>'id,product_brand','status'=>'publish'])` → [{id, product_brand:[termIds]}, ...].
The command already has a WC /products pass; ADD a WP-REST pass after it (same paging + match-by-woo_product_id shape).
</context>

<interfaces>
=== Migration (2026_07_08_010000_add_woo_brand_count_to_products_table.php) ===
```php
Schema::table('products', fn (Blueprint $t) => $t->unsignedInteger('woo_brand_count')->nullable()->after('woo_category_count'));
// down(): drop woo_brand_count.
```

=== Product model ===
Add 'woo_brand_count' to $fillable; cast `woo_brand_count` => 'integer'.

=== ReconcileWooMaintenanceCommand ===
- Constructor: also inject `private readonly WpRestClient $wp` (add `use App\Domain\Sync\Services\WpRestClient;`).
- Add a `$brandUpdated = 0;` counter.
- AFTER the existing WC pass loop (before the summary table), add the WP-REST brand pass:
```php
// ── Brand pass: real product_brand presence via WP REST (the storefront Brand link) ──
$bPage = 1;
while (true) {
    try {
        $brandRows = $this->wp->get('wp/v2/product', [
            'status' => 'publish', 'per_page' => $perPage, 'page' => $bPage, '_fields' => 'id,product_brand',
        ]);
    } catch (\Throwable $e) {
        $this->warn('WP REST product page '.$bPage.' failed: '.$e->getMessage());
        \Illuminate\Support\Facades\Log::warning('reconcile_woo_maintenance.wp_page_failed', ['page' => $bPage, 'error' => $e->getMessage()]);
        break;
    }
    if (! is_array($brandRows) || $brandRows === []) {
        break;
    }
    foreach ($brandRows as $p) {
        $p = (array) $p;
        $wooId = (int) ($p['id'] ?? 0);
        if ($wooId <= 0) {
            continue;
        }
        $product = Product::where('woo_product_id', $wooId)->first();
        if ($product === null) {
            continue;
        }
        if (! $dryRun) {
            $product->forceFill(['woo_brand_count' => count((array) ($p['product_brand'] ?? []))])->saveQuietly();
            $brandUpdated++;
        }
    }
    if (count($brandRows) < $perPage) {
        break;
    }
    $bPage++;
    if ($maxPages > 0 && $bPage > $maxPages) {
        break;
    }
}
```
- Add `['brand_updated', $brandUpdated]` to the summary table rows.
Keep the WC pass + all existing counters/behaviour exactly as-is. Match the existing variable names
($perPage, $dryRun, $maxPages) — adapt if they differ.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: woo_brand_count column + WP-REST brand pass in reconcile command</name>
  <files>
    database/migrations/2026_07_08_010000_add_woo_brand_count_to_products_table.php,
    app/Domain/Products/Models/Product.php,
    app/Console/Commands/ReconcileWooMaintenanceCommand.php,
    tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
  </files>
  <behavior>
    Add the migration + model cast + WP-REST brand pass per <interfaces>. Extend the existing feature test:
    bind a WpRestClient stub (app()->instance) whose get('wp/v2/product', page 1) returns
    [['id'=>101,'product_brand'=>[12843]], ['id'=>102,'product_brand'=>[]]] and page>=2 → []; keep the
    existing WC WooClient stub for the WC pass. After running the command assert: product 101 → woo_brand_count=1,
    product 102 → woo_brand_count=0 (in addition to the existing WC-field assertions still holding). --dry-run
    leaves woo_brand_count null. The WpRestClient stub's post()/put()/delete() must not be called (read-only).
    (WpRestClient returns assoc arrays, so the stub returns arrays; the (array)$p cast is belt-and-braces.)
  </behavior>
  <action>
    Create the migration, add the model cast, inject WpRestClient + add the brand pass + summary row. Extend
    the test with the WpRestClient stub + brand assertions. Run the test + pint. Confirm command still registered.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php 2>&1 | tail -18</automated>
    Expected: GREEN — WC fields still populated + woo_brand_count set from product_brand (101→1, 102→0); dry-run no-op.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/ReconcileWooMaintenanceCommand.php app/Domain/Products/Models/Product.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - woo_brand_count column added; reconcile command's WP-REST pass records product_brand count per live product (read-only); existing WC pass unchanged; feature-tested (incl. dry-run + no WP writes); pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php` → GREEN
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (RUNS a migration — 1 nullable column).
- Re-run the reconcile (now also does the brand pass, read-only): `php artisan products:reconcile-woo-maintenance`
  — the summary should show brand_updated ≈ matched. Then check the REAL missing-brand count:
    php artisan tinker --execute='$rec=App\Domain\Products\Models\Product::where("status","publish")->whereNotNull("woo_product_id")->whereNotNull("woo_reconciled_at"); echo "reconciled: ".(clone $rec)->count()." | missing brand: ".(clone $rec)->where("woo_brand_count",0)->count()." | brand not reconciled (null): ".(clone $rec)->whereNull("woo_brand_count")->count()."\n";'
- Once that missing-brand number looks right, Pass B adds `missing_brand` (woo_brand_count = 0) to
  ProductGapReport + the Overview + Catalogue Gaps.
</verification>

<success_criteria>
- woo_brand_count column + a read-only WP-REST brand pass in the reconcile command populate each live product's real product_brand count; WC pass unchanged; feature-tested (dry-run + read-only); pint clean. Ready for the Pass-B dashboard wire once verified on prod.
</success_criteria>

<output>
Create `.planning/quick/260708-dyy-brand-reconciliation-pass-a-add-woo-bran/260708-dyy-SUMMARY.md` documenting
the woo_brand_count column, the WP-REST brand pass (product_brand count, read-only), the test, and the operator
re-run + verification before Pass B.
</output>
