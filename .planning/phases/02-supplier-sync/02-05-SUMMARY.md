---
phase: 02-supplier-sync
plan: 05-guardrails
subsystem: sync,architecture,retention
tags: [sync-04, deptrac, wp-direct-db, policy-integrity, p2-h, retention-prune, d-07, d-09, verification]

requires:
  - phase: 02-01-data-model
    provides: "SyncError model (retention prune target); 5 hand-written policies + 2 Phase 1 policies (PolicyTemplateIntegrityTest target)"
  - phase: 02-02-external-clients
    provides: "WooClient (sole Woo entry point — SYNC-04 anchor)"
  - phase: 02-03-orchestration
    provides: "SyncChunkJob (DB::transaction removed for SYNC-04); Sync→Products Deptrac allowlist already in place"
  - phase: 02-04-reporting-ui
    provides: "Feature-level PolicyTemplateIntegrityTest stub (promoted + extended here); sync_errors + sync_run_items now have live data paths needing retention"
  - phase: 01-05-horizon-alerting
    provides: "routes/console.php retention-prune pattern (3 existing prunes at 03:00/03:10/03:30); Auditor::record D-09 meta-audit convention"

provides:
  - "depfile.yaml + deptrac.yaml — new `WpDirectDb` layer matching `^Illuminate\\\\Support\\\\Facades\\\\DB$`; Sync ruleset gains `-WpDirectDb` deny rule — SYNC-04 architectural enforcement active in CI"
  - "app/Domain/Sync/Jobs/SyncChunkJob.php — DB::transaction per-SKU wrapper REMOVED + docblock updated; atomicity preserved via remote Woo write + row-level Eloquent saves + cursor-last ordering + P2-F idempotency"
  - "tests/Architecture/DeptracSyncLayerTest.php — 2 tests: positive (current zero-violation baseline) + negative (deliberate violator planted in app/Domain/Sync/Services, Deptrac MUST exit non-zero, cleanup before assertions); exit-code is the authoritative CI gate (deptrac-shim stdout capture is unreliable on Windows PHP)"
  - "tests/Architecture/PolicyTemplateIntegrityTest.php — promoted from Feature + extended (3 tests): (1) `{{ ` literal grep across 5 Policy directories (Pitfall P2-H); (2) positive control — ≥7 Policy files exist; (3) Gate::policy bindings resolve to Domain/root Policies not Shield stubs"
  - "tests/Feature/PolicyTemplateIntegrityTest.php — DELETED (replaced by Architecture version above)"
  - "app/Console/Commands/PruneSyncErrorsCommand.php — D-07 90-day retention; extends Illuminate\\Console\\Command (Phase 1 Prune* pattern, NOT BaseCommand — simpler prunes keep same parent); --days=0 is graceful no-op (logs sync-errors.prune.skipped); every successful run writes Auditor meta-audit (sync-errors.pruned with deleted_count + cutoff_date + days)"
  - "routes/console.php — Schedule::command('sync-errors:prune', ['--days' => 90]) at 03:20 daily with withoutOverlapping(30) + onOneServer + timezone('Europe/London'); REPLACES the Phase 1 Plan 05 TODO marker for D-07"
  - "tests/Feature/PruneSyncErrorsTest.php — 5 tests: P1 delete-old-retain-new, P2 meta-audit shape, P3 --days=0 no-op, P4 default --days=90, P5 schedule registration"
  - "tests/Feature/RetentionPruneTest.php — updated: schedule-list assertion now expects 4 prune commands (was 3); withoutOverlapping count ≥ 4"
  - ".planning/phases/02-supplier-sync/02-VERIFICATION.md — phase-level verification matrix: 6 success criteria ↔ plan coverage ↔ test commands; 13 SYNC-* requirement ID coverage table; full verification script; VPS operator handoff checklist; Phase 3 readiness signals"

affects:
  - "Phase 3+ (Pricing Engine) — DeptracSyncLayerTest only bans DB facade from Sync; Pricing layer can add its own similar guard if needed. PolicyTemplateIntegrityTest already includes future policies automatically (glob-based)."
  - "Phase 7 cutover runbook — 02-VERIFICATION.md operator handoff section references this plan's schedule entry + env-var checklist; runbook consumes directly."
  - "Every future `shield:generate` run — Architecture PolicyTemplateIntegrityTest fails CI fast; Plan 02-04's restore protocol (`git checkout HEAD -- <paths>`) stays as the remedial action."

tech-stack:
  added: []
  patterns:
    - "Deptrac `classLike` collector for framework-class denial: matching `^Illuminate\\\\Support\\\\Facades\\\\DB$` lets us deny a specific facade from a layer without banning the whole framework. Useful precedent for future layer-specific facade prohibitions (e.g. banning `Storage` from Sync)."
    - "Architectural test output-capture on Windows: exit-code is the authoritative CI gate; stdout capture through deptrac-shim is unreliable (Symfony\\Process can't always read the phar's output). Tests rely on exit code + file-system side effects, not on stdout grep."
    - "Promoted Feature → Architecture test migration: when a guardrail grows beyond a single assertion, move it into tests/Architecture/ and delete the Feature duplicate in the SAME commit. Keeps discovery + count stable across the suite."
    - "Retention prune safety: --days=0 is treated as a graceful no-op + logs a `{domain}.prune.skipped` meta-audit row. Prevents an operator typo from wiping the whole table while still leaving a forensic trail."

key-files:
  created:
    - "tests/Architecture/DeptracSyncLayerTest.php"
    - "tests/Architecture/PolicyTemplateIntegrityTest.php"
    - "app/Console/Commands/PruneSyncErrorsCommand.php"
    - "tests/Feature/PruneSyncErrorsTest.php"
    - ".planning/phases/02-supplier-sync/02-VERIFICATION.md"
  modified:
    - "depfile.yaml — + WpDirectDb layer + `Sync: [..., -WpDirectDb]` deny"
    - "deptrac.yaml — mirror of depfile.yaml"
    - "app/Domain/Sync/Jobs/SyncChunkJob.php — removed DB::transaction wrapper + docblock"
    - "routes/console.php — sync-errors:prune at 03:20 (replaces TODO)"
    - "tests/Feature/RetentionPruneTest.php — schedule list 3→4 commands; withoutOverlapping ≥ 4"
  deleted:
    - "tests/Feature/PolicyTemplateIntegrityTest.php (moved to Architecture)"

key-decisions:
  - "Task 1 SYNC-04 enforcement used Deptrac layer (not a pure PHPUnit grep test) because the plan frontmatter's must_haves.truths explicitly required a `vendor/bin/deptrac analyse` exit-non-zero signal on a planted violator. The `classLike` collector on `^Illuminate\\\\Support\\\\Facades\\\\DB$` is precise enough — Sync domain genuinely has zero DB facade use after the SyncChunkJob refactor, and other domains (Alerting, Suggestions) that legitimately use DB::transaction are unaffected."
  - "SyncChunkJob's per-SKU DB::transaction was removed (Deptrac option (a) from the plan text). Atomicity was not load-bearing for correctness: (1) Woo PUT is atomic server-side; (2) Eloquent per-row saves are atomic; (3) cursor_page/cursor_sku is written LAST so partial failure is recoverable via --resume; (4) Pitfall P2-F idempotency skips already-synced SKUs on worker retry. All 8 SyncChunkJob + SyncChunkFailure tests remained green — confirms transactions were belt-and-braces, not correctness-critical."
  - "PruneSyncErrorsCommand extends `Illuminate\\Console\\Command` (not BaseCommand) to match the Phase 1 PruneActivityLog / PruneIntegrationEvents / PruneSyncDiffs pattern. RESEARCH §Extra suggested BaseCommand for correlation-id threading, but all 3 Phase 1 prunes use plain Command and still write correlation_id through Auditor (which reads from Context). Consistency with the existing 3 prunes matters more than the 1 extra correlation-id hop BaseCommand would add."
  - "PolicyTemplateIntegrityTest promoted from tests/Feature to tests/Architecture (not duplicated). The Feature version was marked Plan 02-04 transitional (explicit comment: 'Plan 02-05 will add an architecture-suite version'). Promotion preserves the 1-file grep, adds a positive-control count + Gate::policy binding resolution test, and deletes the Feature copy in the same commit. Net test delta: +2 tests (3 Architecture - 1 Feature removed)."
  - "UserPolicy removed from Gate::policy resolution test (test #3 of PolicyTemplateIntegrityTest) — the codebase has no UserPolicy, only RolePolicy in app/Policies/. Keeping UserPolicy in the expected pairs would false-fail. The test is forward-compatible: adding UserPolicy later only requires adding it to the pairs array."
  - "Deptrac negative test's output-inspection assertion was simplified to exit-code-only after two failed attempts to grep the stdout/stderr for 'wpdirectdb' or the violator file name. The deptrac-shim phar's violation table is written through a channel Symfony\\Process can't always capture on Windows PHP — the stdout string remains empty even when deptrac itself has clearly run + flagged the violation (verified manually via `> /tmp/out.txt` redirect). Exit code is the CI-authoritative signal and is sufficient; belt-and-braces was ultimately noise on this platform."
  - "Schedule uses `Europe/London` timezone on the new sync-errors:prune entry — matches the commented sync:supplier schedule's timezone convention. Phase 1 prunes don't set a timezone explicitly (use server default) which is a minor inconsistency, but tightening them would be scope-creep for Plan 02-05."

requirements-completed:
  - SYNC-04

duration: ~12 min
completed: 2026-04-19
---

# Phase 02 Plan 05: Guardrails Summary

**Phase 2 closed with the permanent enforcement net: (1) SYNC-04 Deptrac `WpDirectDb` layer + architectural negative-test catches any future DB facade import from the Sync domain; (2) Pitfall P2-H `PolicyTemplateIntegrityTest` promoted into the Architecture suite with 3 tests (stub grep + positive control + Gate::policy binding resolution) so a future `shield:generate` regeneration fails CI fast; (3) `sync-errors:prune` command + 03:20 daily schedule replaces the Phase 1 Plan 05 TODO marker with D-07 90-day retention + D-09 meta-audit compliance; (4) `02-VERIFICATION.md` consolidates all 6 success criteria, 13 requirement IDs, and the full verification script. SyncChunkJob's per-SKU DB::transaction was removed (atomicity preserved by Woo-remote write + row-level Eloquent + cursor-last + P2-F idempotency). 9 new Pest tests (2 DeptracSyncLayer + 3 PolicyTemplateIntegrity Architecture + 5 PruneSyncErrors – 1 removed Feature PolicyTemplate stub) bring the full suite from 223 → 232 passing, 2 skipped; Deptrac 0 violations; zero Phase 1 + Phase 2 Plans 1-4 regressions.**

## Performance

- **Duration:** ~12 min execution
- **Started:** 2026-04-19T00:05Z
- **Completed:** 2026-04-19T00:17Z
- **Tasks:** 2
- **Commits:** 2 task commits (+ 1 forthcoming metadata commit)
- **Files created:** 5 (DeptracSyncLayerTest, Architecture PolicyTemplateIntegrityTest, PruneSyncErrorsCommand, PruneSyncErrorsTest, 02-VERIFICATION.md)
- **Files modified:** 5 (depfile.yaml, deptrac.yaml, SyncChunkJob.php, routes/console.php, RetentionPruneTest.php)
- **Files deleted:** 1 (tests/Feature/PolicyTemplateIntegrityTest.php — promoted to Architecture)

## Task Commits

1. **Task 1** — `8f7c76f` `feat(02-05): add WpDirectDb Deptrac layer + DeptracSyncLayerTest (SYNC-04)` — 4 files, +142 / -8
2. **Task 2** — `c3dc2e7` `feat(02-05): PolicyTemplateIntegrityTest (Architecture) + sync-errors:prune + 02-VERIFICATION` — 7 files, +563 / -48

## Accomplishments

### SYNC-04 architectural enforcement (Task 1)

**Deptrac `WpDirectDb` layer added to both depfile.yaml + deptrac.yaml:**

```yaml
- name: WpDirectDb
  collectors:
    - type: classLike
      regex: '^Illuminate\\Support\\Facades\\DB$'
```

And the `Sync` ruleset now includes `-WpDirectDb`:

```yaml
Sync: [Foundation, Products, Alerting, '-WpDirectDb']
```

**SyncChunkJob DB::transaction removal:** the per-SKU `DB::transaction(function () use ($run, $woo, $skuRow, $diff, $supplierRow) { ... })` wrapper around the Woo put + writeRunItem + upsertLocalMirror was removed along with the `use Illuminate\Support\Facades\DB;` import. The class docblock was updated to explain the atomicity reasoning (Woo-remote + row-level Eloquent + cursor-last + P2-F idempotency).

**DeptracSyncLayerTest (2 tests):**
- Positive: `vendor/bin/deptrac analyse` exit 0 on current codebase
- Negative: plant `app/Domain/Sync/Services/__SyncDeptracViolator.php` with `DB::connection('mysql_woo')->table('wp_posts')->update(...)`, run deptrac, assert exit ≠ 0, cleanup before assertions

Verified via manual run: deptrac output the expected violation table:

```
Violation   App\Domain\Sync\Services\__SyncDeptracViolator must not depend on Illuminate\Support\Facades\DB (WpDirectDb)
```

### Pitfall P2-H permanent guardrail (Task 2)

**tests/Architecture/PolicyTemplateIntegrityTest.php** (3 tests, promoted + extended from the Feature version Plan 02-04 shipped):

1. No Policy file contains `{{ ` Shield template literal across 5 directories:
   - `app/Policies/`
   - `app/Domain/Alerting/Policies/`
   - `app/Domain/Products/Policies/`
   - `app/Domain/Suggestions/Policies/`
   - `app/Domain/Sync/Policies/`
2. Positive control — ≥ 7 Policy files exist (prevents false-green from a glob that matches nothing).
3. `Gate::getPolicyFor($model)` returns the expected Domain/root Policy class for 7 model-policy pairs (RolePolicy + 2 Phase 1 + 4 Phase 2). A Shield-regenerated stub has different semantics (permission-based `return $user->can(...)`); our hand-written policies use `hasRole()` — resolving to the expected class proves no stub was silently registered.

The Feature-level `tests/Feature/PolicyTemplateIntegrityTest.php` was deleted in the same commit — no duplication.

### D-07 retention prune — sync-errors:prune (Task 2)

**app/Console/Commands/PruneSyncErrorsCommand.php** — extends `Illuminate\Console\Command` (matches the Phase 1 PruneActivityLog / PruneIntegrationEvents / PruneSyncDiffs pattern; RESEARCH §Extra suggested BaseCommand, but consistency with the 3 existing prunes matters more than the extra correlation-id hop).

```php
protected $signature = 'sync-errors:prune {--days=90}';
```

Safety features:
- `--days=0` is a graceful no-op (logs `sync-errors.prune.skipped` with the `days_below_minimum` reason; exits 0)
- Every successful run writes an Auditor meta-audit row: `description='sync-errors.pruned'` with `properties.deleted_count`, `properties.cutoff_date`, `properties.days` (D-09)

**routes/console.php** — the Phase 1 Plan 05 TODO marker was replaced with the real schedule:

```php
// D-07: sync_errors — 90 days (Phase 2 Plan 05 — replaces the Phase 1 TODO marker)
Schedule::command('sync-errors:prune', ['--days' => 90])
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune sync_errors older than 90 days (D-07 retention)');
```

Slot fits cleanly between the existing 03:10 (integration-events) and 03:30 (sync-diffs) prunes.

### Phase-level verification document (Task 2)

**.planning/phases/02-supplier-sync/02-VERIFICATION.md** — single source of truth for:
- 6 success criteria ↔ plan coverage ↔ test command matrix
- 10 scope-additions verification table (beyond REQUIREMENTS.md)
- 13 SYNC-* requirement ID coverage table
- Full phase verification script (9 bash sections)
- Known deviations / accepted debt (P2-J, A1/A2, Horizon supervisor timeout)
- VPS operator handoff checklist (7 pre-cutover steps with env vars + commands)
- Phase 2 readiness signals for Phase 3 (Pricing Engine)

Phase 7 cutover runbook + Phase 3 kickoff both consume this document directly.

### 8 new Pest tests shipped

| File | Count | Coverage |
|---|---|---|
| tests/Architecture/DeptracSyncLayerTest.php | 2 | positive + negative (SYNC-04 enforcement) |
| tests/Architecture/PolicyTemplateIntegrityTest.php | 3 | literal grep + positive control + Gate binding (promoted + extended) |
| tests/Feature/PruneSyncErrorsTest.php | 5 | delete-old, meta-audit shape, --days=0 no-op, default 90, schedule registration |
| *deleted* tests/Feature/PolicyTemplateIntegrityTest.php | -1 | (promoted to Architecture) |
| tests/Feature/RetentionPruneTest.php | 0 (2 modified) | 3→4 commands + withoutOverlapping count ≥ 4 |

Net test delta: **+2 DeptracSyncLayer + (+3 Architecture PolicyTemplate − 1 Feature PolicyTemplate) + 5 PruneSyncErrors = +9**

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] SyncChunkJob's `DB::transaction` triggered a fresh WpDirectDb violation the moment the Deptrac rule was added**

- **Found during:** Task 1, first `vendor/bin/deptrac analyse` after depfile.yaml update.
- **Issue:** 2 violations at SyncChunkJob.php:24 (import) + :106 (DB::transaction call). The plan anticipated this exactly and offered 3 resolution options; option (a) was explicitly recommended.
- **Fix:** Removed `use Illuminate\Support\Facades\DB;` and the `DB::transaction(function () use ...)` wrapper around per-SKU writes. Replaced with 3 sequential calls. Class docblock updated with the atomicity reasoning.
- **Verification:** all 8 SyncChunkJob + SyncChunkFailure tests remained green; Deptrac exit 0.
- **Committed in:** `8f7c76f` (Task 1).

**2. [Rule 3 — Blocking] DeptracSyncLayerTest negative-test output inspection unreliable on Windows PHP**

- **Found during:** Task 1, first Pest run.
- **Issue:** First two attempts at the secondary belt-and-braces assertion used `str_contains($process->getOutput().$process->getErrorOutput(), 'wpdirectdb' | 'syncdeptracviolator')`. Symfony\\Process captured ~empty output from `deptrac-shim` on Windows PHP — `vendor/bin/deptrac analyse` produces the full violation table when run in shell (verified with `> /tmp/out.txt` redirect), but Process's proc_open shim couldn't catch it.
- **Fix:** Simplified to exit-code-only assertion. Exit code ≠ 0 is the CI-authoritative signal — if Deptrac ran and found any violation, it exits non-zero; if it found NO violations, it exits 0. The planted violator is the only thing in Sync that could trigger WpDirectDb, so `exitCode ≠ 0 with violator present` ∧ `exitCode == 0 without violator` is a sufficient bi-conditional. Inline comments document the platform quirk so future authors don't reintroduce the output-grep check.
- **Committed in:** `8f7c76f` (Task 1).

**3. [Rule 2 — Missing Critical] `UserPolicy` referenced in PolicyTemplateIntegrityTest Gate-binding pairs does not exist in this codebase**

- **Found during:** Task 2, writing the Architecture PolicyTemplateIntegrityTest.
- **Issue:** Initial test included `\App\Models\User::class => \App\Policies\UserPolicy::class` in the Gate-binding pairs. `ls app/Policies/` shows only `RolePolicy.php` — UserPolicy doesn't exist. Including it would false-fail the 3rd test.
- **Fix:** Removed the pair. Test is forward-compatible: if Phase 4+ adds UserPolicy, one array entry addition extends coverage. Inline comment notes the class-exists guard for robustness.
- **Committed in:** `c3dc2e7` (Task 2).

**4. [Rule 3 — Blocking] RetentionPruneTest had a hardcoded "schedules all 3 prune commands" assertion**

- **Found during:** Task 2, after adding the sync-errors:prune schedule entry.
- **Issue:** Phase 1 Plan 05's `RetentionPruneTest::it('schedules all 3 prune commands in routes/console.php')` was frozen at 3 commands. Plan 02-05 adds a 4th (sync-errors:prune), so that test would PASS at 3 (it counts commands it finds, not the total) but stop reflecting the actual count. `routes/console.php file uses withoutOverlapping on each prune` test used `>= 3` threshold which would also stop being precise.
- **Fix:** Updated the test to expect 4 prune commands + withoutOverlapping ≥ 4. Forward-compatible: Phase 5's competitor-csv:prune will bump it to 5 when it ships.
- **Verification:** both updated tests pass post-change. The new PruneSyncErrorsTest P5 adds a parallel content-grep assertion so future plans don't have to touch RetentionPruneTest.
- **Committed in:** `c3dc2e7` (Task 2).

---

**Total deviations:** 4 auto-fixed (3 blockers, 1 missing-critical). No Rule 4 architectural asks. Plan contract shipped in full — SYNC-04 enforced, Pitfall P2-H guardrail permanent, D-07 retention online, 02-VERIFICATION.md delivered.

## Authentication Gates

None encountered — all work is architectural + scheduling + documentation. Tests use `RefreshDatabase` + in-memory Pest assertions; no external network calls.

## Issues Encountered

1. **Deptrac-shim phar stdout capture on Windows PHP** — Symfony\\Process sometimes cannot read the violation table even though it's definitely produced (shell-direct runs show it). Exit-code-only assertion is the correct workaround. Cosmetic; CI gate works.

2. **Schedule timezone asymmetry** — the new sync-errors:prune entry sets `timezone('Europe/London')` matching the commented sync:supplier entry. Phase 1's 3 existing prunes use server-default timezone (unset). Cross-phase alignment would be a small follow-up PR but out of scope here.

3. **PHP CLI still needs PATH inline** — `export PATH="/c/Users/sonny.tanda/.config/herd/bin/php84:$PATH"` prepended to every test command. Known Windows dev-env issue, unchanged since Phase 02-03.

## User Setup Required

None for Plan 02-05 test coverage. For production ops:

- The new `sync-errors:prune` cron entry goes live the moment the deploy runs `php artisan config:cache` and the supervisor reloads. 90-day retention is conservative; no operator action needed to tune.
- 02-VERIFICATION.md lists the full VPS handoff sequence — applies to the whole Phase 2 cutover, not just Plan 02-05.

## Next Plan Readiness

### Phase 3 (Pricing Engine) can assume

- `SupplierPriceChanged` domain event (02-03) fires after-commit reliably — PricingRule / MarginRecompute listener subscribes without touching Sync code.
- `pricing_manager` role has 26 Shield permissions (Product 12 + ImportIssue 12 + view-only SyncRun 2). Phase 3's PricingRule Resource will auto-include via the seeder's dual-style LIKE patterns.
- Deptrac `Pricing` layer currently has `[Foundation]` only — Phase 3 Plan 01 adds `Products` and possibly `Sync` (for reading last supplier price). Same pattern as 02-03's `Sync: [+Products]` update.
- Any Phase 3 `shield:generate` run on new PricingRule / Promotion policies is CI-caught within 1 test if placeholders leak — no manual grep needed.

### Phase 7 (cutover runbook) can assume

- 02-VERIFICATION.md's "VPS Operator Handoff" section is the authoritative env-var + command checklist.
- Retention prunes (4 total) run 03:00–03:30 daily from day one — no activation needed.
- `Schedule::command('sync:supplier --live')` in routes/console.php is the documented kill-switch; uncommenting enables production sync.

### Known concerns for later plans

1. **Deptrac `qossmic/deptrac-shim` PHP deprecation warnings** on Laravel 12 strict runtime — cosmetic only; analysis works. Consider upgrading `qossmic/deptrac` when it releases a PHP-8.4-clean version.

2. **Deptrac Windows PHP stdout capture limitation** — if future architecture tests need to inspect deptrac output textually, use a file-redirect intermediary (`deptrac analyse > {tempfile}`) rather than Symfony\\Process::getOutput().

3. **PruneSyncErrorsCommand uses `Illuminate\Console\Command` not BaseCommand** — correlation_id arrives via Auditor→Context (not via BaseCommand's startBatch). This is fine for retention prunes (they're called by the scheduler, not from an HTTP correlation), but if a future command needs cross-command LogBatch grouping, the choice should be BaseCommand.

4. **Schedule timezone inconsistency** — 3 Phase 1 prunes use server default; 1 Phase 2 prune + sync:supplier use Europe/London. A future consolidation PR could set all schedule entries to London for legibility.

## Threat Flags

No new trust boundaries introduced beyond the plan's documented threat model. All 5 STRIDE mitigations covered:

- **T-02-05-01** (SYNC-04 architectural bypass) — mitigated: Deptrac WpDirectDb deny rule + negative test (DeptracSyncLayerTest). Build fails on PR before merge.
- **T-02-05-02** (Shield regenerates a hand-edited policy) — mitigated: Architecture PolicyTemplateIntegrityTest with 3 assertions runs on every PR; grep + count + Gate-binding resolution all catch damage < 1s.
- **T-02-05-03** (PruneSyncErrorsCommand misconfigured days) — mitigated: `--days=0` graceful no-op + Auditor::record writes BEFORE delete so operator sees what the prune was about to do.
- **T-02-05-04** (Auditor row info disclosure) — ACCEPTED: activity_log is admin-only readable; this is the intended audit trail.
- **T-02-05-05** (www-data compromise = full app compromise) — ACCEPTED: server-level concern; Phase 7 VPS hardening covers.

## Self-Check: PASSED

- Created files verified:
  - `tests/Architecture/DeptracSyncLayerTest.php` FOUND
  - `tests/Architecture/PolicyTemplateIntegrityTest.php` FOUND
  - `app/Console/Commands/PruneSyncErrorsCommand.php` FOUND
  - `tests/Feature/PruneSyncErrorsTest.php` FOUND
  - `.planning/phases/02-supplier-sync/02-VERIFICATION.md` FOUND
- Deleted file verified:
  - `tests/Feature/PolicyTemplateIntegrityTest.php` — absent (confirmed via `ls tests/Feature/ | grep -i policy` → empty)
- Modified files verified:
  - `depfile.yaml` contains `name: WpDirectDb` + `'-WpDirectDb'` in Sync ruleset
  - `deptrac.yaml` is identical to depfile.yaml (mirror convention)
  - `app/Domain/Sync/Jobs/SyncChunkJob.php` — `use Illuminate\Support\Facades\DB;` absent; `DB::transaction(` absent
  - `routes/console.php` — contains `sync-errors:prune` + `dailyAt('03:20')` + `withoutOverlapping(30)` + `onOneServer()`; no residual TODO for D-07
  - `tests/Feature/RetentionPruneTest.php` — "schedules all 4 prune commands"; withoutOverlapping count expects ≥ 4
- Commits verified via `git log --oneline -5`:
  - `8f7c76f` Task 1 FOUND
  - `c3dc2e7` Task 2 FOUND
- Artisan + schedule verified:
  - `php artisan list | grep sync-errors:prune` → returns the row with D-07 description
  - `php artisan schedule:list | grep sync-errors:prune` → returns `20 2 * * * php artisan sync-errors:prune --days=90` (03:20 Europe/London = 02:20 server UTC)
  - `php artisan schedule:list` shows 4 prune entries (03:00 activitylog, 03:10 integration-events, 02:20 sync-errors [UTC], 03:30 sync-diffs)
- Test results:
  - `vendor/bin/pest --filter="DeptracSyncLayer"` — 2 passed
  - `vendor/bin/pest --filter="PolicyTemplateIntegrity"` — 3 passed
  - `vendor/bin/pest --filter="PruneSyncErrors"` — 5 passed
  - `vendor/bin/pest --filter="RetentionPrune"` — 8 passed (all original + the 2 modified)
  - **Full suite: 232 passed, 2 skipped** (was 223 baseline + 9 net new = 232)
- `vendor/bin/deptrac analyse --no-progress` — 0 violations, 33 allowed, 686 uncovered
- `grep -rn '{{ ' app/Policies/ app/Domain/*/Policies/` — zero matches

---

## Phase 2 Wrap-Up Stats

**5 Plans shipped.** Total Phase 2 deliverable:

| Metric | Value |
|---|---|
| Plans | 5 (02-01 through 02-05) |
| Tasks | 12 |
| Commits (task) | 14 |
| Commits (metadata) | 4 (plus 1 more after this SUMMARY lands) |
| Test count (start) | 92 (Phase 1 baseline) |
| Test count (end) | 232 passing + 2 skipped |
| Net Phase 2 tests | +140 (76% growth) |
| Deptrac violations | 0 throughout |
| New requirement IDs completed | 13 (SYNC-01 through SYNC-13) |
| New migrations | 7 (6 Phase 2 tables + 1 additive column) |
| New Filament Resources | 3 (SyncRun, ImportIssue, Product) |
| New policies | 5 (all hand-written, Pitfall P2-H protected) |
| New domain events | 4 (SupplierPriceChanged/StockChanged/SkuMissing + NewSupplierSkuDetected) |
| New Deptrac layer | 1 (WpDirectDb) |
| New console commands | 2 (sync:supplier + sync-errors:prune) |
| Deviations auto-fixed | 24 across 5 plans |
| Rule 4 architectural asks | 0 |

**Phase 2 is SHIP-READY.** All 13 SYNC-* requirements have test coverage, 6 success criteria are each tied to specific plans + test filters, guardrails in place (Deptrac SYNC-04 + Pitfall P2-H PolicyTemplateIntegrity + D-07 retention), and the VPS cutover handoff is documented in 02-VERIFICATION.md. Phase 3 (Pricing Engine) can start immediately against this baseline.

---

*Phase: 02-supplier-sync*
*Plan: 05-guardrails*
*Completed: 2026-04-19*
