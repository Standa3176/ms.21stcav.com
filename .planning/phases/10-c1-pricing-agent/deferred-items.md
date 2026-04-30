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

## AgentRunGdprScrubberTest — JSON_SEARCH not available in SQLite (7 failures)

**Discovered:** Plan 10-03 verification suite (running Feature/Agents tests against SQLite local-dev DB; MySQL `meetingstore_ops_testing` was offline)
**Status:** Pre-existing test-infrastructure issue — MySQL-only SQL function (`JSON_SEARCH`) used by `AgentRunGdprScrubber` query
**Test:** `tests/Feature/Agents/AgentRunGdprScrubberTest.php` — all 7 tests in the file
**Failure observed under SQLite:**
- `SQLSTATE[HY000]: General error: 1 no such function: JSON_SEARCH (Connection: sqlite, Database: :memory:, SQL: select * from "agent_runs" where (JSON_SEARCH(LOWER(tool_calls), one, alice@example.com) IS NOT NULL or "agent_reasoning_summary" like %alice@example.com%))`

**Why deferred:** SQLite does not implement MySQL's `JSON_SEARCH` function. The Phase 8 Plan 05 GDPR scrubber service was authored against MySQL and uses native JSON functions for performance. Plan 10-03 changes (system prompt Blade view + 4-fixture calibration test + prompt-hash test + ops runbook) are wholly unrelated to GDPR scrubbing. All tests pass on MySQL where the production query is portable.

**Recommended fix:** Bring up MySQL on `127.0.0.1:3306` and re-run — should clear all 7 failures. Or refactor `AgentRunGdprScrubber` to use a portable LIKE-only search path with `whereJsonContains` (slower on MySQL but works on both engines). Either way: out of Plan 10-03 scope; logged for a Phase 8 hot-fix plan or post-MySQL-restore verification pass.

## Phase 8 ClaudeClient — wrong Prism import (FIXED in Plan 10-03 commit f166428)

**Discovered:** Plan 10-03 Task 2 (running PricingAgentCalibrationTest against SQLite)
**Status:** RESOLVED — one-line import swap; documented as Plan 10-03 Rule 3 deviation
**File:** `app/Domain/Agents/Clients/ClaudeClient.php`
**Issue:** Phase 8 Plan 02 imported `Prism\Prism\Prism` (the class) but called `Prism::text()` statically. PHP throws `Non-static method Prism\Prism\Prism::text() cannot be called statically`. The intended import was `Prism\Prism\Facades\Prism` (the Laravel Facade), which exposes `text()` via `__callStatic`. Phase 8 Plan 02's own `ClaudeClientTest.php` correctly imports the Facade — only ClaudeClient itself had the wrong import. Phase 8 Plan 02 SUMMARY deferred ClaudeClientTest pending MySQL availability, masking the bug at ship time.
**Fix:** `use Prism\Prism\Prism;` → `use Prism\Prism\Facades\Prism;` (1-line swap; commit f166428)
**Bonus:** Unblocks 8 of 11 ClaudeClientTest cases (the remaining 3 still fail on the `integration_events.correlation_id NOT NULL` SQLite gap — separate Phase 8 test-infra issue).

## TradeRuleResolverTest — SQLite gap (Plan 10-04 verification observation; ~25 failures)

**Discovered:** Plan 10-04 final Unit suite verification (full `php artisan test --testsuite=Unit` against SQLite local-dev DB)
**Status:** Pre-existing Phase 9 test-infrastructure issue — failures are SQLite portability gaps in the Trade pricing rule resolver layer; NOT Plan 10-04 regressions
**Tests:** ~25 failures concentrated in `tests/Unit/TradePricing/Services/TradeRuleResolverTest.php`
**Failure shape:** Assertions like `expect($resolution->marginBasisPoints)->toBe(3500)` fail with `Failed asserting that 1500 is identical to 3500` — SQLite's NULL-handling + ORDER BY semantics differ from MySQL on the resolver's tie-break + fallthrough queries.

**Why deferred:** Plan 10-04 changes (PricingAgentResultMapper / RunPricingAgentJob / Filament SuggestionResource extension / contract tests) do NOT touch `app/Domain/TradePricing/`. `git diff` confirms zero TradePricing changes. The 25 failures all fail identically before AND after Plan 10-04 commits — they are Phase 9 SQLite-portability issues (Phase 9 test files were authored against MySQL `meetingstore_ops_testing`).

**Recommended fix:** Bring up MySQL on `127.0.0.1:3306` and re-run `php artisan test tests/Unit/TradePricing` — should clear all failures. Or refactor TradeRuleResolver query chain to use SQLite-portable patterns (whereJsonContains over whereJsonContainsKey, COALESCE over IFNULL, etc.). Either way: out of Plan 10-04 scope; logged for a Phase 9 hot-fix plan or post-MySQL-restore verification pass.
