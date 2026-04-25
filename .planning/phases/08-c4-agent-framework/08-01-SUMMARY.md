---
phase: 08-c4-agent-framework
plan: 01
subsystem: infra
tags: [agents, prism, anthropic, deptrac, horizon, ulid, eloquent, pest, architecture-tests]

requires:
  - phase: 01-foundation
    provides: Suggestions seam, BaseCommand, IntegrationLogger, Auditor, Horizon supervisor patterns, AlertRecipient receives_* boolean pattern
  - phase: 04-bitrix-crm-sync
    provides: hand-written admin-only Policy precedent, Pitfall P5-F restoration pattern
  - phase: 05-competitor-analysis
    provides: Deptrac dual-YAML sync lesson (P05-05)
  - phase: 07-dashboard-cutover
    provides: Architecture-test exit-code assertion pattern (DeptracDashboardLayerTest), PolicyTemplateIntegrityTest scaffold
provides:
  - 15-column agent_runs table (ULID PK + 14 D-06 columns + guardrail_failures JSON for ROADMAP success criterion #4)
  - 4 backed enums (AgentKind, AgentRunStatus, FinishReason, TrustTier) — single source of truth for downstream Plan 02-05
  - AgentRun Eloquent model (HasUlids + LogsActivity capturing only status + completed_at)
  - AgentRunFactory for fixture-driven Plan 03-05 testing
  - config/agents.php — 6 budget keys + 3 shadow-mode flags + Anthropic pricing table + Langfuse observability driver + retention horizon
  - agents-supervisor Horizon registration (production maxProcesses=2, tries=1, timeout=180s) + local all-in-one queue extension + waits[redis:agents]=60
  - AgentRunPolicy (admin-only viewAny/view; create/update/delete denied unconditionally)
  - Deptrac Agents layer in BOTH depfile.yaml AND deptrac.yaml with allow-list [Foundation, Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate]
  - 3 architecture tests (AgentsWriteOnlyViaSuggestionsTest, AgentToolsNamingTest, DeptracAgentsLayerTest) locking the framework's load-bearing invariants
  - PolicyTemplateIntegrityTest floor bumped 26 → 27 (AgentRunPolicy registered + scanned)
  - 2 supporting migrations (suggestions.auto_apply_eligible nullable boolean for v2.1; alert_recipients.receives_agent_alerts default true)
affects: [08-02-claude-client, 08-03-budgetguard-toolbus-guardrails, 08-04-echoagent-filament, 08-05-shield-safe-regenerate-gdpr, phase-10-pricing-agent, phase-12-seo-agent, phase-14-product-finder-chatbot, phase-15-ad-agent]

tech-stack:
  added: []  # zero composer changes — Plan 02 ships prism-php/prism + mliviu79/laravel-langfuse-prism
  patterns:
    - "ULID-keyed framework forensics row (HasUlids + LogsActivity dirty-only)"
    - "Backed-enum casts for kind/status/finish-reason replacing magic strings"
    - "config/agents.php as single budget + shadow-mode source of truth, integer-cast for fail-closed env vars"
    - "Deptrac Agents layer mirrored byte-equivalent across both yaml configs (Phase 5 dual-YAML lesson)"
    - "Architecture-test guardrails for DB-write prohibition, tool naming convention, and yaml structural sync"

key-files:
  created:
    - database/migrations/2026_04_25_010000_create_agent_runs_table.php
    - database/migrations/2026_04_25_010100_add_auto_apply_eligible_to_suggestions_table.php
    - database/migrations/2026_04_25_010200_add_receives_agent_alerts_to_alert_recipients_table.php
    - app/Domain/Agents/Models/AgentRun.php
    - app/Domain/Agents/Enums/AgentKind.php
    - app/Domain/Agents/Enums/AgentRunStatus.php
    - app/Domain/Agents/Enums/FinishReason.php
    - app/Domain/Agents/Enums/TrustTier.php
    - app/Domain/Agents/Policies/AgentRunPolicy.php
    - database/factories/Domain/Agents/AgentRunFactory.php
    - config/agents.php
    - tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php
    - tests/Architecture/AgentToolsNamingTest.php
    - tests/Architecture/DeptracAgentsLayerTest.php
    - tests/Unit/Domain/Agents/Models/AgentRunTest.php
  modified:
    - config/horizon.php (agents-supervisor production block + local queue extension + waits entry)
    - depfile.yaml (Agents layer + ruleset; Http allow-list extended with Agents)
    - deptrac.yaml (mirrored depfile.yaml additions)
    - app/Providers/AppServiceProvider.php (Gate::policy(AgentRun::class, AgentRunPolicy::class))
    - tests/Architecture/PolicyTemplateIntegrityTest.php (floor 26 → 27 + AgentRunPolicy gate-binding pair)

key-decisions:
  - "AgentRun model is final and excludes ALL writes from architecture grep except Models/AgentRun.php — Plan 03's AgentSuggestionWriter is the sole non-AgentRun writer"
  - "agents-supervisor was NOT pre-allocated in v1 (research correction #1); Plan 01 ADDS the production supervisor block + local queue extension + waits entry"
  - "proposed_by morph columns NOT migrated — Phase 1 D-14 already shipped them via 2026_04_18_180100_create_suggestions_table.php (research correction #2)"
  - "spatie/laravel-permission stays at ^6.0 (NOT bumped past — research correction #3 + zero-version-bump invariant)"
  - "AgentRunPolicy uses hand-written hasRole('admin') matching v1 Pitfall K + P5-F precedent (deviation Rule 2 — security correctness; the plan example showed user->can() but every existing v1 policy uses hasRole)"
  - "guardrail_failures JSON column added per plan-checker iter 1 to satisfy ROADMAP success criterion #4 (15th column atop the 14 D-06 mandate)"

patterns-established:
  - "Domain/Agents bootstrap layout — Enums/, Models/, Policies/ shipped Plan 01; Plan 03+ ships Services/, Clients/, Guardrails/, Tools/, Jobs/, Console/, Filament/"
  - "config/agents.php layout — budget caps first, shadow flags second, Anthropic pricing table third, observability driver fourth, retention horizon last"
  - "Architecture test for greenfield domains: write-prohibition grep + naming-convention grep + Deptrac dual-YAML structural assertion + Deptrac analyse positive run (mirrors Phase 5/6/7 pattern)"

requirements-completed: [AGNT-03, AGNT-09, AGNT-10, AGNT-12]

duration: 55min
completed: 2026-04-25
---

# Phase 8 Plan 01: C4 Agent Framework Foundation Summary

**15-column ULID `agent_runs` table + 4 backed enums + agents-supervisor Horizon queue + Deptrac Agents layer (dual-YAML) + 3 architecture tests locking the load-bearing framework invariants — zero composer changes.**

## Performance

- **Duration:** 55 min
- **Started:** 2026-04-25T10:06:34Z
- **Completed:** 2026-04-25T11:01:22Z
- **Tasks:** 3 (all atomic-committed)
- **Files created:** 15
- **Files modified:** 5

## Accomplishments

- **Data model foundation** — 3 migrations ship: `agent_runs` (ULID PK + 15 D-06 columns), `suggestions.auto_apply_eligible` (nullable for v2.1), `alert_recipients.receives_agent_alerts` (default true). AgentRun model uses HasUlids + LogsActivity (dirty-only on `status` + `completed_at`); 4 backed enums (AgentKind, AgentRunStatus, FinishReason, TrustTier) replace magic strings everywhere downstream.
- **Budget + shadow-mode config** — `config/agents.php` exposes the D-01..D-05 budget knobs (monthly_ceiling_pence=20000, per-kind daily caps, default fail-safe 100p, Europe/London day boundary), the AGNT-12 shadow flags (write_enabled / auto_apply_enabled both default false), the Anthropic pricing table for post-flight cost calculation, the Langfuse observability driver, and the 5y retention horizon. Every value is integer/bool/float-cast for fail-closed env-var coercion.
- **Horizon queue** — `agents-supervisor` registered in production (tries=1, timeout=180s, maxProcesses=2 per Anthropic tier-1 cap) + local all-in-one queue list extended + `redis:agents` slow-queue alarm at 60s. Research correction #1: this supervisor was NOT pre-allocated in v1 Phase 1 FOUND-09 — Plan 01 adds it.
- **Deptrac Agents layer** — registered in BOTH depfile.yaml AND deptrac.yaml with allow-list `[Foundation, Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate]`. Http allow-list extended with `Agents` so Plan 04's RunAgentJob dispatchers can reach in. Webhooks/Sync/Cutover/Marketing explicitly denied. `vendor/bin/deptrac analyse` exits 0 on both YAMLs.
- **Architecture-test guardrails** — `AgentsWriteOnlyViaSuggestionsTest` greps for direct DB writes outside `Models/AgentRun.php` (currently passes vacuously, lights up Plan 03 onwards), `AgentToolsNamingTest` enforces propose_/read_/search_ naming on every Tool subclass (vacuous until Plan 04 ships ReadHealthCheckTool), `DeptracAgentsLayerTest` asserts dual-YAML structural sync + positive Deptrac analyse run. PolicyTemplateIntegrityTest floor bumped 26 → 27.
- **Admin-only policy gate** — `AgentRunPolicy` registered via `Gate::policy(AgentRun::class, ...)` in AppServiceProvider::boot. viewAny/view check `$user->hasRole('admin')`; create/update/delete return false unconditionally (AgentRuns are framework-produced, never admin-edited).

## Task Commits

Each task was committed atomically:

1. **Task 1: Migrations + AgentRun model + enums + factory + 12 unit tests** — `1b1b9a1` (feat — TDD: tests written first, then production code)
2. **Task 2: config/agents.php + agents-supervisor Horizon registration + AgentRunPolicy gate** — `18d024f` (feat)
3. **Task 3: Deptrac Agents layer (dual-YAML) + 3 arch tests + PolicyTemplateIntegrity floor bump** — `36d08c2` (feat)

**Plan metadata commit:** [pending — final commit at end of execution]

## Files Created/Modified

### Created (15)

- `database/migrations/2026_04_25_010000_create_agent_runs_table.php` — 15-column ULID-keyed agent_runs table with 4 indexes (kind+status, triggering_correlation_id, triggering_suggestion_id, started_at)
- `database/migrations/2026_04_25_010100_add_auto_apply_eligible_to_suggestions_table.php` — nullable boolean for v2.1 per-kind opt-in
- `database/migrations/2026_04_25_010200_add_receives_agent_alerts_to_alert_recipients_table.php` — default-true boolean with backfill UPDATE per Pitfall P6-D
- `app/Domain/Agents/Models/AgentRun.php` — final Eloquent model, HasUlids + LogsActivity dirty-only on status + completed_at, gdprScrubInPlace stub for Plan 05
- `app/Domain/Agents/Enums/AgentKind.php` — Echo, Pricing, Seo, Chatbot, AdOptimisation
- `app/Domain/Agents/Enums/AgentRunStatus.php` — Running, Completed, Failed, BudgetExceeded, GuardrailBlocked, MonthlyBudgetBlocked
- `app/Domain/Agents/Enums/FinishReason.php` — EndTurn, ToolUse, MaxTokens, StopSequence, Error (Prism stop-reason mirror)
- `app/Domain/Agents/Enums/TrustTier.php` — Trusted, Mixed, Untrusted (TrustTier passes to RunsAsAgent::execute in Plan 03)
- `app/Domain/Agents/Policies/AgentRunPolicy.php` — admin-only hasRole('admin') gate; mutations denied unconditionally
- `database/factories/Domain/Agents/AgentRunFactory.php` — running-state default with deterministic 64-char system_prompt_hash filler
- `config/agents.php` — single source of truth: 6 budget keys + 3 shadow flags + Anthropic pricing + Langfuse observability + 5y retention
- `tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php` — DB-write prohibition grep over `app/Domain/Agents/**` excluding Models/AgentRun.php
- `tests/Architecture/AgentToolsNamingTest.php` — propose_/read_/search_ name() prefix enforcement (vacuous until Plan 04)
- `tests/Architecture/DeptracAgentsLayerTest.php` — dual-YAML structural assertion + 2 positive Deptrac analyse runs
- `tests/Unit/Domain/Agents/Models/AgentRunTest.php` — 12 behaviour tests for ULID, casts, factory, LogsActivity, enum sequences, guardrail_failures cast

### Modified (5)

- `config/horizon.php` — agents-supervisor production block (tries=1, timeout=180s, maxProcesses=2) + local all-in-one queue extension + waits[redis:agents]=60
- `depfile.yaml` — Agents layer + ruleset (allow-list of 7 v1 layers); Http allow-list extended with Agents
- `deptrac.yaml` — mirrored byte-equivalent against depfile.yaml additions
- `app/Providers/AppServiceProvider.php` — `Gate::policy(AgentRun::class, AgentRunPolicy::class)` + use statements
- `tests/Architecture/PolicyTemplateIntegrityTest.php` — added Domain/Agents/Policies path + AgentRun→AgentRunPolicy pair to bindings test + floor bumped 26 → 27

## Decisions Made

- **Hand-written `hasRole('admin')` AgentRunPolicy (Rule 2 deviation — security correctness):** plan example showed `$user->can('view_any_agent_run')`, but every existing v1 policy uses `hasRole` per CLAUDE.md Pitfall K + P5-F invariant. Adopted hasRole to match the v1 defence-in-depth pattern. Plan 04 layers Shield permissions on top.
- **`max_steps=8` + `max_tokens=4000` defaults in config/agents.php:** matches CONTEXT D-01 Pitfall A1 mitigation. Both env-overridable so production scaling does not require redeploys.
- **Echo daily cap 50p:** smoke-test agent doesn't need real budget — 50p caps accidental loops during Plan 04 development. All other kinds use AGNT-04 spec values.
- **Http allow-list extended with Agents:** Plan 04's RunAgentJob dispatchers (controllers + console commands wired into routes) need to reach into Agents. Arrow stays one-way — Agents itself does NOT have Http in its allow-list.
- **Architecture tests pass vacuously where applicable:** `AgentsWriteOnlyViaSuggestionsTest` and `AgentToolsNamingTest` skip when their target directories don't exist yet (Plan 03+04 ship those). This matches the v1 architecture-test idiom — register the guardrail at the foundation phase, light it up as later plans add code.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Security/Correctness] Adopted hasRole('admin') in AgentRunPolicy instead of permission-only `$user->can()`**

- **Found during:** Task 2 (AgentRunPolicy creation)
- **Issue:** Plan 01 example showed `return $user->can('view_any_agent_run');` — but CLAUDE.md Pitfall K + Phase 5 P5-F invariant + every existing v1 policy uses `$user->hasRole('admin')` as the load-bearing second layer. Following the plan literally would have created a Shield-permission-only policy that drifts from the v1 defence-in-depth pattern (Plan 04 will register Shield permissions on top, but Plan 01's invariant is hasRole-first).
- **Fix:** Used `$user->hasRole('admin')` for `viewAny()` + `view()`; create/update/delete return false unconditionally; Pitfall P5-F docblock added matching the SuggestionPolicy precedent.
- **Files modified:** `app/Domain/Agents/Policies/AgentRunPolicy.php`
- **Verification:** `PolicyTemplateIntegrityTest` (Architecture suite, 28 assertions) passes — confirming Gate::getPolicyFor(AgentRun) resolves to AgentRunPolicy and the policy contains no Shield `{{ Placeholder }}` literal.
- **Committed in:** `18d024f` (Task 2 commit)

**2. [Rule 1 — Bug fix] Pest `expect()->toContain()` does not accept message-arg overload — DeptracAgentsLayerTest initially failed**

- **Found during:** Task 3 (running architecture tests)
- **Issue:** Initial test wrote `expect($layerNames)->toContain('Agents', "{$yamlPath} missing 'Agents' layer")` — Pest interprets the second positional arg as ANOTHER needle to find, not a custom failure message. Test failed with "Failed asserting that an array contains 'depfile.yaml missing Agents layer'".
- **Fix:** Removed all custom message strings from `toContain()` and `toHaveKey()` calls; left a single explanatory comment block at the top of the foreach loop noting Pest's positional-arg semantic. Failure stack trace surfaces the yaml path naturally.
- **Files modified:** `tests/Architecture/DeptracAgentsLayerTest.php`
- **Verification:** Re-ran `pest --testsuite=Architecture --filter=DeptracAgentsLayerTest` — 3 of 3 tests pass (29 assertions across the 3-test class).
- **Committed in:** `36d08c2` (Task 3 commit)

**3. [Rule 1 — Bug fix] Comment block containing literal `*/` inside docblock parsed as block-end and tripped PHP -l**

- **Found during:** Task 3 (syntax-checking AgentToolsNamingTest.php)
- **Issue:** The docblock at the top of AgentToolsNamingTest contained the phrase `read_*/search_*` to describe the tool naming convention. PHP's lexer matched the `*/` token and ended the docblock prematurely, leaving the rest of the line (`search_* tools log only).`) as PHP code that failed to parse.
- **Fix:** Reworded the comment to spell out `read_ and search_ tools log only` instead of using the slash-glob pattern.
- **Files modified:** `tests/Architecture/AgentToolsNamingTest.php`
- **Verification:** `php -l` clean across all 3 architecture test files; full architecture suite ran (only MySQL-deferral failures remained, none from this file).
- **Committed in:** `36d08c2` (Task 3 commit)

---

**Total deviations:** 3 auto-fixed (1 security correctness, 2 bug fixes)
**Impact on plan:** All three were minor mechanical fixes that did not change scope, success criteria, or downstream contracts. Architecture invariants are honoured exactly per CONTEXT D-06 + AGNT-10.

## Issues Encountered

- **MySQL deferral (precedent: Phase 6/7):** local MySQL service offline during execution (port 3306 refused). Migration verification via `php artisan migrate --pretend` and `migrate:status --env=testing` could not complete. Worked around by syntax-checking every PHP file with `php -l` (all clean), running the architecture suite that does not require DB (3 new arch tests pass + PolicyTemplateIntegrityTest passes). The 12 AgentRunTest unit tests are written against `RefreshDatabase` and will run cleanly when MySQL is back. The 9 architecture tests that DID fail in the regression run are pre-existing DB-bound tests (PricingRuleExclusiveSetTest, PriceCalculatorPurityTest, AutoCreateRejectionRetentionTest, etc.) all hitting the same `SQLSTATE[HY000] [2002] No connection could be made` error — none are regressions caused by Plan 01.
- **Deptrac analyse confirmed clean:** both `depfile.yaml` and `deptrac.yaml` exit 0 with 0 violations / 0 errors / 0 warnings.
- **Config + Horizon validation:** raw PHP harness asserted all 16 config keys + 7 Horizon supervisor/queue/wait knobs match the plan's verification expectations.

## Verification Status

| Success criterion | Status |
| --- | --- |
| All 3 tasks committed atomically | DONE — 1b1b9a1, 18d024f, 36d08c2 |
| `php artisan migrate:status --env=testing` shows 3 new migrations | DEFERRED — MySQL offline; migrations syntax-clean per `php -l`, ready for next MySQL window |
| `config('agents.monthly_ceiling_pence') === 20000` | VERIFIED via raw PHP harness |
| `agents-supervisor` in config/horizon.php production env | VERIFIED via raw PHP harness — tries=1, timeout=180, maxProcesses=2 |
| 3 new architecture tests pass | VERIFIED — `pest --filter='AgentsWriteOnlyViaSuggestionsTest\|AgentToolsNamingTest\|DeptracAgentsLayerTest'` → 4 passed, 1 skipped (vacuous on Tools/) |
| AgentRunTest 12 behaviours | DEFERRED — RefreshDatabase needs MySQL; tests written, syntax clean |
| `grep -c "name: Agents" depfile.yaml` = 1 AND deptrac.yaml = 1 | VERIFIED — Grep tool returned 1 occurrence in each file |
| spatie/laravel-permission unchanged at ^6 | VERIFIED — composer.lock shows `6.25.0` |
| No 2026_04_25_010100 migration touches `proposed_by_*` | VERIFIED — migration only adds `auto_apply_eligible` |
| Deptrac 0 violations on both YAMLs | VERIFIED — `vendor/bin/deptrac analyse` exits 0 on both configs |
| 08-01-SUMMARY.md + STATE.md + ROADMAP.md updated | IN PROGRESS — this commit closes the loop |
| PolicyTemplateIntegrityTest passes (floor 27) | VERIFIED — 3 passed (28 assertions) |

## Next Phase Readiness

- **Plan 02 (ClaudeClient + Prism + Langfuse)** has its data model + config foundation in place. AgentRun model is ready to receive `prompt_token_count` / `completion_token_count` / `cost_pence` / `langfuse_trace_id` from Plan 02's ClaudeClient post-flight. `config/agents.php pricing.claude-sonnet-4-6` is the canonical cost rate table. observability.driver=`langfuse-prism` is the install target.
- **Plan 03 (BudgetGuard + ToolBus + GuardrailEngine)** has all 6 budget config keys + Cache facade access + the Suggestions seam (write_enabled gate) wired. AgentsWriteOnlyViaSuggestionsTest is in place to catch any direct-write regression as Plan 03's Services land.
- **Plan 04 (EchoAgent + Filament Resource)** has AgentRunPolicy gate + AgentKind::Echo enum + agents-supervisor queue + AgentToolsNamingTest ready to enforce naming convention on the first ReadHealthCheckTool.
- **Plan 05 (shield:safe-regenerate + GDPR + retention)** has AgentRun::gdprScrubInPlace stub waiting + retention_days config + Pitfall P5-F invariant locked in by PolicyTemplateIntegrityTest.

**Outstanding (operator-side):** Run `php artisan migrate --env=testing` + `pest --filter=AgentRunTest` against `meetingstore_ops_testing` MySQL once the DB service is back online. Confirms 3 migrations land and 12 unit tests pass.

## Self-Check: PASSED

- `php -l` clean on all 15 created files + 5 modified files
- 3 new architecture tests pass (4 of 4 non-skipped + 1 vacuous skip on AgentToolsNamingTest pending Plan 04 Tools/)
- PolicyTemplateIntegrityTest passes with floor=27
- `vendor/bin/deptrac analyse` exits 0 on both yaml configs (0 violations / 0 errors)
- `Grep "name: Agents"` returns count=1 in BOTH depfile.yaml AND deptrac.yaml (dual-YAML proof)
- `composer show spatie/laravel-permission` reports 6.25.0 (NOT bumped past ^6.0)
- Migration `2026_04_25_010100_*` only adds `auto_apply_eligible` — no `proposed_by_*` writes (research correction #2 honoured)
- `agents-supervisor` present in `config/horizon.php` production env with tries=1, timeout=180s, maxProcesses=2 (research correction #1 honoured)
- All 16 `config/agents.php` keys validated via raw PHP harness against expected values
- All 3 task commits exist in `git log` (1b1b9a1, 18d024f, 36d08c2)

---
*Phase: 08-c4-agent-framework*
*Completed: 2026-04-25*
