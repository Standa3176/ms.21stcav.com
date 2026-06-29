---
phase: 260629-rct-draft-from-suggestions-pick-the-woo-bran
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Console/Commands/DraftFromSuggestionsCommand.php
  - tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php
must_haves:
  truths:
    - "When a SKU matches MULTIPLE supplier feed rows with different manufacturers, draft-from-suggestions picks the manufacturer that resolves to a Woo brand, instead of arbitrarily keeping the last-fetched row. So HD226 (BrightSign player + a 'Protect Plus' warranty row sharing MPN HD226) resolves to BrightSign and becomes a candidate, not skipped as 'Protect Plus brand not on Woo'."
    - "$supMap holds ALL manufacturers seen for each key (suppliersku/mpn), not a single last-wins value. The candidate loop iterates the SKU's manufacturers and selects the first whose resolveBrandKey() is non-null."
    - "If NONE of a SKU's manufacturers resolve to a Woo brand, it's still skipped as brand_not_on_woo and the skip report names a representative manufacturer (first one). The not_sourceable / no_manufacturer buckets are unchanged."
    - "Selection of brand-resolving manufacturer is a pure, unit-tested helper firstResolvableBrandKey(array $manufacturers, array<string,string> $wooBrandsByLower): array{0:?string,1:?string} returning [brandKey, matchedManufacturer] (both null if none resolve)."
    - "No regression to single-manufacturer SKUs (the common case): a one-element manufacturer list behaves exactly as before. Existing Console feature tests stay green."
  artifacts:
    - path: "app/Console/Commands/DraftFromSuggestionsCommand.php"
      provides: "Multi-manufacturer collection + brand-preferring selection in the chunk processor"
      contains: "firstResolvableBrandKey"
    - path: "tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php"
      provides: "Unit coverage: single mfr, multi with one resolvable (BrightSign vs Protect Plus), multi none-resolvable, empty"
      contains: "Protect Plus"
  key_links:
    - from: "DraftFromSuggestionsCommand chunk processor per-SKU loop"
      to: "firstResolvableBrandKey()"
      via: "pick the brand-resolving manufacturer among the SKU's feed rows"
      pattern: "firstResolvableBrandKey"
---

<objective>
Fix a real bug: products with an attached warranty/protection-plan feed row are wrongly skipped.

CONFIRMED on prod 2026-06-29: SKU HD226 matches TWO feeds_products rows under the same MPN:
  - BSHD226   | mfr=BrightSign   | stk=118  (the real product — BrightSign IS a Woo brand)
  - MSBSHD226 | mfr=Protect Plus | stk=0    (a warranty/protection plan — not a brand)
The chunk processor builds $supMap with `$supMap[$key] = $mfr` (LAST row wins), so HD226 ends up
mapped to "Protect Plus", which isn't a Woo brand → the SKU is skipped ("brand not on Woo") even
though it's a creatable, in-stock BrightSign product. Any product carrying a warranty/add-on row that
shares its MPN/SKU hits this.

FIX: collect ALL manufacturers per key, and in the candidate loop pick the FIRST manufacturer that
resolves to a Woo brand (via the existing resolveBrandKey from 260628-b9t). Reporting from 260629-pqh
is preserved (brand_not_on_woo only when NONE resolve). Selection-affecting but strictly an
improvement: it can only turn a wrongly-skipped SKU into a candidate; it never drops a SKU that
currently passes.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260629-rct-draft-from-suggestions-pick-the-woo-bran/
@CLAUDE.md
@app/Console/Commands/DraftFromSuggestionsCommand.php

<interfaces>
CURRENT (post 260628-b9t + 260629-pqh) chunk processor builds $supMap as key=>manufacturer (last
wins) in the SQL fetch loop, and the per-SKU loop does:
```php
$mfrLower = mb_strtolower($supMap[$key]);
$brandKey = $this->resolveBrandKey($mfrLower, $wooBrandsByLower);
```
$seenInFeed (260629-pqh) is already collected for all matched suppliersku/mpn.

CHANGE — fetch loop: make $supMap hold a LIST of manufacturers per key (append, dedup):
```php
$mfr = trim((string) $r['manufacturer']);
$seenInFeed[strtolower((string) $r['suppliersku'])] = true;
$seenInFeed[strtolower((string) $r['mpn'])] = true;
if ($mfr === '') { continue; }
foreach ([strtolower((string) $r['suppliersku']), strtolower((string) $r['mpn'])] as $k) {
    if ($k === '') { continue; }
    $supMap[$k] ??= [];
    if (! in_array($mfr, $supMap[$k], true)) { $supMap[$k][] = $mfr; }
}
```

CHANGE — per-SKU loop: replace the single-manufacturer resolve with the multi-manufacturer pick:
```php
$key = strtolower($sku);
$inFeed = isset($seenInFeed[$key]);
$mfrs = $supMap[$key] ?? [];          // array now
$hasMfr = $mfrs !== [];
[$brandKey, $matchedMfr] = $this->firstResolvableBrandKey($mfrs, $wooBrandsByLower);
$reason = $this->classifySkip($inFeed, $hasMfr, $brandKey !== null);
if ($reason !== null) {
    if ($reason === 'brand_not_on_woo') {
        $skips['brand_not_on_woo'][] = ($mfrs[0] ?? '?').' ('.$sku.')';   // representative mfr
    } else {
        $skips[$reason][] = $sku;
    }
    continue;
}
if ($brandsFilter !== null && ! in_array($brandKey, $brandsFilter, true)) {
    $skips['brand_filtered'][] = $sku;
    continue;
}
$canonical = $wooBrandsByLower[$brandKey];
$candidates[$sku] = $canonical;
$byBrand[$canonical][] = $sku;
```

NEW pure helper (public, unit-tested):
```php
/**
 * Pick the first manufacturer that resolves to a Woo brand.
 * Returns [brandKey, matchedManufacturer]; [null, null] if none resolve.
 * Handles the multi-row case (e.g. a product + a warranty/protection-plan row
 * sharing the same MPN) — prefer the real brand over a non-brand add-on label.
 *
 * @param  array<int,string>     $manufacturers
 * @param  array<string,string>  $wooBrandsByLower
 * @return array{0:?string,1:?string}
 */
public function firstResolvableBrandKey(array $manufacturers, array $wooBrandsByLower): array
{
    foreach ($manufacturers as $mfr) {
        $bk = $this->resolveBrandKey(mb_strtolower(trim((string) $mfr)), $wooBrandsByLower);
        if ($bk !== null) { return [$bk, $mfr]; }
    }
    return [null, null];
}
```
resolveBrandKey (260628-b9t) and classifySkip (260629-pqh) already exist on the class.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: firstResolvableBrandKey() helper + unit test (RED→GREEN)</name>
  <files>
    app/Console/Commands/DraftFromSuggestionsCommand.php,
    tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php
  </files>
  <behavior>
    Add firstResolvableBrandKey() per <interfaces>. Unit test (map ['brightsign'=>'BrightSign',
    'lindy'=>'Lindy']):
      - ['BrightSign'] → ['brightsign','BrightSign']                       (single, resolvable)
      - ['Protect Plus','BrightSign'] → ['brightsign','BrightSign']        (THE HD226 case — skips the non-brand, picks BrightSign)
      - ['BrightSign','Protect Plus'] → ['brightsign','BrightSign']        (order-independent: first resolvable wins)
      - ['Protect Plus'] → [null,null]                                     (none resolve → brand_not_on_woo)
      - ['Lindy - Cable'] → ['lindy','Lindy - Cable']                      (suffix-strip via resolveBrandKey still works)
      - [] → [null,null]
    Construct via app(DraftFromSuggestionsCommand::class); no DB touched.
  </behavior>
  <action>
    Add firstResolvableBrandKey() near resolveBrandKey/classifySkip. Write the unit test. Run + pint.
    (No wiring yet — pure helper first.)
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php 2>&1 | tail -15</automated>
    Expected: GREEN.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/DraftFromSuggestionsCommand.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - firstResolvableBrandKey() exists; 6 cases GREEN; pint clean.
  </done>
</task>

<task type="auto" tdd="false">
  <name>Task 2: collect all manufacturers + wire brand-preferring selection</name>
  <files>
    app/Console/Commands/DraftFromSuggestionsCommand.php
  </files>
  <behavior>
    $supMap becomes key=>list-of-manufacturers (append+dedup) in the fetch loop. The per-SKU loop uses
    firstResolvableBrandKey() to pick the brand-resolving manufacturer; classifySkip + skip buckets +
    candidate selection then behave per <interfaces>. HD226-style SKUs (product + warranty row) now
    resolve to the real brand and become candidates. Single-manufacturer SKUs behave exactly as before.
  </behavior>
  <action>
    Apply both CHANGE blocks from <interfaces> (fetch loop → manufacturer lists + $seenInFeed; per-SKU
    loop → firstResolvableBrandKey + classifySkip-driven buckets + candidate assignment). Ensure no
    other code reads $supMap as a string. Run pint + the Console feature suite.

    Manual sanity (document in SUMMARY, not executed here): post-deploy,
    `php artisan products:draft-from-suggestions --skus=HD226 --dry-run` should now print
    `Batch: 1 product(s) … BrightSign` (no longer skipped as Protect Plus).
  </action>
  <verify>
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test app/Console/Commands/DraftFromSuggestionsCommand.php 2>&1 | tail -5</automated>
    Expected: PASS.
    <automated>grep -nE "firstResolvableBrandKey|\\\$supMap\[\\\$k\]|in_array\(\\\$mfr" app/Console/Commands/DraftFromSuggestionsCommand.php | head</automated>
    Expected: $supMap built as lists (append/dedup), firstResolvableBrandKey used in the per-SKU loop.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Console/ tests/Unit/Console/ 2>&1 | tail -15</automated>
    Expected: GREEN (no regression; multi-mfr + skip-report + brand-suffix unit tests all pass).
  </verify>
  <done>
    - $supMap holds manufacturer lists; brand-preferring selection wired; HD226-style SKUs become candidates.
    - Single-manufacturer behaviour unchanged; full Console suite GREEN; pint clean.
  </done>
</task>

</tasks>

<verification>
1. `pest tests/Unit/Console/DraftFromSuggestionsMultiMfrTest.php` → GREEN
2. `pest tests/Feature/Console/ tests/Unit/Console/` → GREEN (no regression)
3. `pint --test app/Console/Commands/DraftFromSuggestionsCommand.php` → PASS

Operator notes (for SUMMARY.md, NOT executed by Claude):
- Deploy: push main, then on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
- Verify: `php artisan products:draft-from-suggestions --skus=HD226 --dry-run` → `Batch: 1 (BrightSign)`, no longer skipped. Then create it for real: `--skus=HD226 --auto-approve --no-confirm`.
- This fix also unblocks every other product that had a warranty/protection-plan row hijacking its brand — re-run a `--limit=0 --dry-run` to see the new (higher) creatable Batch total.
</verification>

<success_criteria>
- A SKU with multiple supplier manufacturers resolves to the Woo-brand one (BrightSign over Protect Plus); HD226 becomes a candidate.
- None-resolvable still reported as brand_not_on_woo; not_sourceable/no_manufacturer unchanged.
- Single-manufacturer SKUs unchanged; pure helper unit-tested; full Console suite green; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260629-rct-draft-from-suggestions-pick-the-woo-bran/260629-rct-SUMMARY.md` documenting:
- The warranty-row brand-hijack bug (HD226: Protect Plus row overwriting BrightSign) surfaced by the 260629-pqh skip report.
- $supMap → manufacturer-lists + firstResolvableBrandKey() brand-preferring selection.
- That it only turns wrongly-skipped SKUs into candidates (no SKU that passes today is dropped), and the operator verify + impact-recount steps.
</output>
