---
phase: 15
plan: 15b-01
subsystem: Agents / Marketing Intelligence
tags: [agent, ad-optimisation, advice-only, ga4, suggestions, shadow-mode, tdd, prism, claude]
requires:
  - App\Domain\Integrations\Models\GaChannelMetric (Phase 15 15a-02) — ga_channel_metrics_daily snapshot
  - Phase 8 agent framework (AgentRegistry, BudgetGuard, ToolBus, GuardrailEngine, ClaudeClient, AgentRun, PromptRenderer)
  - Phase 10 PricingAgent + Phase 12 SeoAgent templates (mirrored, not invented)
  - App\Domain\Products\Models\Product + App\Domain\Competitor\Models\CompetitorPrice (read-only)
provides:
  - AdOptimisationAgent (kind='ad_optimisation', Trusted) — third REAL RunsAsAgent consumer
  - 3 Marketing tools — read_ga4_channel_performance, read_margin_opportunity, propose_marketing_action
  - RunAdOptimisationJob — single-analysis orchestration (no productId), shadow-gated
  - AdOptimisationResultMapper — bundled ad_optimisation Suggestion writer (advice-only)
  - agents:run-ad-optimisation scheduled command (safe no-op when no recent GA4 data)
  - ad_optimisation kind wired into the existing Suggestions inbox + acknowledge-only approval
affects:
  - 15b-02 (Marketing dashboard — surfaces these Suggestions; NOT built here)
  - 15c (closed-loop: Google Ads writes / ad_budget_overrides / GCLID — explicitly deferred)
tech-stack:
  added: []
  patterns:
    - "Advice-only agent: only side effect is a shadow-gated Suggestion; approval fires NO apply path"
    - "Single analysis run (no per-product loop) — RunAdOptimisationJob constructor takes only ?string batchCorrelationId"
    - "Safe no-op scheduling: command counts GA4 rows in lookback window; ZERO → log + exit 0, no dispatch, no LLM spend"
    - "Bundled Suggestion (payload.proposals[]) mirroring SeoAgentResultMapper; idempotent by agent_run_id"
    - "3 KB TruncatingTool soft cap + _truncated hints on both read tools (revenue-sorted / margin-sorted)"
    - "propose_* no-op ack sink (mirrors ProposeMarginBandTool); mapper materialises the Suggestion post-run"
key-files:
  created:
    - app/Domain/Agents/Tools/Marketing/ReadGa4ChannelPerformanceTool.php
    - app/Domain/Agents/Tools/Marketing/ReadMarginOpportunityTool.php
    - app/Domain/Agents/Tools/Marketing/ProposeMarketingActionTool.php
    - app/Domain/Agents/Agents/AdOptimisationAgent.php
    - resources/views/agents/ad_optimisation/system.blade.php
    - app/Domain/Agents/Jobs/RunAdOptimisationJob.php
    - app/Domain/Agents/Services/AdOptimisationResultMapper.php
    - app/Domain/Agents/Console/Commands/RunAdOptimisationCommand.php
    - tests/Feature/Agents/Marketing/ReadMarketingToolsTest.php
    - tests/Feature/Agents/Marketing/ProposeMarketingActionToolTest.php
    - tests/Feature/Agents/Marketing/AdOptimisationAgentRegistrationTest.php
    - tests/Feature/Agents/Marketing/AdOptimisationResultMapperTest.php
    - tests/Feature/Agents/Marketing/RunAdOptimisationJobTest.php
    - tests/Feature/Agents/Marketing/AdOptimisationSuggestionApproveActionTest.php
    - tests/Feature/Agents/Marketing/RunAdOptimisationCommandTest.php
  modified:
    - config/agents.php (ad_optimisation temperature + data_lookback_days + schedule toggle; reuses daily_caps.ad_optimisation=300)
    - app/Providers/AppServiceProvider.php (register agent + command)
    - routes/console.php (everySixHours schedule, config-gated)
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (kind filter option + acknowledge-only approve; generic approve excluded)
    - tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php (exempt the new job + mapper as sanctioned writers)
decisions:
  - "AdOptimisationResultMapper is bundled (payload.proposals[]) like SeoAgentResultMapper, and idempotent by payload->agent_run_id so a re-map returns the existing Suggestion rather than double-writing."
  - "Approval is acknowledgement-only via a dedicated acknowledge_ad_optimisation action (flips status, dispatches NO ApplySuggestionJob). 'ad_optimisation' is also excluded from the generic approve action (which DOES dispatch). No SuggestionApplier is registered for the kind — enforcing advice-only at the wiring level."
  - "Guardrail catch arm mirrors RunSeoAgentJob's status/record/rethrow shape but does NOT write a guardrail-blocked audit Suggestion (that is SEO-specific P12-B); the two guardrails (SensitiveFieldsStrip + OutboundRegex) match PricingAgent."
  - "read_margin_opportunity is a focused read (no single margin column exists): margin = sell_price − buy_price on published+instock products, joined to CompetitorPrice 90d min/count in one grouped query. Money path (RuleResolver/PriceCalculator, D-03) is untouched — read-only."
  - "config('agents.ad_optimisation.data_lookback_days', 14) drives the command's no-op gate; temperature 0.3 (cautious analyst, between pricing 0.0 and SEO 0.4)."
metrics:
  duration: ~2h
  completed: 2026-07-11
  tasks: 7
  files_created: 15
  files_modified: 5
---

# Phase 15 Plan 15b-01: AdOptimisationAgent (advice-only, GA4-fed) Summary

A scheduled Claude-backed **AdOptimisationAgent** that reads the 15a GA4 channel
snapshot plus the app's own margin / competitor / stock data and emits
prioritised **advice-only** `ad_optimisation` Suggestions (shift/increase/reduce
spend, pause, add coverage). It runs every six hours, is a single analysis run
(no per-product loop), is shadow-mode gated, and no-ops safely when there is no
recent GA4 data — so it is safe to schedule now, before real GA4 data flows. Its
ONLY side effect is writing Suggestions to the existing inbox; approving one is
acknowledgement only.

## Tasks & Commits

| Task | Description | Commit |
| ---- | ----------- | ------ |
| 1 | Marketing read tools (read_ga4_channel_performance + read_margin_opportunity) | 8089ce1 |
| 2 | propose_marketing_action advice-only structured sink | 2bc1234 |
| 3 | AdOptimisationAgent + registration + system prompt + config | 4d8f38f |
| 5 | AdOptimisationResultMapper (bundled advice-only writer) | 92823ea |
| 4 | RunAdOptimisationJob (advice-only orchestration, no productId) | d904022 |
| 6 | Suggestion kind wiring + advice-only approval | cc44814 |
| 7 | agents:run-ad-optimisation scheduled command + safe no-op | 9e1e6dd |

(Task 5 mapper committed before Task 4 job because the job depends on the mapper.)

## Final tool set

| Tool | name() | Type |
| ---- | ------ | ---- |
| ReadGa4ChannelPerformanceTool | `read_ga4_channel_performance` | read_ (TruncatingTool, 3 KB cap) |
| ReadMarginOpportunityTool | `read_margin_opportunity` | read_ (TruncatingTool, 3 KB cap) |
| ProposeMarketingActionTool | `propose_marketing_action` | propose_ (no-op ack sink) |

`propose_marketing_action` args: `action_type` (enum: shift_budget | increase_investment |
reduce_spend | pause_target | add_coverage), `target`, `rationale`, `supporting_metrics`,
`confidence` (enum low|medium|high).

## Non-negotiables — confirmed

- **ADVICE-ONLY.** No Google writes, no ad_budget_overrides table, no GCLID, no closed-loop.
  No Marketing dashboard. Only side effect = shadow-gated `ad_optimisation` Suggestions in the
  existing inbox.
- **(a) Shadow-mode skip — TESTED.** RunAdOptimisationJobTest Fixture 2: with
  `write_enabled=false` the AgentRun persists (status completed) and ZERO Suggestions are
  written. Fixture 1 proves the write path with `write_enabled=true`.
- **(b) No-data no-op — TESTED.** RunAdOptimisationCommandTest: no recent GA4 rows → exit 0,
  nothing queued; stale rows (outside the 14d lookback) → exit 0, nothing queued; recent rows
  → exactly one RunAdOptimisationJob dispatched; --dry-run → nothing queued.
- **(c) Advice-only approval — TESTED.** AdOptimisationSuggestionApproveActionTest: acknowledging
  an `ad_optimisation` Suggestion flips status to approved and dispatches NO ApplySuggestionJob
  (asserted via Bus::fake); the generic (dispatching) approve action is hidden for the kind.
- Budget cap: reuses the pre-existing `config('agents.daily_caps.ad_optimisation') = 300`.
- Suggestion NOT-NULL defaults (payload/correlation_id) respected. Phase 10/12 agents,
  RuleResolver/PriceCalculator (D-03), and the money path untouched (additive only).

## Deviations from Plan

### Auto-added (Rule 2 — sanctioned-writer architecture requirement)

**1. [Rule 2] Exempted the new job + mapper in AgentsWriteOnlyViaSuggestionsTest**
- **Found during:** Task 5 / Task 4
- **Issue:** `app/Domain/Agents/**` is forbidden from direct DB writes by the architecture test;
  the new mapper (Suggestion::create) and job (AgentRun writes) would trip it.
- **Fix:** Added `Jobs/RunAdOptimisationJob.php` + `Services/AdOptimisationResultMapper.php` to the
  test's notPath() exemption list, exactly as the Phase 10/12 mappers + jobs are exempted.
- **Files modified:** tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php
- **Commit:** 92823ea

Otherwise plan executed as written. Task 5 (mapper) was committed before Task 4 (job) because the
job depends on the mapper at construction — a commit-ordering choice, not a scope change.

## Verify results

- **pest — touched areas (tests/Feature/Agents/Marketing/):** 34 passed (105 assertions).
- **Wider Agents suite (tests/Feature/Agents/):** 213 passed (565 assertions) — no regression.
- **Suggestions suites (tests/Feature/Suggestions + SuggestionInbox + QueryCount + BrandFilter):**
  58 passed (243 assertions) — no regression.
- **AgentToolsNamingTest:** PASS (read_/propose_ naming; ToolBus runtime gate also asserted for
  propose_marketing_action).
- **route:list --path=admin:** exit 0.
- **schedule:list:** shows `agents:run-ad-optimisation` (`0 */6 * * *`), no error.
- **pint:** PASS (`--test` clean on all created + modified files).
- **deptrac analyse:** 0 violations, 0 warnings, 0 errors (Agents→Integrations/Products/Competitor
  edges already allowed — no new allow-list entry needed).

## Known Stubs

None. The propose tool is an intentional no-op ack sink (contract sink, not a stub) — the mapper
materialises the Suggestion, mirroring the Phase 10/12 propose-tool pattern.

## Self-Check: PASSED

- Created files verified present on disk (15 files).
- All 7 task commits verified in git log.
