---
phase: 260701-pmr-add-products-backfill-woo-stock-one-time
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Console/Commands/BackfillWooStockCommand.php
  - app/Providers/AppServiceProvider.php
  - tests/Feature/Console/BackfillWooStockCommandTest.php
must_haves:
  truths:
    - "products:backfill-woo-stock pushes manage_stock=true + stock_quantity + stock_status (via the BuildsWooStockPayload trait) to Woo for existing app-created published products, so they show a stock line like legacy products — one-time backfill for products created before 260701-opg; new products already get this at publish."
    - "Scope: by default products where auto_create_status='published' AND woo_product_id > 0. --skus=CSV overrides the scope to those exact SKUs. --limit=N caps the run (0 = all). --dry-run prints what would be pushed and writes nothing."
    - "Per product it PUTs products/{woo_product_id} with the stock payload. On a WooCommerce woocommerce_rest_product_invalid_id error it NULLs the stale woo_product_id (saveQuietly) + logs + counts as skipped_stale, and CONTINUES (never aborts the batch); any other error is counted + logged as an error and the loop continues."
    - "Idempotent + safe to re-run: setting manage_stock/qty/status on an already-correct product is harmless; no Claude spend (pure Woo PUTs). After it runs, the existing scheduled cutover:auto-sync keeps stock_quantity fresh."
    - "The command's per-product push logic is exercised by a feature test with a WooClient stub (records PUTs); dry-run makes zero PUTs; invalid-id nulls that product's id while others still push."
  artifacts:
    - path: "app/Console/Commands/BackfillWooStockCommand.php"
      provides: "products:backfill-woo-stock — push stock keys to Woo for app-created products"
      contains: "backfill-woo-stock"
    - path: "tests/Feature/Console/BackfillWooStockCommandTest.php"
      provides: "push / dry-run / invalid-id-skip coverage"
      contains: "manage_stock"
  key_links:
    - from: "BackfillWooStockCommand"
      to: "WooClient::put(products/{id}, wooStockPayload)"
      via: "BuildsWooStockPayload trait, invalid-id guard clears stale ids"
      pattern: "wooStockPayload"
---

<objective>
Backfill stock display onto existing app-created products. New auto-published products already carry
manage_stock+stock_quantity+stock_status (260701-opg); but ~600 products published BEFORE that fix have
manage_stock=false on Woo, so WooCommerce ignores their quantity and shows no stock line. The scheduled
cutover:auto-sync pushes stock_quantity but NOT manage_stock, so those products never display stock.

Add products:backfill-woo-stock — a one-time (re-runnable) command that PUTs manage_stock=true + current
stock_quantity + stock_status to Woo for the existing app-created products. Pure Woo writes, no Claude.
After it runs, cutover:auto-sync keeps the quantities fresh. Reuses the BuildsWooStockPayload trait and
the invalid-id guard shipped earlier today.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260701-pmr-add-products-backfill-woo-stock-one-time/
@CLAUDE.md
@app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php
@app/Console/Commands/ReconcileStaleWooIdsCommand.php
@app/Console/Commands/BaseCommand.php
@tests/Feature/Console/BackfillCategoryFromWooCommandTest.php

<interfaces>
Reuse the trait (260701-opg): App\Domain\ProductAutoCreate\Concerns\BuildsWooStockPayload::wooStockPayload(
Product $product): array{manage_stock:bool, stock_quantity:int, stock_status:string}. (It's `protected`;
`use` the trait on the command so $this->wooStockPayload() is available.)

BaseCommand: extend it, implement `protected function perform(): int`. Inject WooClient via constructor
(mirror ReconcileStaleWooIdsCommand which just shipped — same WooClient injection + iterate-products +
dry-run + AppServiceProvider registration pattern).

WooClient: get(...)/put(string $path, array $body). put throws Automattic\...\HttpClientException on WC
error; the observed invalid-id message contains `woocommerce_rest_product_invalid_id`. WooClient is
subclassable for tests (anon subclass, skip parent __construct, override put() to record $calls +
optionally throw) — see bindWooStub in tests/Feature/Console/BackfillCategoryFromWooCommandTest.php.

Product: whereNotNull('woo_product_id')->where('woo_product_id','>',0); auto_create_status ('published'
etc.); status; sku; stock_quantity (int); stock_status.

Command shape:
```php
protected $signature = 'products:backfill-woo-stock
    {--skus= : Comma-separated SKUs to target instead of the default app-created-published set}
    {--limit=0 : Max products this run (0 = all)}
    {--dry-run : Print what would be pushed; write nothing}';

protected function perform(): int
{
    $skus = /* parse --skus CSV, trimmed, non-empty */;
    $q = Product::query()->whereNotNull('woo_product_id')->where('woo_product_id','>',0);
    $q = $skus !== []
        ? $q->whereIn('sku', $skus)
        : $q->where('auto_create_status', 'published');
    $limit = max(0, (int) $this->option('limit'));
    if ($limit > 0) { $q->limit($limit); }
    $dryRun = (bool) $this->option('dry-run');

    $pushed = 0; $wouldPush = 0; $skippedStale = 0; $errors = 0;
    foreach ($q->cursor() as $product) {
        $payload = $this->wooStockPayload($product);
        if ($dryRun) {
            $wouldPush++;
            if ($wouldPush <= 20) {
                $this->line(sprintf('  would push %s → qty=%d %s', $product->sku, $payload['stock_quantity'], $payload['stock_status']));
            }
            continue;
        }
        try {
            $this->woo->put("products/{$product->woo_product_id}", $payload);
            $pushed++;
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'woocommerce_rest_product_invalid_id')) {
                Log::warning('backfill_woo_stock.stale_id_cleared', ['product_id'=>$product->id,'sku'=>$product->sku,'woo_product_id'=>$product->woo_product_id]);
                $product->forceFill(['woo_product_id'=>null])->saveQuietly();
                $skippedStale++;
            } else {
                Log::warning('backfill_woo_stock.push_failed', ['product_id'=>$product->id,'sku'=>$product->sku,'error'=>$e->getMessage()]);
                $errors++;
            }
        }
    }
    // print summary (dry-run vs live counts)
    return SymfonyCommand::SUCCESS;
}
```
Register the command in AppServiceProvider's commands list (same place SyncSupplierFeedDatesCommand /
ReconcileStaleWooIdsCommand are registered). NOT scheduled (one-time; cutover:auto-sync handles ongoing).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: BackfillWooStockCommand + registration + feature test</name>
  <files>
    app/Console/Commands/BackfillWooStockCommand.php,
    app/Providers/AppServiceProvider.php,
    tests/Feature/Console/BackfillWooStockCommandTest.php
  </files>
  <behavior>
    Command per <interfaces>: default scope app-created published + woo_product_id>0; --skus / --limit /
    --dry-run; per-product PUT of the stock payload; invalid-id → null id + skippedStale + continue;
    other error → errors + continue; summary printed. Registered in AppServiceProvider.

    Feature test (RefreshDatabase; WooClient anon-subclass stub recording put calls as
    ['path'=>, 'body'=>], with a way to make put() throw for a specific woo id):
      - Seed 3 products auto_create_status='published': A (woo 100, stock 5), B (woo 200, stock 0),
        C (woo 300, stock 12). Plus a NON-published product D (auto_create_status='draft', woo 400) to
        prove default scope excludes it.
      - Live run: stub records PUT products/100 {manage_stock:true,stock_quantity:5,stock_status:instock},
        products/200 {…,0,outofstock}, products/300 {…,12,instock}; NO put for 400. Summary "pushed=3".
      - --dry-run: zero puts recorded; A/B/C unchanged.
      - invalid-id: stub throws a RuntimeException containing 'woocommerce_rest_product_invalid_id' for
        woo 200 → product B woo_product_id null after run; A & C still pushed; skipped_stale=1, pushed=2;
        command exits SUCCESS (no throw).
      - --skus=A-SKU targets only that product (ignores the published-scope default).
    Drive via Artisan::call('products:backfill-woo-stock', [...]).
  </behavior>
  <action>
    Create the command; `use BuildsWooStockPayload`; register in AppServiceProvider. Write the feature
    test with the WooClient stub (record calls + per-id throw). Run + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Console/BackfillWooStockCommandTest.php 2>&1 | tail -20</automated>
    Expected: GREEN.
    <automated>~/.config/herd/bin/php84/php.exe artisan list 2>&1 | grep -i backfill-woo-stock</automated>
    Expected: command listed.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/BackfillWooStockCommand.php app/Providers/AppServiceProvider.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Command exists + registered; default scope = app-created published; --skus/--limit/--dry-run work; invalid-id skips+clears+continues; test GREEN; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Console/BackfillWooStockCommandTest.php` → GREEN
2. `pest tests/Feature/Console/` → GREEN (no regression)
3. `artisan list | grep backfill-woo-stock` → present
4. `pint --test` on the command + AppServiceProvider → PASS

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Preview: `php artisan products:backfill-woo-stock --dry-run` (shows count + sample; no writes).
- Apply: `php artisan products:backfill-woo-stock` (~600 Woo PUTs; ~100 req/min headroom → a few minutes).
  Optionally batch with --limit=200. Re-runnable / idempotent.
- After it runs, the scheduled cutover:auto-sync (--field=stock_quantity) keeps quantities fresh; app
  products now display stock like legacy products.
- Any stale Woo ids encountered are cleared (nulled) + logged backfill_woo_stock.stale_id_cleared, same
  as the price-push guard.
</verification>

<success_criteria>
- products:backfill-woo-stock pushes manage_stock+qty+status to Woo for existing app-created products (dry-run first), no Claude spend.
- Default scope = app-created published; --skus/--limit/--dry-run supported; invalid-id self-heals + continues.
- Feature test green; command registered; pint clean; reuses BuildsWooStockPayload.
</success_criteria>

<output>
Create `.planning/quick/260701-pmr-add-products-backfill-woo-stock-one-time/260701-pmr-SUMMARY.md` documenting:
- Why existing app products need the backfill (manage_stock=false; cutover:auto-sync pushes qty but not manage_stock).
- The command (scope, flags, invalid-id guard, idempotent, no Claude) reusing BuildsWooStockPayload.
- Operator dry-run→apply steps; that ongoing freshness is already handled by cutover:auto-sync.
</output>
