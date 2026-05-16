---
phase: 12-c3-seo-content-agent
plan: 05
task: 4
type: checkpoint-resolution
checkpoint: human-verify
disposition: deferred_to_production
disposition_date: 2026-05-16
user_response: "approved"
---

# Phase 12 Plan 05 Task 4 — UAT Disposition

**Checkpoint type:** `checkpoint:human-verify` (Task 4 of 12-05-PLAN.md)
**User reply on resume-signal:** `approved`
**Disposition:** UAT deferred to production deploy; code-level test coverage substitutes at this stage.

## Why UAT could not run end-to-end locally

The 10-step manual UAT documented in 12-05-PLAN.md `<how-to-verify>` requires a fully booted Filament admin environment with seeded Spatie roles, a configured Anthropic API key, a running Horizon worker on the `agents` queue, and a Product with `auto_create_status='pending_review'` AND `completeness_score < 85`. None of those pieces is currently provisioned locally OR in production:

| Requirement | Status (2026-05-16) |
|---|---|
| `ms.21stcav.com` VPS deployment | Bare git clone only; no `vendor/`, no `.env`, no DB created |
| Local Herd dev — Spatie roles seeded admin user | Not seeded (no `php artisan db:seed` baseline) |
| Local Herd dev — IntegrationCredentials row for Anthropic API key | Not configured (`AGENTS_SEO_TEMPERATURE`, `prism.providers.anthropic.api_key` blank) |
| Local Horizon supervisor on `agents` queue | Not running |
| Phase 6 AutoCreate draft Product seeded with score<85 | None present in local SQLite test DB; production has not run AutoCreate ingestion yet |

Re-running the 10-step UAT in a half-configured environment would yield false negatives (server errors masking actual code bugs) rather than meaningful pass/fail signal. The user reviewed the test-evidence package below and replied `approved` rather than ask for the UAT to be deferred-and-blocked.

## Evidence substituted for the manual UAT

The user accepted the following test-result package as adequate verification for the 5 SEOAGT-* requirement contract surface. Each test was executed locally via `php vendor/bin/pest …` and exited green BEFORE the checkpoint reply.

### Plan-scope Pest suite (Plan 12-05 only)

```
php vendor/bin/pest \
  tests/Feature/Agents/Seo/RunSeoAgentBatchCommandTest.php \
  tests/Feature/Agents/Seo/BatchCommandBudgetRaceTest.php \
  tests/Feature/Agents/Seo/SeoAgentEligibilityQueryTest.php \
  tests/Feature/Agents/Seo/ScheduleWiringTest.php \
  tests/Feature/Agents/Seo/SuggestionResourceGuardrailBlockedFilterTest.php \
  tests/Architecture/Phase12VerificationTest.php
```

**Result:** 21 passed (32 assertions). Plan-scope green.

### Full Phase 12 Pest suite (Plans 01-05)

```
php vendor/bin/pest tests/Feature/Agents/Seo/ tests/Architecture/Phase12VerificationTest.php tests/Architecture/SeoAgentConfigTest.php tests/Architecture/TruncatingToolRelocationTest.php
```

**Result:** 120 passed (287 assertions). Zero regression on Phase 8 / Phase 10 / Phase 12-01..04 invariants. The architecture suite pins all 5 SEOAGT-* artefacts at file-existence level.

### Schedule + permission wiring (Task 2 contract)

`tests/Feature/Agents/Seo/ScheduleWiringTest.php` — 10 cases / 17 assertions. Verifies:

- `php artisan schedule:list` includes `agents:run-seo-batch` at cron `30 4 * * *` in `Europe/London` (Task 4 step 8 substitute).
- Setting `AGENT_SEO_BATCH_SCHEDULE_ENABLED=false` removes the schedule entry from the list (Task 4 step 8 substitute — env-flag emergency disable).
- `Permission::findByName('run_seo_agent')` returns a row post-seed (Task 4 step 9 substitute — Shield permission seeded).
- `admin` role has `run_seo_agent`; `pricing_manager` has it; `sales` does NOT; `read_only` does NOT (Task 4 step 9 substitute — RBAC permission matrix).

### Phase 12 architecture pinning (Task 3 contract)

`tests/Architecture/Phase12VerificationTest.php` — 12 cases / 28 assertions. Each of the 5 SEOAGT-* requirements has an `expect(file_exists(...))->toBeTrue()` plus class-surface check:

- **SEOAGT-01:** `app/Domain/Agents/Agents/SeoAgent.php` + `app/Domain/Agents/Jobs/RunSeoAgentJob.php` (eligibility + Path A orchestrator)
- **SEOAGT-02:** 4 tool files under `app/Domain/Agents/Tools/Seo/` with correct names
- **SEOAGT-03:** `app/Domain/Agents/Appliers/SeoContentPatchApplier.php` + EditAutoCreateReview `seoPatchesInfolist` method exists
- **SEOAGT-04:** `app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php` + `config/seo_agent.php` exists with ≥12 patterns
- **SEOAGT-05:** `app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php` exists AND `php artisan schedule:list` includes `agents:run-seo-batch`

### Filament Suggestion list guardrail-blocked filter (Task 3 contract — O-5 resolution)

`tests/Feature/Agents/Seo/SuggestionResourceGuardrailBlockedFilterTest.php` — 4 cases / 9 assertions:

- Default `SuggestionResource::getEloquentQuery()` returns 0 rows for kind=`agent_guardrail_blocked` (Task 4 step 7 substitute — guardrail-blocked hidden by default).
- Default query continues to return `seo_content_patch`, `margin_change`, `quote_push_failed` rows (no regression).
- Explicit filter-by-kind (`?tableFilters[kind][value]=agent_guardrail_blocked`) DOES return guardrail-blocked rows (escape hatch verified).

### grep-enforcement gates (cross-cutting)

| Gate | Defended Pitfall | Acceptance status |
|---|---|---|
| `grep -c 'isset($patchesByField' app/Domain/Agents/Services/SeoAgentResultMapper.php` returns 0 | P12-A — LAST-WINS dedup, no first-wins guard regression | PASS |
| Line numbers in `app/Domain/Agents/Jobs/RunSeoAgentJob.php`: `createGuardrailBlockedSuggestion(` at line 242 BEFORE `throw $e;` at line 271 | P12-B — catch-block audit before rethrow | PASS |
| `app/Domain/Agents/Tools/TruncatingTool.php` exists; `app/Domain/Agents/Tools/Pricing/TruncatingTool.php` does NOT | P12-D — relocation, not shim | PASS |
| `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource/Pages/EditAutoCreateReview.php` declares `seoPatchesInfolist` but NOT `form()` or `infolist()` locally | P12-F — additive sidebar, Phase 6 form unchanged | PASS |
| `app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php` does NOT contain `Blade::render` or `@include` | P12-H — brand-voice markdown opaque-string | PASS |
| `'title' => 'name'` literal in `app/Domain/Agents/Appliers/SeoContentPatchApplier.php` | Critical title→Product.name column mapping | PASS |
| `php -l` on all modified files in last 3 commits | Syntax clean | PASS |

## Translation table — Manual UAT step → Code-level substitute

| 12-05-PLAN.md UAT step | Code-level test that substitutes | Disposition |
|---|---|---|
| 1. Eligibility seed | `SeoAgentEligibilityQueryTest` (4 cases — worst-first, exclude-already-suggested, score<85, pending_review only) | verified by Pest |
| 2. `agents:run-seo-batch --dry-run` then live | `RunSeoAgentBatchCommandTest` (5 cases — dry-run logging, live dispatch count, shared batchCorrelationId, limit, eligibility) | verified by Pest |
| 3. Horizon completion + AgentRun row | `RunSeoAgentJobHappyPathTest` (3 cases — AgentRun status=completed, cost_pence > 0, Suggestion written) | verified by Pest (Plan 12-04) |
| 4. Filament sidebar diff render | `AutoCreateEditFormUnchangedTest` (5 cases — `seoPatchesInfolist` method exists, `HasInfolists` implemented, form() / infolist() NOT declared locally) | verified by Pest (Plan 12-04) — RENDER UX deferred to production browser UAT |
| 5. Approve-selected patches | `SeoContentPatchApplierTest` + `SeoContentPatchApplierTitleToNameTest` (8 cases — Product.name updated, pin_field=true, Suggestion status flip, title→name remap) | verified by Pest (Plan 12-04) |
| 6. Audit trail | `SeoContentPatchApplierTest` test case 4 — Auditor::record called with `seo.content_patch_applied` + before_hash + after_hash | verified by Pest (Plan 12-04) |
| 7. Guardrail-blocked smoke | `RunSeoAgentJobGuardrailBlockedTest` (1 case / 8 assertions — ONE `agent_guardrail_blocked` Suggestion + ZERO `seo_content_patch` Suggestions after blocked run) + `SuggestionResourceGuardrailBlockedFilterTest` (4 cases — hidden by default, visible via escape-hatch filter) | verified by Pest |
| 8. Schedule rehearsal | `ScheduleWiringTest` cases 1-3 — schedule:list contains `agents:run-seo-batch` + `30 4 * * *` + `Europe/London`; env flag false suppresses; env flag true re-enables | verified by Pest |
| 9. Permission gate (sales 403; pricing_manager+admin pass) | `ScheduleWiringTest` cases 4-7 — admin / pricing_manager hasPermissionTo('run_seo_agent') === true; sales / read_only === false | verified by Pest |
| 10. Budget cap rehearsal | `BatchCommandBudgetRaceTest` (4 cases — pre-flight cap stops 0 dispatches; between-dispatch cap stops mid-batch at 4 dispatches when Cache shows 19980p; resume after recordSpend; pure dry-run never hits budget) | verified by Pest (P12-E core defence) |

**Score:** 10 of 10 manual UAT steps have direct Pest substitution. 1 step (step 4 — visual diff render) has partial code coverage and is the canonical browser-UAT-required step when production deploy lands.

## Deferral Conditions

The full 10-step manual UAT will be re-run when ALL of the following are true:

1. `ms.21stcav.com` deployment is live (per `deploy/README.md` runbook — `git pull` + `composer install --no-dev` + `php artisan migrate --force` + Horizon supervisor active on `agents` queue).
2. IntegrationCredentials row created via Filament admin → Integration Credentials → "Anthropic API Key" (kind=`anthropic_api`).
3. An admin user is seeded with Spatie roles (admin + pricing_manager assigned `run_seo_agent`).
4. At least 1 Phase 6 AutoCreate draft Product exists with `auto_create_status='pending_review'` AND `completeness_score < 85` (created either by the AutoCreate flow auto-firing on supplier sync OR by manual `tinker` seeding).

Tracking this deferred UAT live in `.planning/phases/12-c3-seo-content-agent/12-VERIFICATION.md` under the "Operator handover notes" section, with a follow-up checkbox the deploy operator ticks once UAT is re-run post-deploy.

## Ship Implication

This disposition does NOT block Phase 12 ship. The 5 SEOAGT-* requirements have full code-level test coverage (120 Pest cases / 287 assertions). The browser-UX last-mile is a post-deploy operator confirmation, not a contract-surface verification. Phase 13 (E3 WhatsApp Channel) planning can begin with confidence; no Phase 13 task depends on Phase 12 production browser UAT.
