---
phase: 260701-ssf-fix-buildswoostockpayload-derive-stock-s
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php
  - tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php
must_haves:
  truths:
    - "wooStockPayload derives stock_status from quantity when manage_stock is on: qty>0 => instock, qty<=0 => outofstock. It NEVER emits a contradictory qty=0 + instock (oversell risk) even when the product's local stock_status says 'instock'."
    - "The ONLY preserved local status is 'onbackorder' — a genuine backorder can be sellable at qty 0; any other local value (instock/outofstock/blank/garbage) is ignored in favour of the qty-derived status."
    - "manage_stock stays true and stock_quantity stays max(0,(int)stock_quantity) — unchanged. This fixes the backfill dry-run rows that showed qty=0 + instock, and improves new-product publishes (PublishProductJob) the same way since both use this trait."
  artifacts:
    - path: "app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php"
      provides: "qty-authoritative stock_status derivation, onbackorder preserved"
      contains: "onbackorder"
    - path: "tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php"
      provides: "qty=0+local-instock -> outofstock, qty>0+local-outofstock -> instock, onbackorder preserved"
      contains: "outofstock"
  key_links:
    - from: "wooStockPayload()"
      to: "stock_status derived from stock_quantity"
      via: "onbackorder preserved, else qty>0?instock:outofstock"
      pattern: "onbackorder"
---

<objective>
Fix a stock-status inconsistency caught in the backfill dry-run: BuildsWooStockPayload preserved the
product's local stock_status even when it contradicted the quantity, producing payloads like
qty=0 + stock_status=instock (e.g. 7090043790993, PULSE-PRO-BOARD1). Under manage_stock=true WC likely
overrides status from qty, but sending qty=0+instock risks a 0-stock product displaying in-stock
(oversell) if WC honours it. Make quantity authoritative: qty>0 => instock, qty=0 => outofstock; only
preserve a genuine 'onbackorder'. Same trait powers PublishProductJob + products:backfill-woo-stock,
so both are corrected. Tiny, additive-safe change.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260701-ssf-fix-buildswoostockpayload-derive-stock-s/
@CLAUDE.md
@app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php
@tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php

<interfaces>
CURRENT wooStockPayload() derivation (the bug — preserves any valid local status):
```php
$qty = max(0, (int) ($product->stock_quantity ?? 0));
$status = (string) ($product->stock_status ?? '');
if (! in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
    $status = $qty > 0 ? 'instock' : 'outofstock';
}
```
TARGET (qty authoritative; only onbackorder preserved):
```php
$qty = max(0, (int) ($product->stock_quantity ?? 0));
// manage_stock=true makes quantity authoritative — derive status from it so we
// never emit qty=0 + instock (oversell risk). Preserve only a genuine
// 'onbackorder' (sellable at qty<=0 when backorders are allowed).
$status = ((string) ($product->stock_status ?? '')) === 'onbackorder'
    ? 'onbackorder'
    : ($qty > 0 ? 'instock' : 'outofstock');
```
manage_stock/stock_quantity keys unchanged. Update the method docblock line about status being
"reconciled by WC" to note status is now derived from quantity (onbackorder excepted).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: qty-authoritative stock_status + test update</name>
  <files>
    app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php,
    tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php
  </files>
  <behavior>
    Trait derivation replaced per <interfaces>. Update the existing unit test cases and add the
    bug-fix cases so it asserts the qty-authoritative rule:
      - qty=5, stock_status=null   -> instock
      - qty=0, stock_status=null   -> outofstock
      - qty=null                   -> 0 + outofstock
      - qty=-3                     -> 0 + outofstock
      - qty=0, stock_status='onbackorder' -> onbackorder  (preserved)
      - qty=5, stock_status='garbage'     -> instock       (derived)
      - qty=0, stock_status='instock'     -> outofstock    (NEW — the bug: qty wins, no oversell)
      - qty=5, stock_status='outofstock'  -> instock        (NEW — qty wins both directions)
      - qty=5, stock_status='onbackorder' -> onbackorder   (preserved even with stock)
    (Any prior case that asserted a contradictory local status was preserved must be updated to the
    qty-derived expectation.)
  </behavior>
  <action>
    Apply the TARGET derivation + docblock tweak. Update/add the unit-test cases above. Run + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php 2>&1 | tail -20</automated>
    Expected: GREEN (incl. qty=0+instock -> outofstock).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/ProductAutoCreate/PublishProductStockTest.php tests/Feature/Console/BackfillWooStockCommandTest.php 2>&1 | tail -15</automated>
    Expected: GREEN — consumers still pass (their seeded products use consistent qty/status; if any asserted a contradictory expectation, correct it to the qty-derived value).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Domain/ProductAutoCreate/Concerns/BuildsWooStockPayload.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Status derived from qty (onbackorder excepted); no qty=0+instock possible; unit + consumer tests GREEN; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Unit/Domain/ProductAutoCreate/BuildsWooStockPayloadTest.php` → GREEN
2. `pest tests/Feature/ProductAutoCreate/PublishProductStockTest.php tests/Feature/Console/BackfillWooStockCommandTest.php` → GREEN
3. `pint --test` on the trait → PASS

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Re-run the backfill dry-run — qty=0 rows now read `outofstock` (no more qty=0 + instock). Then apply:
  `php artisan products:backfill-woo-stock --dry-run` then without --dry-run.
</verification>

<success_criteria>
- No payload ever has qty=0 + instock; stock_status follows quantity (onbackorder excepted).
- Unit test covers the qty-wins cases; PublishProductStock + BackfillWooStock tests still green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260701-ssf-fix-buildswoostockpayload-derive-stock-s/260701-ssf-SUMMARY.md` documenting the qty-authoritative status fix (caught in the backfill dry-run), the onbackorder exception, and that it corrects both the backfill and new-product publishes.
</output>
