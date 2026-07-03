# Deferred items — 260703-rk3

Out-of-scope discoveries logged during execution (NOT fixed here).

## Pre-existing: `pest --filter=DraftFromSuggestions` aborts on a global-function redeclaration

Running `pest --filter=DraftFromSuggestions` loads the whole suite, which fatals BEFORE
any test runs:

```
PHP Fatal error: Cannot redeclare function bindWooStockStub()
  (previously declared in tests/Feature/Console/BackfillWooStockCommandTest.php:113)
  in tests/Feature/ProductAutoCreate/PublishProductStockTest.php on line 63
```

Two feature test files each declare a top-level `bindWooStockStub()` helper in their file
scope; Pest includes both, so the second redeclaration is fatal. Unrelated to this task's
files (DraftFromSuggestionsCommand / DraftFromSuggestionsIndexTest). Not touched.

Workaround used for verification: ran the four DraftFromSuggestions test files by explicit
path instead of `--filter` — all 28 tests pass. Fix (later): namespace/rename one of the
two `bindWooStockStub()` helpers or move it into a shared, once-guarded test helper.
