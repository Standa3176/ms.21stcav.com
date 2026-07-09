---
phase: 260709-vvj-two-small-fixes-retagproductsonwoo-dry-r
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Console/Commands/RetagProductsOnWooCommand.php
  - app/Domain/ProductAutoCreate/Services/FieldPinManager.php
  - app/Domain/Products/Filament/Resources/ProductResource.php
  - tests/Feature/RetagProductsOnWooCommandTest.php
  - tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php
must_haves:
  truths:
    - "FIX #6 — RetagProductsOnWooCommand's `while (true) { query page=1 }` loop now tracks processed Woo product IDs across iterations and breaks when a page-1 result yields NO new IDs. In LIVE mode it already drained (products move off ?brand=N after each PUT); in DRY-RUN mode there are no PUTs so the filter set never shrank → the loop spun to the 50-iteration $pageGuard emitting the same products up to 50× (phantom `would_retag` rows). With processed-id dedup it drains cleanly in BOTH modes — a dry-run over N products emits exactly N plan rows."
    - "FIX #4 — the Product pin-tab can now pin the price: `pin_price` is added to FieldPinManager::PIN_COLUMNS (was a hardcoded 8-list missing it) AND a `Toggle::make('override_pins.pin_price')->label('Pin price')` is added to ProductResource's pin section. The ProductOverride model already has pin_price fillable+cast (gy0), and ProductOverrideGuard already maps regular_price→pin_price — so this closes the loop: an operator can now set pin_price in the UI and it persists + is honoured by the sync guard."
    - "Both are small, contained fixes. #6 changes only the pagination loop's termination (no change to what gets retagged in live mode). #4 is additive (a 9th pin toggle + column). Driver-portable; no migration."
  artifacts:
    - path: "app/Console/Commands/RetagProductsOnWooCommand.php"
      provides: "processed-id dedup loop termination (dry-run no longer loops)"
      contains: "processedIds"
    - path: "app/Domain/ProductAutoCreate/Services/FieldPinManager.php"
      provides: "pin_price in PIN_COLUMNS"
      contains: "pin_price"
    - path: "app/Domain/Products/Filament/Resources/ProductResource.php"
      provides: "Pin price toggle"
      contains: "pin_price"
  key_links: []
---

<objective>
Two small owed fixes: (#6) stop RetagProductsOnWoo's dry-run producing phantom repeated rows, and (#4) expose the
pin_price toggle in the Product pin-tab so the gy0 pin_price column is actually settable end-to-end.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260709-vvj-two-small-fixes-retagproductsonwoo-dry-r/
@CLAUDE.md
@app/Console/Commands/RetagProductsOnWooCommand.php
@app/Domain/ProductAutoCreate/Services/FieldPinManager.php
@app/Domain/Products/Filament/Resources/ProductResource.php
@app/Domain/Pricing/Models/ProductOverride.php
@tests/Feature/RetagProductsOnWooCommandTest.php
@tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php
---
Verified:
- RetagProductsOnWooCommand (~line 190): `$pageGuard=0; $maxPageGuard=50; while (true) { get('products', [brand, per_page,
  page=>1, status=>any]); ... foreach ($response as $row) { retag ... } $pageGuard++; if ($pageGuard>=50) break; }`.
  The ONLY termination for dry-run is the 50-guard → phantom rows (memory: brand-cleanup-followups item #6).
- FieldPinManager::PIN_COLUMNS = [pin_title, pin_short_description, pin_long_description, pin_meta_description,
  pin_image, pin_slug, pin_brand, pin_category] (8) — used in BOTH the load loop (~line 51) and savePins (~line 91).
  Missing pin_price. Docblock says "8 pin_* toggle" — update to 9.
- ProductResource pin-tab (~lines 146-153): 8 Toggle::make('override_pins.pin_X') entries, NO pin_price.
- ProductOverride model: pin_price already in $fillable + $casts (bool) + the pin-list array (gy0).
</context>

<interfaces>
=== #6 — RetagProductsOnWooCommand processed-id dedup ===
In the per-source `while (true)` loop, track processed Woo product ids and break when the page-1 result introduces
none (mirrors the memory's prescribed fix):
```php
$processedIds = [];              // before the while(true)
while (true) {
    $response = $this->woo->get('products', [... page=>1, status=>'any', brand=>$sourceId ...]);   // unchanged
    if (! is_array($response) || $response === []) { break; }

    $newRows = array_filter($response, function ($row) use (&$processedIds) {
        $id = (int) (is_array($row) ? ($row['id'] ?? 0) : ($row->id ?? 0));
        return $id > 0 && ! isset($processedIds[$id]);
    });
    if ($newRows === []) { break; }   // drained — every product on this page already handled (dry-run exit; live exit if a PUT failed to move a product)

    foreach ($newRows as $row) {
        // ... existing stdClass cast + retag/plan logic, PLUS: mark processed
        $processedIds[$id] = true;
    }
    // keep the $pageGuard safety backstop as-is
}
```
Preserve everything else (status=any, page=1, the 404/error handling, the auditor calls, dry-run vs live branch).
The key change: iterate `$newRows` not `$response`, and record `$processedIds` so a page that repeats fully → clean break.

=== #4 — pin_price toggle ===
- FieldPinManager::PIN_COLUMNS: add `'pin_price'` (making it 9); update the docblock "8"→"9".
- ProductResource pin section: add (near the other pins, logical spot — next to price/brand)
  `Toggle::make('override_pins.pin_price')->label('Pin price'),`
- No model change needed (ProductOverride already has pin_price). The load loop + savePins iterate PIN_COLUMNS so
  pin_price now round-trips automatically.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: dry-run dedup loop + pin_price toggle</name>
  <files>
    app/Console/Commands/RetagProductsOnWooCommand.php,
    app/Domain/ProductAutoCreate/Services/FieldPinManager.php,
    app/Domain/Products/Filament/Resources/ProductResource.php,
    tests/Feature/RetagProductsOnWooCommandTest.php,
    tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php
  </files>
  <behavior>
    Apply #6 + #4 per <interfaces>.
    #6 test (extend RetagProductsOnWooCommandTest): a DRY-RUN over a source whose Woo stub returns the SAME 3
    products on every page=1 call produces exactly 3 plan rows (NOT 150) and terminates without hitting the
    50-guard. Keep the existing live-mode retag/pagination cases green.
    #4 test (extend ProductResourcePinTabTest): saving with override_pins.pin_price=true persists
    ProductOverride.pin_price=true (via FieldPinManager); and the load path hydrates it back. Keep the existing
    pin-tab cases green.
  </behavior>
  <action>
    Implement both. Run the two test files + pint on the 3 changed source files.
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/RetagProductsOnWooCommandTest.php tests/Feature/ProductAutoCreate/ProductResourcePinTabTest.php 2>&1 | tail -15</automated>
    Expected: GREEN — dry-run emits N rows not 50×N; pin_price persists + hydrates; existing cases pass.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint app/Console/Commands/RetagProductsOnWooCommand.php app/Domain/ProductAutoCreate/Services/FieldPinManager.php app/Domain/Products/Filament/Resources/ProductResource.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - RetagProductsOnWoo dry-run drains via processed-id dedup (no phantom rows, both modes); pin_price is a working pin-tab toggle (PIN_COLUMNS + UI + persists); tests green; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest` the two files → GREEN
2. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Deploy: push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (NO migration).
- brands:retag-products-on-woo --dry-run now shows an accurate plan (was repeating each product up to 50×).
- The Product pin tab now has a "Pin price" toggle — setting it makes supplier sync leave that product's price
  alone (the ProductOverrideGuard regular_price→pin_price mapping was already wired; only the UI toggle + the
  FieldPinManager column list were missing).
</verification>

<success_criteria>
- RetagProductsOnWoo dry-run terminates via processed-id dedup (exact row count, no phantom 50× repeats) with live mode unchanged; pin_price is a functioning pin-tab toggle (PIN_COLUMNS + UI + round-trips); both tests green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260709-vvj-two-small-fixes-retagproductsonwoo-dry-r/260709-vvj-SUMMARY.md` documenting the dry-run dedup fix (both-mode drain) and the pin_price toggle (PIN_COLUMNS + UI + persistence), with the tests.
</output>