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
