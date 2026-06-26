---
phase: 260626-fjg-fix-backfill-category-from-woo-crash-on-
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Console/Commands/BackfillCategoryFromWooCommand.php
  - tests/Feature/Console/BackfillCategoryFromWooCommandTest.php
autonomous: true
requirements:
  - QUICK-260626-fjg
must_haves:
  truths:
    - "BackfillCategoryFromWooCommand binds the `sku` value in its DB::table('products')->where('sku', ...) UPDATE as a STRING, never an integer — even when the SKU is all-digits (e.g. '41074')."
    - "Root cause: PHP coerces all-digit array keys to int. $candidates is keyed by SKU; numeric SKUs become int keys, flow through array_chunk into the write loop as int $sku, and bind as an integer. MariaDB strict mode then numerically coerces the varchar `sku` column and throws SQLSTATE 22007 / error 1292 'Truncated incorrect DECIMAL value' on the first non-numeric SKU it scans."
    - "Fix: cast the chunk key back to string at the top of the inner write loop (`$sku = (string) $sku;`) so the WHERE binding, $updatedSkus list, and sample rows all carry string SKUs."
    - "Behaviour is otherwise byte-identical: a numeric-SKU product gets category_id + category_ids written exactly as a non-numeric one; all six existing outcome cases (A-F) stay green."
    - "Regression guard is portable to the SQLite test DB: the test asserts the captured UPDATE binding for sku is a PHP string (is_string), which fails on the int-coerced code and passes after the cast — without needing MariaDB strict mode to reproduce error 1292."
  artifacts:
    - path: "app/Console/Commands/BackfillCategoryFromWooCommand.php"
      provides: "String cast of the chunk key inside the write loop"
      contains: "$sku = (string) $sku;"
    - path: "tests/Feature/Console/BackfillCategoryFromWooCommandTest.php"
      provides: "Case G — numeric SKU writes correctly + sku binding is a string (DB::listen guard)"
      contains: "is_string"
  key_links:
    - from: "BackfillCategoryFromWooCommand inner write loop"
      to: "DB::table('products')->where('sku', (string) $sku)"
      via: "string cast of the array-chunk key before the UPDATE"
      pattern: "(string) \\$sku"
---

<objective>
Fix a production crash in `php artisan products:backfill-category-from-woo` (LIVE run, 2026-06-26).

OBSERVED (prod, DB stcav_meeting):
```
SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DECIMAL value: 'CQ68056'
SQL: update `products` set `category_id` = 12209, `category_ids` = [12209], `updated_at` = ...
     where `sku` = 41074
```

ROOT CAUSE:
`$candidates` (app/Console/Commands/BackfillCategoryFromWooCommand.php:118-127) is an associative
array keyed by SKU: `$candidates[$sku] = $wooId`. PHP **coerces all-digit string array keys to
integers**, so a SKU like "41074" is stored under int key 41074. `array_chunk(..., true)` preserves
those int keys, and the inner write loop `foreach ($chunk as $sku => $wooId)` (line 199) therefore
yields `$sku` as an **int** for numeric SKUs. The live write `DB::table('products')->where('sku',
$sku)` (line 245) then binds an integer.

`products.sku` is a varchar. MariaDB (prod, strict mode) numerically coerces the whole column to
compare against the int literal, and the first non-numeric SKU it scans ('CQ68056') throws error
1292. SQLite (the test DB) is loosely typed and never errors — so the existing 6-case suite stayed
green while prod crashed. (Same SQLite-vs-MariaDB divergence class as quick task 260626-d76 earlier
today.)

FIX: cast the chunk key back to a string at the top of the write loop. One line. Then add a
regression test that is catchable on SQLite by asserting the UPDATE's `sku` binding is a PHP string.

IMPACT / STATE: the crash was mid-run and non-transactional, so SKUs processed before the failing
chunk were written; the rest were not. The default candidate query filters `whereNull('category_id')`,
so a re-run after this fix resumes cleanly (already-written rows are excluded). No data corruption.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260626-fjg-fix-backfill-category-from-woo-crash-on-/
@CLAUDE.md
@app/Console/Commands/BackfillCategoryFromWooCommand.php
@tests/Feature/Console/BackfillCategoryFromWooCommandTest.php

<interfaces>
<!-- Key contracts the executor needs. Extracted from the codebase. -->

THE BUG SITE — inner write loop (app/Console/Commands/BackfillCategoryFromWooCommand.php:199):
```php
foreach ($chunk as $sku => $wooId) {
    if (! isset($lookup[$wooId])) {
        // ... woo_not_found
    }
    // ... builds $primary, $idList, $names ...
    DB::table('products')
        ->where('sku', $sku)          // <-- $sku is int 41074 for numeric SKUs
        ->update([
            'category_id' => $primary,
            'category_ids' => json_encode($idList),
            'updated_at' => now(),
        ]);
    $updated++;
    $updatedSkus[] = $sku;            // <-- also int; feeds --resync implode()
    // ... sample[] ...
}
```

TARGET — add ONE line as the FIRST statement inside the loop:
```php
foreach ($chunk as $sku => $wooId) {
    // PHP coerces all-digit array keys to int; $candidates is keyed by SKU,
    // so numeric SKUs arrive here as ints. `products.sku` is a varchar — an
    // int binding makes MariaDB (strict) numerically coerce the whole column
    // and throw error 1292 on the first non-numeric SKU. Cast back to string.
    $sku = (string) $sku;

    if (! isset($lookup[$wooId])) {
        // ... unchanged ...
```
This single cast fixes the WHERE binding, the $updatedSkus list, and the sample rows — all
downstream uses read $sku after the cast.

EXISTING TEST HARNESS (tests/Feature/Console/BackfillCategoryFromWooCommandTest.php) — REUSE:
- `bindWooStub(array $nextResponse): object` — binds an anonymous WooClient subclass returning the
  given product fixtures; records calls in `$stub->calls`.
- `bindWooStubThrowing(Throwable $e)` — stub that throws on get().
- Cases A-F already cover the outcome buckets. Add Case G after them.
- Product::factory()->create(['sku' => ..., 'woo_product_id' => ..., 'status' => 'publish',
  'category_id' => null]) is the established seed shape.

DB::listen capture pattern for the binding guard (works on SQLite):
```php
$bindings = [];
DB::listen(function ($q) use (&$bindings): void {
    if (str_starts_with(strtolower($q->sql), 'update') && str_contains($q->sql, 'products')) {
        $bindings[] = $q->bindings;
    }
});
// ... Artisan::call(...) ...
// The sku binding is the WHERE param — assert NONE of the update bindings is an int
// equal to the numeric SKU, and that a string '41074' binding is present.
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Add failing numeric-SKU regression test (RED)</name>
  <files>
    tests/Feature/Console/BackfillCategoryFromWooCommandTest.php
  </files>
  <behavior>
    New "Case G: numeric SKU binds as string and writes category_id" appended to the existing file.

    Seed two publish products with NULL category_id + a woo_product_id:
      - one with an ALL-DIGIT sku '41074' (woo_product_id 5001)
      - one with a non-numeric sku 'CQ68056' (woo_product_id 5002)
    bindWooStub returns categories for both ([{id:777,name:'Numeric Cat'}] and
    [{id:888,name:'Alpha Cat'}]).

    Register a DB::listen capture (per <interfaces>) BEFORE Artisan::call to collect bindings of
    every UPDATE touching `products`.

    Run live (no --dry-run, --no-confirm). Assert:
      1. DB::table('products')->where('sku','41074')->value('category_id') === 777 (behavioural).
      2. DB::table('products')->where('sku','CQ68056')->value('category_id') === 888.
      3. PORTABLE GUARD: across the captured UPDATE bindings, the sku binding for the numeric row
         is a PHP string — assert `collect($bindings)->flatten()->contains('41074')` AND assert
         NONE of the bindings is the integer 41074:
         `expect(collect($bindings)->flatten()->every(fn ($b) => $b !== 41074))->toBeTrue();`
         (Use strict `!==` so int 41074 is rejected but string '41074' passes.) This is the
         assertion that FAILS on the int-coerced code and PASSES after the (string) cast — and it
         does so on SQLite, without needing MariaDB strict mode.
  </behavior>
  <action>
    Append Case G to tests/Feature/Console/BackfillCategoryFromWooCommandTest.php, reusing
    bindWooStub and the Product::factory seed shape. Do NOT modify the command in this task.

    Run the file. Expectation 3 (the int-binding guard) MUST FAIL against the current command (the
    numeric SKU is bound as int 41074, so `every(... !== 41074)` is false). Expectations 1 and 2 may
    already pass on SQLite (loose typing writes correctly). Commit this RED state.

    NOTE on RRED reliability: if expectation 3 unexpectedly passes (e.g. the runner normalises
    bindings), strengthen it by also asserting the *type* directly: capture the specific sku binding
    (the last element of each update's bindings array) and assert at least one update has
    `is_int($binding) === true` for the numeric row in the unpatched code path. The canonical signal
    is: unpatched code binds int 41074; patched code binds string '41074'.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/BackfillCategoryFromWooCommandTest.php 2>&1 | tail -25</automated>
    Expected: Case G FAILS on the int-binding guard; Cases A-F stay green.
  </verify>
  <done>
    - Case G present, seeds a numeric and a non-numeric SKU.
    - Case G's binding guard fails against the unmodified command.
    - Command source untouched.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Cast chunk key to string in the write loop (GREEN)</name>
  <files>
    app/Console/Commands/BackfillCategoryFromWooCommand.php
  </files>
  <behavior>
    `$sku = (string) $sku;` added as the first statement inside `foreach ($chunk as $sku => $wooId)`
    (line 199), with the incident-anchored comment from <interfaces>. All Case G assertions GREEN;
    Cases A-F unchanged and GREEN.
  </behavior>
  <action>
    Insert the cast + comment as the first line of the inner write loop at line 199. Change nothing
    else. Run the test file — all 7 cases (A-G) GREEN. Run pint on the file.
  </action>
  <verify>
    <automated>vendor/bin/pest tests/Feature/Console/BackfillCategoryFromWooCommandTest.php 2>&1 | tail -15</automated>
    Expected: 7/7 GREEN.

    <automated>grep -n "(string) \$sku" app/Console/Commands/BackfillCategoryFromWooCommand.php</automated>
    Expected: one match inside the write loop (~line 200).

    <automated>vendor/bin/pint --test app/Console/Commands/BackfillCategoryFromWooCommand.php 2>&1 | tail -5</automated>
    Expected: PASS. If not, run `vendor/bin/pint app/Console/Commands/BackfillCategoryFromWooCommand.php` and re-verify.
  </verify>
  <done>
    - The (string) $sku cast is the first statement in the write loop, with the incident comment.
    - 7/7 BackfillCategoryFromWooCommandTest GREEN.
    - pint clean.
  </done>
</task>

</tasks>

<verification>
1. `vendor/bin/pest tests/Feature/Console/BackfillCategoryFromWooCommandTest.php` → 7/7 GREEN
2. `grep -n "(string) \$sku" app/Console/Commands/BackfillCategoryFromWooCommand.php` → 1 match
3. `vendor/bin/pint --test app/Console/Commands/BackfillCategoryFromWooCommand.php` → PASS
4. Full Pest delta vs baseline: +1 pass / 0 new fails

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Re-run the backfill after deploy — it resumes safely (whereNull('category_id') excludes the rows
  already written before the crash):
  `sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan products:backfill-category-from-woo'`
- Then refresh the audit snapshot so /admin/category-audit reflects the fix:
  `sudo -u stcav bash -c 'cd /home/stcav/ms.21stcav.com && php artisan products:audit-categories'`
</verification>

<success_criteria>
- products:backfill-category-from-woo no longer crashes on numeric SKUs under MariaDB strict mode.
- The sku WHERE binding is always a string.
- New regression test is portable (catches the int binding on SQLite); existing A-F cases unaffected.
- One-line root-cause fix; no behavioural change for non-numeric SKUs.
</success_criteria>

<output>
Create `.planning/quick/260626-fjg-fix-backfill-category-from-woo-crash-on-/260626-fjg-SUMMARY.md` when done, documenting:
- The PHP array-key int-coercion root cause + the MariaDB strict-mode 1292 manifestation.
- Why the existing suite missed it (SQLite loose typing) and how Case G's binding guard closes the gap on SQLite.
- The safe-resume property of the re-run (whereNull('category_id')).
- Deploy + re-run + audit-refresh operator steps.
- Sibling reference to 260626-d76 (same SQLite-vs-MariaDB divergence class, fixed earlier today).
</output>
