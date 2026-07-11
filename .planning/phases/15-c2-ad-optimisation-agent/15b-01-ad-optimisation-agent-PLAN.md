# 15b-01 — AdOptimisationAgent (advice-only advisory loop, GA4-fed)

**Type:** GSD phase-plan slice (TDD, atomic commits). Executor does NOT push/deploy.
**Parent:** Phase 15 (expanded) — Marketing Intelligence. Builds on 15a (GA4 snapshot) + Phase 8 C4 agent framework.
**Decisions:** D15-1/2/3 (STATE.md) — GA4-first, **ADVICE-ONLY (no external writes)**, writes deferred to 15c.

## Goal
A scheduled Claude-backed **AdOptimisationAgent** that reads the GA4 channel snapshot (15a) **plus the
app's own margin / competitor / stock data** and emits prioritised **Suggestions** (kind
`ad_optimisation`) — e.g. "shift budget from channel A to B", "high-margin in-demand SKUs with weak
paid coverage", "pause underperforming spend". It runs several times a day. It is **advice-only**:
NO writes to Google, NO auto-actioning; every output is a Suggestion a human approves. Built and fully
tested against STUBS (seeded GA4 rows + a mocked ClaudeClient) so it works the moment real GA4 data
flows — and it must **no-op safely when there is no recent GA4 data** (so it's safe to schedule now
without burning LLM spend).

## Non-negotiables
- **ADVICE-ONLY.** No Google Ads writes, no `ad_budget_overrides` table, no GCLID (all 15c). The
  agent's only side effect is writing `ad_optimisation` Suggestions.
- **Shadow-mode gated** like every other agent: the Suggestion write is skipped when
  `config('agents.write_enabled')` is false (the `AgentRun` forensic row still persists). Same as
  RunSeoAgentJob:210.
- **Safe no-op** when `ga_channel_metrics_daily` has no rows in the lookback window — the command logs
  and exits 0 WITHOUT dispatching (no LLM call, no spend). Mirrors 15a-02's no-op philosophy.
- **Approving an `ad_optimisation` Suggestion does nothing external** — no ApplySuggestionJob wiring for
  this kind; approval is acknowledgement only. Verify no apply path fires.

## Template to MIRROR (do not invent — copy the freshest agent, SeoAgent/Phase 12)
- Agent class: `app/Domain/Agents/Agents/SeoAgent.php` (implements `RunsAsAgent`:
  `kind()/trustTier()/tools()/systemPrompt()/guardrails()`; `execute()` is a throwing stub —
  orchestration lives in the job).
- Orchestration: `app/Domain/Agents/Jobs/RunSeoAgentJob.php` — the 13-step sequence (correlation
  thread + Context, registry resolve, PromptRenderer, `AgentRun::create`, events, `BudgetGuard::
  assertHasBudget`, GuardrailEngine pre/post, `ClaudeClient::generate` with `ToolBus::buildPrismTools`,
  extract tool_calls, `AgentRun::update`, `BudgetGuard::recordSpend`, shadow-gated mapper call, budget/
  guardrail/throwable catch arms). Mirror it; the one structural diff: **no `$productId`** — this is a
  single analysis run, not per-product.
- Suggestion write: `app/Domain/Agents/Services/SeoAgentResultMapper.php` — post-run, reads
  `AgentRun.tool_calls` where `tool_name='propose_*'` and CREATEs Suggestion(s). Build an analogous
  `AdOptimisationResultMapper`.
- Read/propose tools: `app/Domain/Agents/Tools/Pricing/*` — read tools have 90d/30d windows, ~3 KB
  output caps + `_truncated` hints; `propose_*` records the proposal (the mapper materialises it). Tool
  naming (`read_*` / `propose_*`) is gate-enforced by `AgentToolsNamingTest` + `ToolBus`.
- Registration: `app/Providers/AppServiceProvider.php` ~L440 — `$registry->register('ad_optimisation',
  AdOptimisationAgent::class)` next to the pricing/seo registrations.
- Scheduled command: `app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php` (BaseCommand +
  `perform()`, correlation id, budget rechecks, `--dry-run`, dispatch to `agents` queue).
- Snapshot source: `App\Domain\Integrations\Models\GaChannelMetric` (15a-02) — Agents→Integrations is
  already an allowed Deptrac edge (ClaudeClient). Own-data reads mirror the Pricing tools' allowed edges.

## Tasks

### Task 1 — Read tools (TDD, ~3 KB caps + `_truncated`)
`app/Domain/Agents/Tools/Marketing/`:
- `ReadGa4ChannelPerformanceTool` (`read_ga4_channel_performance`) — aggregate `ga_channel_metrics_daily`
  over the last 30 days by channel_group + campaign: sessions, key_events, transactions,
  revenue (pennies→£). Returns compact JSON, capped.
- `ReadMarginOpportunityTool` (`read_margin_opportunity`) — own data: top high-margin, in-stock products
  and their competitor position (reuse the read paths the Pricing tools already use for margin /
  competitor / stock — do NOT duplicate query logic if a service already exposes it; otherwise a
  focused read is fine). Purpose: let the agent spot high-margin SKUs worth advertising. Capped.
Tests: each tool returns capped JSON over seeded data; `_truncated` hint when over cap.

### Task 2 — Propose tool (TDD)
`ProposeMarketingActionTool` (`propose_marketing_action`) in `Tools/Marketing/`. Arguments (Prism schema):
`action_type` (enum: `shift_budget` | `increase_investment` | `reduce_spend` | `pause_target` |
`add_coverage`), `target` (string — channel/campaign/sku/free-text), `rationale` (string),
`supporting_metrics` (string/JSON), `confidence` (enum low|medium|high). The tool RECORDS the proposal
(returns an ack string); the mapper materialises the Suggestion post-run (SEO pattern). No side effects.
Test: the tool validates args and returns an ack; invalid `action_type` rejected at the schema level.

### Task 3 — `AdOptimisationAgent` + registration (TDD)
`app/Domain/Agents/Agents/AdOptimisationAgent.php implements RunsAsAgent`: `kind()='ad_optimisation'`,
`trustTier()=Trusted` (scheduled, no customer input), `tools()` = the 2 read + 1 propose tools,
`systemPrompt()` via PromptRenderer('ad_optimisation'), `guardrails()` = the same 2 the PricingAgent
uses (SensitiveFieldsStrip + OutboundRegexFilter), `execute()` throws (orchestration in the job).
System prompt: `resources/views/agents/ad_optimisation/system.blade.php` — instruct the model to act as
a cautious paid-media/marketing analyst: read the tools, then call `propose_marketing_action` for each
concrete, evidence-backed recommendation; advice-only; never fabricate metrics. Register in
AppServiceProvider. Test: registry resolves 'ad_optimisation' → AdOptimisationAgent; tool list + trust
tier + budget cap (config `agents.daily_caps.ad_optimisation` = 300) assertions.

### Task 4 — `RunAdOptimisationJob` (TDD, mirror RunSeoAgentJob)
`app/Domain/Agents/Jobs/RunAdOptimisationJob.php` — mirror RunSeoAgentJob's orchestration WITHOUT a
productId (constructor takes only `?string $batchCorrelationId`). `AgentRun` kind='ad_optimisation'.
Build the user message from a compact summary of the lookback window (or just let the tools fetch).
Shadow-gated mapper call (`config('agents.write_enabled')`); all four catch arms (Monthly/Budget/
Guardrail/Throwable) as in RunSeoAgentJob; events; Langfuse fields; `onQueue('agents')`; `$tries=1`,
`$timeout=180`. Tests: mock `ClaudeClient::generate` to return a steps/response with a
`propose_marketing_action` tool call → assert AgentRun Completed + tool_calls persisted; with
write_enabled=true the mapper writes a Suggestion(kind=ad_optimisation), with false it does NOT (shadow).
Budget-exceeded path sets status + rethrows.

### Task 5 — `AdOptimisationResultMapper` (TDD)
`app/Domain/Agents/Services/AdOptimisationResultMapper.php` — reads `AgentRun.tool_calls` where
`tool_name='propose_marketing_action'` and creates Suggestion rows (kind `ad_optimisation`, status
pending, payload = the proposal fields + agent_reasoning + agent_run_id + correlation_id, evidence =
supporting_metrics). One Suggestion per proposal (or bundled — match SeoAgentResultMapper's shape;
bundled is fine). Respect the Suggestion model's NOT-NULL columns (payload/correlation_id — see the
Suggestion booted() defaults from 260708-gy0). Test: given an AgentRun with 2 propose calls → 2 (or 1
bundled) Suggestions with correct kind/payload; idempotent-ish (don't double-write on re-map — key by
agent_run_id).

### Task 6 — Suggestion kind wiring + advice-only approval (TDD)
- Add `'ad_optimisation'` to the `SuggestionResource` `kind` SelectFilter options and ensure
  `getEloquentQuery()` does NOT hide it (it hides `agent_guardrail_blocked` only).
- Confirm the generic approve action treats `ad_optimisation` as acknowledgement — NOT in the
  kind-specific approve list, and NO ApplySuggestionJob/apply path is wired for it. Add a test:
  approving an `ad_optimisation` Suggestion flips status to approved and triggers NO external
  job/write (assert via Bus::fake / no queued apply job).

### Task 7 — Scheduled command + safe no-op (TDD)
`agents:run-ad-optimisation` (BaseCommand/perform; mirror RunSeoAgentBatchCommand). Pre-flight:
count `GaChannelMetric` rows with `date >= today()->subDays(config or 14)`. **If zero → log
`ad_optimisation.noop_no_data` + exit 0 (NO dispatch, NO spend).** Else generate a correlation id and
`RunAdOptimisationJob::dispatch($correlationId)`. Support `--dry-run` (report eligibility, no dispatch).
Schedule in `routes/console.php`: `Schedule::command('agents:run-ad-optimisation')->everySixHours()
->withoutOverlapping()` (London TZ convention) — safe because of the no-op + the daily budget cap.
Tests: with seeded GA4 rows → dispatches (Bus::fake asserts RunAdOptimisationJob queued); with none →
no-op exit 0, nothing queued; `--dry-run` dispatches nothing.

## Verify
- `pest` on all touched areas (tools, agent registration, job orchestration incl. shadow + budget,
  mapper, suggestion-kind + advice-only approval, command no-op/dispatch) — GREEN. Run the wider
  Agents + Suggestions suites for no regression.
- `AgentToolsNamingTest` + any tool-contract tests GREEN (read_*/propose_* naming).
- `php artisan route:list --path=admin` exit 0; `php artisan schedule:list` shows
  `agents:run-ad-optimisation` without error.
- `pint` pass on touched files.
- `vendor/bin/deptrac analyse` → **0 violations** (Agents→Integrations/Products/Pricing/Competitor
  edges already exist; if a NEW edge is needed, note why — do not silently add allow-list entries).

## Guardrails / out of scope
- ADVICE-ONLY. No Google writes, no ad_budget_overrides, no GCLID, no closed-loop (15c). No Marketing
  DASHBOARD (that's 15b-02 — suggestions surface in the existing Suggestions inbox filtered by kind).
- Do NOT modify the Phase 10/12 agents, RuleResolver/PriceCalculator (D-03 byte-locks), or the money
  path. Additive only.
- Do NOT stage the pre-existing working-tree noise (`storage/app/research/supplier-probe.json`,
  `tests/Unit/Competitor/CompetitorIngestFreshnessColorTest.php`, untracked `.claude/`).
- Driver-portable (SQLite tests / MariaDB prod). PHP/composer via Herd (~/.config/herd/bin/php84/php.exe).
- No push, no deploy. Atomic commits per task. Write `15b-01-SUMMARY.md` on completion (commit SHAs,
  the tool set, shadow-mode + no-op + advice-only-approval test results, verify results).
