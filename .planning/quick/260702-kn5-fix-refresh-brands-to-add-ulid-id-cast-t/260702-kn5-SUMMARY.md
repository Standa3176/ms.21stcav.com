---
phase: 260702-kn5-fix-refresh-brands-to-add-ulid-id-cast-t
plan: 01
subsystem: suggestions / brands-to-add
tags: [bug-fix, ulid, type-coercion, tdd, console-command]
requires:
  - app/Console/Commands/RefreshBrandsToAddCommand.php (shipped 260702-h50)
  - app/Domain/Suggestions/Models/Suggestion.php (HasUlids)
provides:
  - "RefreshBrandsToAddCommand::indexSuggestionSkus() — pure (string) ULID-keyed row→sku map"
affects:
  - "products:refresh-brands-to-add walk (now all pending suggestions, not 1)"
  - "Suggestions Brand / Brand-on-Woo filters + /admin/brands-to-add page (populated after re-run)"
tech-stack:
  added: []
  patterns:
    - "Pure, unit-tested helper isolates a type-coercion boundary (mirrors 260626-fjg all-digit-SKU→int fix)"
key-files:
  created:
    - tests/Unit/Console/RefreshBrandsToAddWalkTest.php
  modified:
    - app/Console/Commands/RefreshBrandsToAddCommand.php
decisions:
  - "Key suggestions by (string) ULID id, never (int) — (int) '01ksace…' = 1 collapses the whole set onto key 1."
  - "Extract the row→sku mapping into a pure public indexSuggestionSkus() so a ULID-collapse regression is unit-guarded."
metrics:
  duration: ~10m
  completed: 2026-07-02
  tasks: 1
  files: 2
  tests_added: 3
---

# Phase 260702-kn5 Plan 01: Fix RefreshBrandsToAdd ULID-id (int)-cast collapse Summary

Fixed a shipped bug where `products:refresh-brands-to-add` walked only 1 of 8,826 pending
`new_product_opportunity` suggestions — the collect step cast the ULID string PK to `(int)`,
collapsing every id onto array key 1 — by keying on the `(string)` ULID id via a new pure,
unit-tested `indexSuggestionSkus()` helper.

## What Changed

### Root cause
`Suggestion` uses `HasUlids` (ULID string primary key, e.g. `01ksaceqj4vqc15t6gnr51yd54`).
The shipped chunk closure did `$suggestionSku[(int) $sug->id] = $sku`. In PHP,
`(int) '01ksace…'` evaluates to `1`, so **every** pending suggestion overwrote array key `1`
(last-write-wins → final count `1`). The walk therefore processed 1 suggestion, tagging tagged
0, and the Suggestions Brand / Brand-on-Woo filters + the `/admin/brands-to-add` page were empty.
Same type-coercion class as the earlier all-digit-SKU→int bug (260626-fjg).

### Fix — `app/Console/Commands/RefreshBrandsToAddCommand.php`
1. Added a pure PUBLIC method `indexSuggestionSkus(iterable $rows): array<string,string>` that
   maps rows to `[ (string) ULID id => sku ]`, keying by the FULL ULID string (never `(int)`),
   skipping rows with a blank `evidence.sku`, and handling `evidence` supplied as a JSON string
   OR an already-decoded array.
2. Rewired the chunk closure body to `$suggestionSku += $this->indexSuggestionSkus($rows);`
   (merges each chunk, preserving string keys).
3. Updated the `$suggestionSku` type docblock to `array<string, string>` ((string) ULID id => sku).

The tag loop is unchanged (`foreach ($suggestionSku as $id => $sku)`) — `$id` is now the ULID
string, so `Suggestion::find($id)` resolves the real row.

### Regression test — `tests/Unit/Console/RefreshBrandsToAddWalkTest.php` (new)
Tests via `app(RefreshBrandsToAddCommand::class)->indexSuggestionSkus([...])` with `stdClass` rows:
- Three DISTINCT ULID-shaped ids each with distinct JSON evidence → 3 entries keyed by the FULL
  ULID strings (asserts count === 3, **NOT 1** — the bug guard). Also asserts `(int)` on each id
  returns `1` to prove why string keying is required.
- A row with `{"sku":""}` (blank) → skipped.
- A row whose `evidence` is already an array → handled.

## Scope discipline
`buildBrandsToAddIndex`, `fetchManufacturers`, the mysqli walk, cache write, `--dry-run`, and the
schedule are untouched. Existing `tests/Unit/Console/BrandsToAddIndexTest.php` stays green.

## Verification
- `pest RefreshBrandsToAddWalkTest.php BrandsToAddIndexTest.php` → **6 passed (23 assertions)**
  (3 new walk tests + 3 pre-existing index tests).
- `grep -nE "\(int\) \$sug->id|indexSuggestionSkus"` → no `(int) $sug->id`; `indexSuggestionSkus`
  defined (line 171) + called (line 104).
- `pint --test` on the command → `{"result":"pass"}`; pint on the new test → `{"result":"pass"}`.

## Deviations from Plan
None - plan executed exactly as written (TDD RED → GREEN, no refactor needed).

## Operator re-run step (NOT run by Claude — no deploy/push done here)
1. Deploy: push `main` → on VPS `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh`.
2. Re-run `php artisan products:refresh-brands-to-add`. It should now report
   "Walking 8,826 pending suggestion(s)" (not 1) and "Tagged ~8,826", and cache the real
   brands-to-add list.
3. The Suggestions Brand / Brand-on-Woo filters then populate, and `/admin/brands-to-add` lists
   the real missing brands.

## Self-Check: PASSED
- FOUND: tests/Unit/Console/RefreshBrandsToAddWalkTest.php
- FOUND: .planning/quick/260702-kn5-fix-refresh-brands-to-add-ulid-id-cast-t/260702-kn5-SUMMARY.md
- FOUND commit: 352ce5f fix(260702-kn5): key refresh-brands-to-add suggestions by (string) ULID id
