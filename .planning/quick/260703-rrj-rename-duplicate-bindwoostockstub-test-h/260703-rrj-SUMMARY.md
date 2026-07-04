# 260703-rrj — Rename duplicate `bindWooStockStub` test helper

## Problem
The global Pest helper `bindWooStockStub` was declared in TWO test files with different signatures/bodies:
- `tests/Feature/Console/BackfillWooStockCommandTest.php` — `bindWooStockStub(?int $throwForWooId = null)`
- `tests/Feature/ProductAutoCreate/PublishProductStockTest.php` — `bindWooStockStub(array $putResponse = [], array $postResponse = [])`

Two different functions sharing one global name → running the full `pest` suite (no filter) fataled with
`Cannot redeclare bindWooStockStub()`. This had blocked full-suite runs and forced the last four executors
to verify per-file.

## Fix (pure rename — no behaviour change)
Renamed each to a unique, purpose-descriptive name (matching the codebase's per-file-prefixed helper
convention — `bindRetagWooStub`, `hn1BindWooStub`, `bindWooReconcileStub`, …):
- `BackfillWooStockCommandTest`: `bindWooStockStub` → **`bindBackfillWooStockStub`** (declaration + 4 call sites)
- `PublishProductStockTest`: `bindWooStockStub` → **`bindPublishWooStockStub`** (declaration + 5 call sites; `bindLiveStockResolver` untouched)
- `PublishDraftsCommandTest`: updated the line-32 doc comment referencing the old name (comment only; it uses its own `hn1BindWooStub`)

## Verification
- `grep -rn bindWooStockStub tests` → **0** (old name fully gone)
- The three previously-clashing files run **together in one Pest process**: **18 passed (82 assertions)**, NO redeclare fatal — the regression is fixed.
- `pint --test` on all three files → `{"result":"pass"}`

## Notes
- Test-helper rename only; no app code touched, nothing to deploy.
- Recovery note: the executor completed the edits but was interrupted before running tests + committing; verified + committed manually (edits were complete and correct — old name at 0 matches, new names at expected counts).
- Any remaining full-suite failures are the pre-existing ~ProductAutoCreate test-infra items noted in earlier tasks' deferred-items, unrelated to this rename.
