---
phase: 260708-bdw-fix-reconcile-woo-maintenance-stdclass-c
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Console/Commands/ReconcileWooMaintenanceCommand.php
  - tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
must_haves:
  truths:
    - "products:reconcile-woo-maintenance no longer crashes with 'Cannot use object of type stdClass as array'. WooClient::get('products',...) returns the Woo list as an ARRAY of stdClass objects (normaliseResponseBody returns it as-is because it's already a PHP array; only a top-level stdClass gets json-round-tripped to nested arrays). The command now casts each product to an array before reading it: `$p = (array) $p;` at the top of the per-product loop — robust whether the item is a stdClass (real Woo) or an array (future/other callers)."
    - "The feature test stub now returns stdClass product objects (matching the real WooClient list shape), so it reproduces the prod crash on the OLD code and passes on the fixed code — closing the test-vs-reality gap that let the bug ship."
    - "Reconciliation output is unchanged for a matched product (woo_image_count=count(images), woo_gtin=global_unique_id, woo_category_count=count(categories), woo_stock_status, woo_reconciled_at); still read-only on Woo; --dry-run still writes nothing."
  artifacts:
    - path: "app/Console/Commands/ReconcileWooMaintenanceCommand.php"
      provides: "stdClass-safe product access (cast each item to array)"
      contains: "(array) $p"
    - path: "tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php"
      provides: "stdClass fixture matching real Woo list shape"
      contains: "(object)"
  key_links:
    - from: "reconcile loop"
      to: "cast Woo list item stdClass → array before field access"
      via: "$p = (array) $p"
      pattern: "(array) $p"
---

<objective>
Fix the prod crash in products:reconcile-woo-maintenance: WooClient returns the Woo /products list as an
array of stdClass objects, but the command reads `$p['id']` (array syntax) → "Cannot use object of type
stdClass as array". Cast each item to an array; make the test stub return stdClass so it reflects real Woo
and guards the regression.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260708-bdw-fix-reconcile-woo-maintenance-stdclass-c/
@CLAUDE.md
@app/Console/Commands/ReconcileWooMaintenanceCommand.php
@app/Domain/Sync/Services/WooClient.php
@tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
---
WooClient::normaliseResponseBody returns the response as-is when it's already a PHP array (a list of
stdClass products), so list items are stdClass; only a single top-level stdClass gets recursively cast.
</context>

<interfaces>
=== ReconcileWooMaintenanceCommand — the per-product loop (~line 100) ===
Add a cast as the FIRST statement inside `foreach ($rows as $p) {`:
```php
foreach ($rows as $p) {
    $p = (array) $p;   // Woo list items are stdClass; cast so $p['id'] etc. work (no-op if already array)
    $wooId = (int) ($p['id'] ?? 0);
    ...
}
```
Everything else stays: `$p['images']` (after the cast, a value that count() accepts whether array or list of
stdClass), `$p['global_unique_id']`, `$p['categories']`, `$p['stock_status']`. `count((array) ($p['images'] ?? []))`
already tolerates a list of stdClass. No other change to the command.

=== Test — make the stub return stdClass (real Woo shape) ===
In the WooClient stub's get('products', …) fixture, return the product rows as OBJECTS, e.g.:
```php
return [
    (object) ['id' => 101, 'images' => [(object) [], (object) []], 'global_unique_id' => '50123', 'categories' => [(object) []], 'stock_status' => 'instock'],
    (object) ['id' => 102, 'images' => [], 'global_unique_id' => '', 'categories' => [], 'stock_status' => 'outofstock'],
];
```
(page >= 2 → []). Keep all existing assertions (101 → image_count 2 / gtin '50123' / category_count 1 /
stock 'instock' / reconciled_at set; 102 → 0/null/0/'outofstock'; --dry-run writes nothing; no Woo writes).
This stub shape now matches what WooClient actually returns, so it fails on the unfixed command and passes
on the fixed one.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: cast Woo list items to array + stdClass test fixture</name>
  <files>
    app/Console/Commands/ReconcileWooMaintenanceCommand.php,
    tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php
  </files>
  <behavior>
    First change the test stub to return stdClass product objects (per <interfaces>) and confirm it now
    REPRODUCES the crash on the current command (RED — "Cannot use object of type stdClass as array"). Then
    add `$p = (array) $p;` at the top of the loop (GREEN). All existing assertions must pass unchanged
    (101 → 2/'50123'/1/'instock'/reconciled; 102 → 0/null/0/'outofstock'; dry-run no-op; no Woo writes).
  </behavior>
  <action>
    Update the stub to objects; add the cast; run the test + pint.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php 2>&1 | tail -15</automated>
    Expected: GREEN with the stdClass stub (was RED before the cast).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/ReconcileWooMaintenanceCommand.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - Command casts each Woo list item to array (stdClass-safe); test stub is stdClass (matches real Woo) + all assertions green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Feature/Products/ReconcileWooMaintenanceCommandTest.php` → GREEN (with stdClass fixture)
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration — code only).
- Then re-run the reconciliation (now works): `php artisan products:reconcile-woo-maintenance --dry-run` then live,
  then the gap-count probe from the pass-1 verification to see the REAL shop-wide numbers.
- Root cause: WooClient returns the /products LIST as an array of stdClass objects (normaliseResponseBody
  returns already-array responses as-is; only a single top-level stdClass is json-round-tripped). Any future
  Woo LIST consumer must `(array)` each item (or WooClient could be changed to always deep-cast lists — a
  broader follow-up).
</verification>

<success_criteria>
- products:reconcile-woo-maintenance runs without the stdClass crash; the test stub matches the real Woo (stdClass) list shape and all assertions pass; reconciliation output unchanged; read-only; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260708-bdw-fix-reconcile-woo-maintenance-stdclass-c/260708-bdw-SUMMARY.md` documenting
the stdClass-list root cause (WooClient returns list items as objects), the (array) cast fix + the stdClass
test fixture that now guards it, and the operator re-run.
</output>
