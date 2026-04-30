# Phase 10 Deferred Items

Items found during Plan 10-01 execution that are out-of-scope for the
PricingAgent skeleton work but should be tracked.

## PolicyTemplateIntegrityTest — Shield {{ Placeholder }} leak in app/Policies/RolePolicy.php

**Discovered:** Plan 10-01 Task 1 verification (running architecture suite)
**Status:** Pre-existing failure (file was already in `M` state before Plan 10-01 began — see initial `git status --short` capture)
**Test:** `tests/Architecture/PolicyTemplateIntegrityTest.php` test 1 — "no Policy file contains a Shield {{ Placeholder }} literal (Pitfall P2-H)"
**File:** `app/Policies/RolePolicy.php` lines 66, 74, 82, 90, 98, 106 — Shield-generated stubs `'{{ ForceDelete }}'`, `'{{ ForceDeleteAny }}'`, `'{{ Restore }}'`, `'{{ RestoreAny }}'`, `'{{ Replicate }}'`, `'{{ Reorder }}'`

**Why deferred:** Pre-existing un-staged modification in the repository at the start of Plan 10-01 execution. Not caused by Plan 10-01 changes (PricingAgent + 5 tool stubs + EchoAgent deletion). RolePolicy.php is unrelated to Phase 10 scope. Per execution scope-boundary policy, out-of-scope failures are logged here rather than fixed inline.

**Recommended fix:** Resolve the pre-existing `M app/Policies/RolePolicy.php` modification per the `shield:safe-regenerate` workflow (Phase 8 Plan 05) — replace the 6 placeholder strings with real permission slugs (`force_delete_role`, `force_delete_any_role`, `restore_role`, `restore_any_role`, `replicate_role`, `reorder_role`). Track via a separate hot-fix plan or roll into the next phase touching admin policies.

## PinnedFieldsSurviveSyncTest — SQLite test-infra gaps (3 failures)

**Discovered:** Plan 10-02 verification suite (running architecture tests against SQLite local-dev DB; MySQL `meetingstore_ops_testing` was offline)
**Status:** Pre-existing test-infrastructure issue — failures are environmental (SQLite + Mockery final-class), not regression
**Test:** `tests/Architecture/PinnedFieldsSurviveSyncTest.php` — 3 of its tests require either MySQL JSON column behaviour or full migration coverage
**Failures observed under SQLite:**
1. `it AUTO-10: pinned title + short_description survives sync` — `SQLSTATE[HY000]: General error: 1 table product_overrides has no column named pin_price`
2. `it AUTO-10: unpinned product is overwritten naturally` — `Mockery: The class \App\Foundation\Audit\Services\Auditor is marked final and its methods cannot be replaced`
3. `it AUTO-10: pin revert failure is logged` — same SQLite missing-column error

**Why deferred:** These failures are caused by running tests against SQLite (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`) instead of the canonical MySQL `meetingstore_ops_testing` DB. The test suite was authored against MySQL where the migration includes the `pin_price` column. Plan 10-02 changes (4 tool implementations + 5 unit tests + 1 architecture test) are wholly unrelated to product_overrides / sync / pinned fields.

**Recommended fix:** Bring up MySQL on `127.0.0.1:3306` and re-run the architecture suite — failures should clear. Or refactor the test to skip cleanly when the schema is incomplete (sqlite portability). Either way: out of Phase 10 scope.
