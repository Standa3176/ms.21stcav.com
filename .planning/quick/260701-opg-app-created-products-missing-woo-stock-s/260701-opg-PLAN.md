---
phase: 260701-opg-app-created-products-missing-woo-stock-s
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php
  - app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php
  - tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php
  - tests/Feature/ProductAutoCreate/PublishProductStockTest.php
must_haves:
  truths:
    - "When PublishProductJob publishes an auto-created product to Woo, the payload includes manage_stock=true, stock_quantity (from the product), and stock_status — so the storefront shows a stock line ('In stock (N)' / 'Out of stock') exactly like the legacy products, which app-created products currently lack."
    - "Both publish paths carry stock: Path A (existing Woo draft → PUT) now sends status=publish PLUS the stock fields; Path B (buildCreatePayload → POST) includes the stock fields."
    - "Stock values derive from the product: stock_quantity = max(0, (int) product.stock_quantity); stock_status = product.stock_status when it's a valid WC value (instock/outofstock/onbackorder), else instock when qty>0 else outofstock."
    - "Stock derivation is a shared, pure, unit-tested trait method wooStockPayload(Product $product): array{manage_stock:bool, stock_quantity:int, stock_status:string}."
    - "No change to price/EAN/description handling or the split-write; only stock keys are added. Live-price sync and the EAN-collision retry are untouched."
  artifacts:
    - path: "app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php"
      provides: "wooStockPayload() — manage_stock/stock_quantity/stock_status from a Product"
      contains: "manage_stock"
    - path: "app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php"
      provides: "stock fields merged into Path A PUT + Path B buildCreatePayload"
      contains: "wooStockPayload"
    - path: "tests/Feature/ProductAutoCreate/PublishProductStockTest.php"
      provides: "asserts published payload carries manage_stock + stock_quantity + stock_status"
      contains: "manage_stock"
  key_links:
    - from: "PublishProductJob (Path A PUT + Path B buildCreatePayload)"
      to: "Woo product stock fields"
      via: "wooStockPayload(Product) merged into the write"
      pattern: "wooStockPayload"
---

<objective>
App-created products don't show a stock line on the storefront; legacy products show "In stock (N)"
or "Out of stock". Cause: neither Woo write in the auto-create flow sets stock management —
PublishProductJob Path A does `PUT products/{id} {status:publish}` (no stock), and buildCreatePayload
(Path B POST) has no stock keys either. So Woo leaves manage_stock off and the theme shows no stock.

Fix: push manage_stock=true + stock_quantity + stock_status (from the product, which already tracks
both locally) on publish, via a shared trait, so app products display stock like legacy ones. Additive
+ low-risk: only stock keys are added; price/EAN/description/split-write logic is untouched.

SCOPE NOTE (for SUMMARY): this makes stock DISPLAY correctly at publish time. Whether stock stays
fresh on Woo afterwards (ongoing stock→Woo push) is a separate mechanism to verify — flag it, don't
build it here.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260701-opg-app-created-products-missing-woo-stock-s/
@CLAUDE.md
@app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php
@app/Domain/Products/Models/Product.php
@tests/Feature/Console/BackfillCategoryFromWooCommandTest.php

<interfaces>
Product model: casts stock_quantity => integer; has stock_status (string, nullable). Both are fillable.

NEW trait app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php:
```php
<?php
declare(strict_types=1);
namespace App\Domain\ProductAutoCreate\Concerns;

use App\Domain\Products\Models\Product;

trait BuildsWooStockPayload
{
    /**
     * WooCommerce stock keys so app-created products display a stock line like
     * legacy products. manage_stock=true makes WC show/track quantity; stock_status
     * is set explicitly for theme display and reconciled by WC from quantity.
     *
     * @return array{manage_stock:bool, stock_quantity:int, stock_status:string}
     */
    protected function wooStockPayload(Product $product): array
    {
        $qty = max(0, (int) ($product->stock_quantity ?? 0));
        $status = (string) ($product->stock_status ?? '');
        if (! in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
            $status = $qty > 0 ? 'instock' : 'outofstock';
        }

        return [
            'manage_stock' => true,
            'stock_quantity' => $qty,
            'stock_status' => $status,
        ];
    }
}
```

PublishProductJob (app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php):
- Add `use App\Domain\ProductAutoCreate\Concerns\BuildsWooStockPayload;` on the class.
- Path A (line ~84): change
    `$response = $woo->put("products/{$wooId}", ['status' => 'publish']);`
  to
    `$response = $woo->put("products/{$wooId}", array_merge(['status' => 'publish'], $this->wooStockPayload($product)));`
- Path B — buildCreatePayload() (line ~232): after the base `$payload = [...]` array is built (and
  before `return $payload;`), merge the stock keys:
    `$payload = array_merge($payload, $this->wooStockPayload($product));`
  (Leave the split-write of regular_price exactly as-is — stock is unaffected by the Cost-of-Goods
  plugin, so it stays in the initial POST.)

WooClient stub for tests: anonymous subclass that skips the parent constructor and overrides
put()/post() to record calls (mirror bindWooStub in tests/Feature/Console/BackfillCategoryFromWooCommandTest.php),
bound via app()->instance(WooClient::class, $stub). PublishProductJob is dispatched via
PublishProductJob::dispatchSync((int)$product->id, 0) OR constructed + handle() called — inspect the
job's constructor/handle signature. WOO_WRITE_ENABLED must be true in the test env for a real write
(config()->set('services.woo.write_enabled', true)) so put/post actually fire (else shadow mode).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: BuildsWooStockPayload trait + unit test</name>
  <files>
    app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php,
    tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php
  </files>
  <behavior>
    Trait per <interfaces>. Unit test via an anonymous class that uses the trait and exposes
    wooStockPayload() publicly; pass Product instances (unsaved is fine — new Product(['stock_quantity'=>..])):
      - stock_quantity=5, stock_status=null → [manage_stock:true, stock_quantity:5, stock_status:'instock']
      - stock_quantity=0 → stock_status:'outofstock'
      - stock_quantity=null → 0 + 'outofstock'
      - stock_quantity=-3 (defensive) → 0 + 'outofstock'
      - stock_quantity=0, stock_status='onbackorder' (valid WC value preserved) → stock_status:'onbackorder'
      - stock_quantity=5, stock_status='garbage' (invalid) → derived 'instock'
  </behavior>
  <action>
    Create the trait. Write the unit test (new Product([...]) needs stock_quantity/stock_status
    fillable — they are). Run + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php 2>&1 | tail -15</automated>
    Expected: GREEN.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Trait + 6 cases GREEN; pint clean.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: wire stock into PublishProductJob (both paths) + feature test</name>
  <files>
    app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php,
    tests/Feature/ProductAutoCreate/PublishProductStockTest.php
  </files>
  <behavior>
    Path A PUT and Path B buildCreatePayload both carry manage_stock/stock_quantity/stock_status per
    <interfaces>. Feature test (RefreshDatabase, write_enabled=true, WooClient stub recording calls):
      - Path A: seed a publish-status Product with woo_product_id=999 + stock_quantity=7 → run
        PublishProductJob → stub recorded a PUT to "products/999" whose body has status='publish',
        manage_stock=true, stock_quantity=7, stock_status='instock'.
      - Path B: seed a Product with woo_product_id=null + stock_quantity=0 → run PublishProductJob →
        stub recorded a POST to "products" whose body has manage_stock=true, stock_quantity=0,
        stock_status='outofstock'.
    (Assert the stock keys specifically; don't over-assert unrelated payload.)
  </behavior>
  <action>
    Add the trait use; edit Path A PUT and buildCreatePayload per <interfaces>. Write the feature test
    with a WooClient stub capturing ['method','path','body']. Run pint + the ProductAutoCreate suite.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/ProductAutoCreate/PublishProductStockTest.php 2>&1 | tail -20</automated>
    Expected: GREEN.
    <automated>grep -nE "wooStockPayload|BuildsWooStockPayload" app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php | head</automated>
    Expected: trait used + wooStockPayload merged into both Path A PUT and buildCreatePayload.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/ProductAutoCreate/ 2>&1 | tail -15</automated>
    Expected: GREEN (no regression to existing publish tests).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/ProductAutoCreate/Jobs/PublishProductJob.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Both publish paths send stock; feature test GREEN; ProductAutoCreate suite GREEN; pint clean; price/EAN logic unchanged.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php` → GREEN
2. `pest tests/Feature/ProductAutoCreate/PublishProductStockTest.php` → GREEN
3. `pest tests/Feature/ProductAutoCreate/` → GREEN (no regression)
4. `pint --test` on the trait + PublishProductJob → PASS

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- New auto-published products will now carry manage_stock + quantity + status → storefront shows the
  stock line like legacy products. To backfill EXISTING app-created products, re-publish them
  (`products:draft-from-suggestions --skus=… --auto-approve`) or a future one-off stock-push.
- FOLLOW-UP to verify: is stock kept fresh on Woo after publish (ongoing stock→Woo sync)? If not,
  displayed stock will freeze at publish-time qty — worth a separate check.
</verification>

<success_criteria>
- Published app-created products carry manage_stock=true + stock_quantity + stock_status → storefront shows a stock line like legacy products.
- Both publish paths (existing-draft PUT + create POST) send stock; single-source pure helper unit-tested.
- No change to price/EAN/description/split-write; ProductAutoCreate suite green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260701-opg-app-created-products-missing-woo-stock-s/260701-opg-SUMMARY.md` documenting:
- The gap (publish paths sent no stock keys → no storefront stock line on app products).
- The BuildsWooStockPayload trait + wiring into both PublishProductJob paths.
- Backfill note (re-publish existing app products) + the ongoing-stock-sync follow-up to verify.
</output>
