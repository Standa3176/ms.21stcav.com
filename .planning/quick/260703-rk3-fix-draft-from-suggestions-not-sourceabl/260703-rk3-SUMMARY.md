---
phase: 260703-rk3-fix-draft-from-suggestions-not-sourceabl
plan: 01
subsystem: autocreate
tags: [supplier-feed, draft-from-suggestions, char-padding, sourceable, tdd]
requires:
  - supplier_products (remote supplier_db; suppliersku/mpn are space-padded CHAR columns)
  - supplier_sku_cache (LOWER(TRIM()) keyed — the "sourceable" filter truth this now agrees with)
provides:
  - DraftFromSuggestionsCommand::indexSupplierRows() — pure LOWER(TRIM()) row→(seen,mfrs) indexer
affects:
  - products:draft-from-suggestions (padded-SKU false-negative "not sourceable" fixed)
tech-stack:
  added: []
  patterns:
    - "Extract impure inline keying into a pure, unit-tested helper so a padding regression can't recur (same class as 260609-rie stockseparate + 260606-c4o env guards)"
key-files:
  created:
    - tests/Unit/Console/DraftFromSuggestionsIndexTest.php
  modified:
    - app/Console/Commands/DraftFromSuggestionsCommand.php
decisions:
  - "Only the key-building changed (add trim + extract to pure helper). classifySkip, firstResolvableBrandKey, --brands filter, the per-SKU loop, and generate-drafts/assign-taxonomy/auto-publish chaining all left untouched."
metrics:
  duration: ~20m
  completed: 2026-07-03
  tasks: 1
  tests-added: 4
  commits: 2
---

# Phase 260703-rk3 Plan 01: Fix draft-from-suggestions padded-SKU false-negative Summary

Space-padded CHAR supplier-feed keys (`mpn='49XE4F-M            '`) now trim to match the trimmed
evidence SKU, so padded-SKU products classify sourceable instead of being wrongly skipped — via a new
pure, unit-tested `indexSupplierRows()` helper (LOWER+TRIM keys).

## What & Why (root cause)

`products:draft-from-suggestions` reported `not_sourceable` (and skipped) for SKUs whose
`supplier_products` `mpn`/`suppliersku` is a **space-padded CHAR column** — e.g. `49XE4F-M` stored as
`'49XE4F-M            '`.

- The SQL `WHERE suppliersku IN (...) OR mpn IN (...)` **matched the row** — MariaDB ignores trailing
  spaces on CHAR comparisons.
- The "On supplier DB: sourceable" Filament filter (`supplier_sku_cache`, built with `LOWER(TRIM())`)
  **showed it sourceable**.
- But the PHP-side chunk closure built its in-feed membership set (`$seenInFeed`) and manufacturer map
  (`$supMap`) with `strtolower()` **but no `trim()`**. So the padded feed value keyed as
  `'49xe4f-m            '` (untrimmed), while the per-SKU loop looks it up under `strtolower($sku)` =
  `'49xe4f-m'` (the intake SKU is `trim()`'d at line ~161). The padded key never equalled the trimmed
  lookup key → `$inFeed = false` → `classifySkip()` returned `not_sourceable` → skipped.

Net: the two "sourceable" definitions disagreed. `supplier_sku_cache` (LOWER+TRIM) said yes; the command
(LOWER only) said no. This fix makes the command's keying mirror the cache's.

## The fix

Extracted the row→(seen,mfrs) indexing out of the inline `while` loop into a new **pure public** method:

```php
public function indexSupplierRows(iterable $rows): array  // ['seen'=>..., 'mfrs'=>...]
```

- Keys are `strtolower(trim(...))` of `suppliersku` and `mpn` — LOWER **and** TRIM.
- `seen[$k]=true` for every non-empty key (feeds `classifySkip`'s in-feed / not_sourceable distinction).
- `mfrs[$k][]` appends + dedups the trimmed `manufacturer` (preserves the 260629-rct multi-manufacturer
  "prefer the brand-resolving row" behaviour); blank manufacturer → skipped (feeds the `no_manufacturer`
  path); empty key → never added (no `''` key).

Chunk closure rewired to:

```php
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
['seen' => $seenInFeed, 'mfrs' => $supMap] = $this->indexSupplierRows($rows);
```

Being pure (no mysqli/DB), a padding regression is now guarded by a unit test.

## What was NOT changed (by design)

`classifySkip`, `firstResolvableBrandKey`, `promoteMissingBrand`, the `--brands` filter, the per-SKU
classification loop (already `$key = strtolower($sku)` on the trimmed intake SKU — now hits the trimmed
feed keys), and the generate-drafts / assign-taxonomy / source-images / auto-publish chaining — all
untouched.

## Tests

New `tests/Unit/Console/DraftFromSuggestionsIndexTest.php` (constructs the command via the container,
calls `indexSupplierRows()` directly — pure, no DB), 4 cases:

1. **The 49XE4F-M guard** — padded row `['suppliersku'=>'CC99220     ', 'mpn'=>'49XE4F-M            ',
   'manufacturer'=>'LG ELECTRONICS      ']` → `seen['49xe4f-m']` true AND `seen['49xe4f-m            ']`
   **false**; `mfrs['49xe4f-m'] === ['LG ELECTRONICS']` (trimmed).
2. Blank-manufacturer padded row → `seen` key present, key **absent** from `mfrs`.
3. Two rows sharing an mpn with different manufacturers → both collected in `mfrs[mpn]`.
4. Empty suppliersku/mpn → no `''` key created.

RED-confirmed (4 failed: `indexSupplierRows` did not exist) → GREEN after implementing.

## Verification (Herd php: `~/.config/herd/bin/php84/php.exe`)

- `pest tests/Unit/Console/DraftFromSuggestionsIndexTest.php` → **4 passed (12 assertions)**.
- All DraftFromSuggestions tests by path (Unit BrandMatch + SkipReport + MultiMfr + Feature CreateBrand +
  new Index) → **28 passed (44 assertions), 0 regressions**.
- `pint --test app/Console/Commands/DraftFromSuggestionsCommand.php tests/Unit/Console/DraftFromSuggestionsIndexTest.php`
  → `{"result":"pass"}`.

**Confirmed:** the padded `'49XE4F-M            '` row now keys as `'49xe4f-m'` (Case 1 asserts both the
trimmed key present and the padded key absent).

## Deviations from Plan

None — plan executed exactly as written (RED → GREEN, no refactor needed). Only key-building changed;
nothing else touched.

### Out-of-scope discovery (logged, NOT fixed)

`pest --filter=DraftFromSuggestions` cannot run: it loads the whole suite, which fatals on a
**pre-existing, unrelated** global-function redeclaration — `bindWooStockStub()` declared in both
`tests/Feature/Console/BackfillWooStockCommandTest.php` and
`tests/Feature/ProductAutoCreate/PublishProductStockTest.php`. Not related to this task's files. Logged to
`deferred-items.md`; verified instead by running the four DraftFromSuggestions files by explicit path (all
28 green). Fix later: rename/namespace one of the two helpers.

## Commits

- `5abdc9f` — test(260703-rk3): add failing indexSupplierRows padded-key guard (RED)
- `7649bd0` — fix(260703-rk3): trim supplier feed keys via pure indexSupplierRows() (GREEN)

Local only — NOT pushed, NOT deployed.

## Operator re-verify (post-deploy — NOT run by Claude)

1. Push `main` → VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` (no migration).
2. `php artisan products:draft-from-suggestions --skus="49XE4F-M,SPV3301-FX-02,02864-001,E103164,IFP6553,461252,43UH5Q-E,65UH5Q-E,SPV1302-02,461641" --create-missing-brands --dry-run`
   → expect a **"Batch: N product(s)"** line now (not "No matching SKUs / 10 not sourceable").
3. Re-run the Auto-create (Source images + Auto-publish) on those rows — they should create this time.

Related follow-ups still owed (separate): surface skip reasons in the Suggestions UI; harden
`RunAutoCreatePipelineJob`.

## Self-Check: PASSED

- FOUND: app/Console/Commands/DraftFromSuggestionsCommand.php (indexSupplierRows present)
- FOUND: tests/Unit/Console/DraftFromSuggestionsIndexTest.php
- FOUND commit: 5abdc9f (test/RED)
- FOUND commit: 7649bd0 (fix/GREEN)
