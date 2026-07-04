---
phase: 260703-rrj-rename-duplicate-bindwoostockstub-test-h
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - tests/Feature/Console/BackfillWooStockCommandTest.php
  - tests/Feature/ProductAutoCreate/PublishProductStockTest.php
  - tests/Feature/Console/PublishDraftsCommandTest.php
must_haves:
  truths:
    - "The global test helper bindWooStockStub is no longer declared twice. It was defined in BOTH BackfillWooStockCommandTest (signature (?int $throwForWooId)) AND PublishProductStockTest (signature (array $putResponse, array $postResponse)) — two different functions sharing one global name — so loading the full Pest suite fataled with 'Cannot redeclare bindWooStockStub()'. Each is renamed to a unique, purpose-descriptive name matching the codebase's per-file-prefixed convention (bindRetagWooStub, hn1BindWooStub, bindWooReconcileStub, …)."
    - "BackfillWooStockCommandTest's helper → bindBackfillWooStockStub (declaration + its 4 call sites). PublishProductStockTest's helper → bindPublishWooStockStub (declaration + its 5 call sites). No behaviour change — pure rename; each file's tests still pass standalone AND together in one run."
    - "The full Pest suite (pest with NO filter) no longer aborts on the bindWooStockStub redeclare — it collects and runs. (Other pre-existing, unrelated failures in the suite are out of scope and NOT introduced by this change.)"
  artifacts:
    - path: "tests/Feature/Console/BackfillWooStockCommandTest.php"
      provides: "renamed bindBackfillWooStockStub"
      contains: "bindBackfillWooStockStub"
    - path: "tests/Feature/ProductAutoCreate/PublishProductStockTest.php"
      provides: "renamed bindPublishWooStockStub"
      contains: "bindPublishWooStockStub"
  key_links:
    - from: "two same-named global helpers"
      to: "two uniquely-named helpers"
      via: "rename declarations + all in-file call sites"
      pattern: "bindPublishWooStockStub"
---

<objective>
The global Pest helper bindWooStockStub is declared in TWO test files with different signatures/bodies, so
running the full suite (pest, no filter) fatals with "Cannot redeclare bindWooStockStub()". This has blocked
full-suite runs and forced the last four executors to run per-file. Rename each to a unique name (matching
the existing per-file-prefixed helper convention). Pure mechanical rename — no behaviour change.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/quick/260703-rrj-rename-duplicate-bindwoostockstub-test-h/
@CLAUDE.md
@tests/Feature/Console/BackfillWooStockCommandTest.php
@tests/Feature/ProductAutoCreate/PublishProductStockTest.php
@tests/Feature/Console/PublishDraftsCommandTest.php
</context>

<interfaces>
Two declarations to rename (confirmed unique names — nothing else in tests/ collides):

1. tests/Feature/Console/BackfillWooStockCommandTest.php
   - line 113: `function bindWooStockStub(?int $throwForWooId = null): object`  →  `function bindBackfillWooStockStub(?int $throwForWooId = null): object`
   - call sites to update: lines 33, 57, 73, 94 (`$stub = bindWooStockStub(...)` → `bindBackfillWooStockStub(...)`; keep the `throwForWooId:` named arg on line 73).

2. tests/Feature/ProductAutoCreate/PublishProductStockTest.php
   - line 63: `function bindWooStockStub(array $putResponse = [], array $postResponse = []): object`  →  `function bindPublishWooStockStub(array $putResponse = [], array $postResponse = []): object`
   - call sites to update: lines 108, 138, 177, 213, 241 (keep the `putResponse:` / `postResponse:` named args).
   - `bindLiveStockResolver` in this file is unique — DO NOT touch it.

3. tests/Feature/Console/PublishDraftsCommandTest.php
   - line 32 is a COMMENT referencing "PublishProductStockTest's bindWooStockStub" — update the comment text to `bindPublishWooStockStub` for accuracy. It is NOT a call; no functional change. (PublishDraftsCommandTest uses its own hn1BindWooStub — leave that.)

Do a final grep to be sure no other file references `bindWooStockStub`.
</interfaces>

<tasks>

<task type="auto" tdd="false">
  <name>Task 1: rename the two clashing bindWooStockStub helpers</name>
  <files>
    tests/Feature/Console/BackfillWooStockCommandTest.php,
    tests/Feature/ProductAutoCreate/PublishProductStockTest.php,
    tests/Feature/Console/PublishDraftsCommandTest.php
  </files>
  <behavior>
    Rename per <interfaces> (decl + all call sites, preserving named args). Update the PublishDrafts comment.
    No test logic changes.
  </behavior>
  <action>
    Apply the renames. `grep -rn bindWooStockStub tests` must return ZERO matches afterwards. Run the three
    affected files TOGETHER (one process — this is the real regression: they must coexist), then a broad
    suite run to confirm the redeclare fatal is gone.
  </action>
  <verify>
    <automated>cd "/c/Users/sonny.tanda/Documents/1 - Laravel Projects/meetingstore-ops-app" && grep -rn "bindWooStockStub" tests | wc -l</automated>
    Expected: 0 (no reference to the old name remains).
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest tests/Feature/Console/BackfillWooStockCommandTest.php tests/Feature/ProductAutoCreate/PublishProductStockTest.php tests/Feature/Console/PublishDraftsCommandTest.php 2>&1 | tail -15</automated>
    Expected: GREEN — the three files run together with NO "Cannot redeclare" fatal.
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pest 2>&1 | tail -25</automated>
    Expected: the full suite COLLECTS + RUNS with no bindWooStockStub redeclare fatal. (Pre-existing unrelated failures may remain — note the pass/fail tally in SUMMARY but do NOT chase them; success here = the redeclare is gone and the suite runs to completion.)
    <automated>~/.config/herd/bin/php84/php.exe vendor/bin/pint --test tests/Feature/Console/BackfillWooStockCommandTest.php tests/Feature/ProductAutoCreate/PublishProductStockTest.php tests/Feature/Console/PublishDraftsCommandTest.php 2>&1 | tail -5</automated>
    Expected: PASS.
  </verify>
  <done>
    - No `bindWooStockStub` name remains; the three files run together without redeclare; full pest suite runs to completion (redeclare fatal gone); pint clean.
  </done>
</task>

</tasks>

<verification>
1. `grep -rn bindWooStockStub tests` → 0
2. `pest <the 3 files together>` → GREEN, no redeclare
3. `pest` (full, no filter) → runs to completion (redeclare gone; record the tally)
4. `pint --test` → PASS

Operator notes (for SUMMARY.md):
- Pure test-helper rename; no app code touched, nothing to deploy.
- Full `pest` (no filter) now runs — if it still reports OTHER failures, those are the pre-existing ~ProductAutoCreate test-infra items noted in earlier tasks' deferred-items, unrelated to this rename.
</verification>

<success_criteria>
- bindWooStockStub is gone (renamed to bindBackfillWooStockStub + bindPublishWooStockStub); the previously-clashing files coexist in one Pest run; the full suite no longer aborts on the redeclare; pint clean.
</success_criteria>

<output>
Create `.planning/quick/260703-rrj-rename-duplicate-bindwoostockstub-test-h/260703-rrj-SUMMARY.md` documenting
the duplicate-global-helper root cause, the two renames + call-site updates, and the full-suite-now-runs result
(with the pass/fail tally + a note that any remaining failures are pre-existing/unrelated).
</output>
