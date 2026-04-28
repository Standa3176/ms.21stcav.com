# Phase 10: C1 Pricing Agent - Context

**Gathered:** 2026-04-28
**Status:** Ready for planning
**Phase position:** Third v2 phase (after Phase 8 framework + Phase 9 trade-pricing decorator); first **real** consumer of the Phase 8 agent framework

<domain>
## Phase Boundary

Phase 10 ships the first real Phase 8 framework consumer: a `PricingAgent implements RunsAsAgent` (kind `pricing`) that enriches v1's existing Phase 5 `margin_change` Suggestions with LLM reasoning + confidence + proposed margin band. Scope: 5 read-only/propose-only tools (`read_margin_history`, `read_competitor_prices`, `read_supplier_price_trend`, `read_sales_volume_90d`, `propose_margin_band`); admin-pull invocation only (Filament button on suggestion detail page) — no auto-trigger; latest-wins idempotency via `evidence.agent_run_ids[]` array; per-tool 90-day rolling windows aligned with Phase 5's sales-threshold-90d window; per-tool 3 KB soft caps with `_truncated`/`_total_available` hints; agent's `propose_margin_band` is structured-contract output only (a no-op writer that gets extracted by `PricingAgentResultMapper` after Prism's tool-loop completes); v1's existing Phase 5 `MarginChangeApplier` approve workflow unchanged; agent reasoning + confidence (0-100, prompt-anchored to LOW/MODERATE/HIGH bands) + proposed band displayed in `SuggestionResource` margin_change detail view alongside v1's deterministic evidence; v1-margin-outside-agent-band flagged via red OUT-OF-BAND chip + approve-with-reason modal (audit-logged); rejection captured via structured form (`misleading? Y/N/Partial` radio + mandatory note) into a dedicated `/admin/agent-runs/rejection-inbox` Filament page for prompt-iteration triage; no auto-prompt-feedback. Trust-tier locked `Trusted` per PRCAGT-05; budget cap 500 pence/day per Phase 8 D-01..D-05.

The 5 PRCAGT-* requirements pin the contract surface. EchoAgent (Phase 8 smoke-test fixture) gets DELETED in this phase — PricingAgent becomes the canonical real-agent reference.

Scope is fixed by ROADMAP.md Phase 10 + REQUIREMENTS.md PRCAGT-01..05. Discussion resolved 11 implementation decisions (D-01..D-11) covering trigger UX, idempotency, tool I/O windowing, payload caps, propose-tool semantics, confidence calibration, out-of-band conflict UX, and structured rejection feedback.

</domain>

<decisions>
## Implementation Decisions

### Trigger + idempotency (PRCAGT-01)

- **D-01:** **Admin-pull only — Filament button on suggestion detail.** New "Run pricing agent" action on `SuggestionResource`'s margin_change detail view dispatches `RunPricingAgentJob` on the Phase 8 `agents` Horizon queue. NO listener on Phase 5's `MarginChangeSuggestionCreated` event in v2.0 — auto-trigger would burn budget on suggestions that may be auto-rejected; tension with PRCAGT-05 "trust-tier=trusted (admin-triggered only)". Auto-trigger via `AGENT_PRICING_AUTO_ENRICH_ENABLED` env flag is a v2.1 candidate once daily-cap calibration lands against real traffic. Rationale: Trusted-tier means admin-triggered means admin-clicked; literal interpretation of PRCAGT-05.
- **D-02:** **Latest-wins idempotency via `evidence.agent_run_ids[]` array.** PRCAGT-01 says re-runs gate on `evidence.agent_run_id` null — Phase 10 EXTENDS this to a plural array. New runs append to `evidence.agent_run_ids[]`; latest run's reasoning + confidence + band overwrite the displayed enrichment fields (`evidence.agent_reasoning`, `evidence.agent_confidence_0_to_100`, `evidence.agent_proposed_band_min_bps`, `evidence.agent_proposed_band_max_bps`). Filament shows latest reasoning prominently with collapsible "Previous agent runs (N)" history listing prior AgentRun ULIDs + timestamps + statuses. Re-run button is ALWAYS visible for any non-running latest run (admin can iterate prompts, retry after failure, retry after rejection-with-misleading-flag). Iteration captured forever via Phase 8's 5-year AgentRun retention.
- **D-03:** **One-at-a-time per suggestion lock.** Re-run button disabled while latest agent_run.status=running for that suggestion. Prevents accidental double-burn. Lock check: query `agent_runs` where `triggering_suggestion_id={suggestion.id}` AND `status='running'` LIMIT 1; if found, button shows "Agent running..." with spinner + ETA. No queue stacking; second click after completion only.

### Tool I/O shapes (PRCAGT-02)

- **D-04:** **90-day rolling window aligned with Phase 5 sales-threshold-90d.** All four read_* tools answer with consistent time scope:
  - `read_margin_history(sku)` → last 90d of price changes; cap at 30 entries; if more, downsample to evenly-spaced 30 entries
  - `read_competitor_prices(sku)` → last 90d competitor_prices rows; cap at 50 most recent across all competitors; group by competitor in response shape
  - `read_supplier_price_trend(sku)` → last 90d of supplier_price changes; cap at 30 entries
  - `read_sales_volume_90d(sku)` → reads cached `products.last_sales_count_90d` column (Phase 5 ships this) — no live aggregation; if cache stale (`sales_count_computed_at` > 24h old), tool returns the cached value plus `_cache_age_hours` field so agent knows freshness
  - `propose_margin_band(sku, proposed_bps, reasoning, confidence_0_to_100, band_min_bps, band_max_bps)` → structured tool-call output only (no-op writer; logs the call); see D-06
- **D-05:** **Per-tool 3 KB soft caps + `_truncated` hint.** Each read_* tool caps response payload at ~3000 chars of JSON output. If data exceeds cap, return truncated set + `_truncated: true` + `_total_available: N` count. Agent can request narrower window via follow-up tool call (Prism's tool-loop with `withMaxSteps(8)` accommodates iterative narrowing). Predictable per-tool input-token cost; prevents pathological 50KB responses on highly-tracked SKUs (Logitech MeetUp scenario where 30+ competitors × 90d = thousands of rows). Implementation: each tool's response builder enforces the cap pre-serialisation; downsampling logic per-tool (margin_history evenly-spaced; competitor_prices most-recent-per-competitor first).
- **D-06:** **`propose_margin_band` = pure structured tool-call output.** Tool implementation is a no-op writer that records the call to `agent_run.tool_calls[]` (Phase 8 D-06 structured-summary snapshot). After Prism's tool-loop finishes, `PricingAgentResultMapper` extracts the FINAL `propose_margin_band` call from `tool_calls[]` and copies into `Suggestion.evidence.agent_proposed_band_min_bps`/`max_bps` + `Suggestion.evidence.agent_reasoning` + `Suggestion.evidence.agent_confidence_0_to_100`. Cleanest separation: tools = data shape, mapper = persistence. Agent may call `propose_margin_band` multiple times during reasoning; only the final call wins (logged history retained on agent_run). If agent never calls `propose_margin_band` (e.g. ran out of `withMaxSteps(8)` budget), mapper writes `evidence.agent_run_status='no_proposal'` for visibility.

### Confidence + band semantics + Filament UX (PRCAGT-03, PRCAGT-04)

- **D-07:** **Prompt-instructed confidence bands with anchor examples.** System prompt explicitly defines:
  - **0-30 LOW:** data sparse OR conflicting signals OR recent volatility (e.g. <5 sales in 90d, <3 competitors tracked, supplier price moved >15% in last 30d)
  - **31-70 MODERATE:** some support, some uncertainty (typical case — moderate sales volume, 2-4 competitors, stable supplier)
  - **71-100 HIGH:** strong consistent signal, multi-source corroboration (>20 sales/90d, ≥4 competitors all moving same direction, stable supplier, clear margin-delta trend)

  Few-shot examples in prompt show 1-2 cases per band. Filament badge: red <31, amber 31-70, green ≥71. Calibration improves over time via prompt iteration; no scoring algorithm code on our side. `agent_confidence_0_to_100` stored as integer on `Suggestion.evidence`.
- **D-08:** **Out-of-band conflict UX: visual chip + approve-with-reason modal.** When `Phase 5 deterministic proposed margin_bps` falls OUTSIDE `[agent_proposed_band_min_bps, agent_proposed_band_max_bps]`:
  - Filament detail view shows side-by-side comparison cards: "v1 deterministic: 2200 bps" vs "agent band: 1800-2050 bps"
  - Red OUT-OF-BAND chip on the agent-band card
  - Approve action remains ENABLED (admin still has final authority — PRCAGT-04 "Approve action unchanged")
  - On click, confirmation modal asks: "v1 margin is outside agent's confidence band [1800-2050]; proceed anyway?" + free-text mandatory reason field
  - Reason captured to `audit_log` with `actor=user`, `description=approved_margin_change_out_of_band`, `properties={suggestion_id, agent_run_id, deterministic_bps, band_min, band_max, reason}`
  - Out-of-band approvals also tagged in the `Suggestion.evidence.out_of_band_approval` JSON for direct dashboard query (no audit_log join required)

  Doesn't gate decisions on the agent (preserves "agent enriches deterministic, never replaces" PRCAGT-01 principle); surfaces the disagreement for prompt iteration.
- **D-09:** **Structured rejection feedback form + dedicated Filament inbox.** When admin rejects a margin_change suggestion that has agent enrichment, the rejection modal asks:
  1. **"Was agent reasoning misleading?"** radio: Yes / No / Partially
  2. **"Notes"** mandatory free-text field (min 10 chars to prevent empty-noise rejections)

  Stored in new column `Suggestion.evidence.agent_rejection_feedback = {misleading: 'yes'|'no'|'partial', notes: string, rejected_by_user_id: int, rejected_at: timestamp}`. NEW Filament page `/admin/agent-runs/rejection-inbox` (admin + pricing_manager view) lists rejected agent runs filtered by `misleading=yes`/`misleading=partial` for dev/prompt-iteration triage. NO auto-prompt-feedback to the model in v2.0 (compounding-drift risk; defer to v2.1 once stable rejection-data corpus exists).

- **D-10:** **Filament SuggestionResource margin_change detail view layout.**
  - Top row: v1 deterministic evidence card + agent enrichment card (side-by-side at desktop; stacked on mobile)
  - V1 card: existing Phase 5 evidence (last 3 competitor prices, our_current, proposed margin_bps, sales_count_90d, pricing_rule)
  - Agent card: `agent_reasoning` (markdown-rendered text), `agent_confidence_0_to_100` (badge with band label), `agent_proposed_band` (range chip "1800-2050 bps")
  - "Run pricing agent" or "Re-run pricing agent" button at top-right of agent card (per D-01/D-02/D-03 trigger rules)
  - "Previous agent runs (N)" collapsible below agent card (per D-02 — array of agent_run_ids with timestamp + status + Langfuse trace deep-link if available)
  - Approve / Reject / Apply actions remain in their existing positions (no v1 layout disruption)

### Failure / blocked / no-proposal handling

- **D-11:** **Agent run terminal-state visibility on suggestion detail.**
  - `agent_run.status=running` → button disabled, spinner shown, "Agent running..." chip
  - `agent_run.status=completed` → enrichment fields populated; standard layout
  - `agent_run.status=failed` → red chip "Last agent run failed (click for details)"; re-run button visible; clicking the chip opens AgentRun detail in modal/drawer with failure reason from `tool_calls[-1].outputs.error`
  - `agent_run.status=budget_exceeded` → amber chip "Daily budget hit — try after midnight (London)"; re-run button visible (will fail again until midnight)
  - `agent_run.status=monthly_budget_blocked` → red chip "Monthly budget reached — escalate to admin"; re-run button DISABLED until next month
  - `agent_run.status=guardrail_blocked` → red chip "Guardrail blocked (kind: prompt_injection|pii_leak|other)"; re-run visible; rare for trusted-tier pricing agent (no customer input) but framework pattern preserved
  - Agent ran but emitted no `propose_margin_band` (Prism withMaxSteps exhausted before proposal): `evidence.agent_run_status='no_proposal'`; UI shows amber chip "Agent finished without proposal — re-run to retry"

### Claude's Discretion

Areas not user-discussed — planner/researcher picks the default best-practice approach:

- **System prompt design (Phase 8 Claude's Discretion: Blade view at `resources/views/agents/pricing/system.blade.php`).** Default contents:
  - **Persona:** "You are a pricing analyst for a UK B2B AV reseller. You analyse competitor pricing data, supplier price trends, and sales volumes to propose margin bands. You prioritise predictability over aggressive optimisation. You never invent data; if a tool returns sparse data, you reflect that in low confidence."
  - **Workflow:** Sequence is suggested (read margin_history → competitor_prices → supplier_price_trend → sales_volume_90d → propose_margin_band) but agent may interleave; agent may call read_* tools multiple times for different SKUs if reasoning requires
  - **Confidence rubric:** anchor examples per D-07 LOW/MODERATE/HIGH bands inline
  - **Output contract:** mandatory final `propose_margin_band` call with reasoning ≥40 chars, confidence integer 0-100, band_min_bps ≤ band_max_bps, both bps integers ≥0
  - **Few-shot examples:** 2 worked cases (one HIGH-confidence "data-rich" SKU, one LOW-confidence "data-sparse" SKU) showing tool sequence + final propose_margin_band shape
  - **Versioning:** prompt hash stored on `agent_runs.system_prompt_hash` (Phase 8 D-(unnumbered)); git history is the version history; no DB-stored prompts; no prompt-management UI
- **Temperature lock = 0** confirmed (Phase 8 STACK.md default); deterministic outputs given same input. Cache eligibility: same `(sku, evidence_hash)` returning identical reasoning is acceptable (audit-logged via `system_prompt_hash` + tool I/O history).
- **EchoAgent deletion.** Phase 8's `EchoAgent` smoke-test fixture is DELETED in this phase as PRCAGT proves the framework with real business value. EchoAgent's contract test remains in `tests/Feature/Agents/FrameworkSmokeTest.php` (renamed) using a fixture stub agent class declared inline within the test, not a domain class.
- **Filament page route:** `/admin/agent-runs/rejection-inbox` lives under `app/Filament/Pages/AgentRunRejectionInboxPage.php` (admin panel page, not a Resource — single-purpose triage view). Sortable by `rejected_at desc`; filterable by `misleading` enum; columns: SKU, suggestion_id deep-link, agent confidence, deterministic bps, agent band, misleading flag, notes excerpt, rejected_at.
- **Tool implementation files** at `app/Domain/Agents/Tools/Pricing/{ReadMarginHistoryTool, ReadCompetitorPricesTool, ReadSupplierPriceTrendTool, ReadSalesVolume90dTool, ProposeMarginBandTool}.php`. Each extends Phase 8's `Tool` base; each has a Pest unit test exercising the 3KB soft cap + `_truncated` hint.
- **`PricingAgent` class location:** `app/Domain/Agents/Agents/PricingAgent.php` (Phase 8 D-09 — agent classes co-located with Tools/ for the layer; not in a Domain/Pricing subdirectory because `Agents` layer reads from Pricing but doesn't write to it).
- **Listener for `MarginChangeSuggestionCreated`:** NOT shipped in this phase per D-01. Reserved for v2.1 if/when auto-trigger is justified.
- **Admin permission:** new Shield permission `run_pricing_agent` (verb-prefix following Phase 8 AGNT-10 convention). Admin gets it; pricing_manager gets it (they own pricing decisions); sales does not; read_only does not. Re-run from rejection inbox: same permission. Apply Pitfall P5-F via `shield:safe-regenerate` artisan wrapper (Phase 8 D-(unnumbered)).
- **Migrations:** `2026_04_28_010000_add_agent_rejection_feedback_to_suggestions_table.php` adds `agent_rejection_feedback` JSON column (nullable). No other migrations — all enrichment data lives in existing `Suggestion.evidence` JSON.
- **Test scope:** Pest Feature tests for the 5 tools (each with 90d window + 3KB cap + `_truncated` hint test cases); PricingAgentResultMapper unit test; SuggestionResource detail view component test (out-of-band chip + approve-with-reason modal); RunPricingAgentJob queue test; AgentRunRejectionInboxPage authorisation test; idempotency test (re-run appends to evidence.agent_run_ids[]). Total: ~25-30 Pest cases.

### Folded Todos

None — no pending todos matched Phase 10 scope at discussion time.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 8 Agent Framework (heavy reuse — every primitive Phase 10 needs)

- `.planning/phases/08-c4-agent-framework/08-CONTEXT.md` — 9 decisions D-01..D-09 (budget two-layer defence, AgentRun retention, GDPR scrub-in-place, Blade system prompts, provenance morph activation, structured-summary snapshot)
- `.planning/phases/08-c4-agent-framework/08-RESEARCH.md` — Prism API surface, Langfuse trace shape, BudgetGuard atomic increments, ToolBus naming convention enforcement
- `app/Domain/Agents/Contracts/RunsAsAgent.php` — contract Phase 10 PricingAgent implements
- `app/Domain/Agents/Services/AgentRegistry.php` — Phase 10 registers `pricing` kind here in AppServiceProvider
- `app/Domain/Agents/Services/ToolBus.php` — Phase 10 tool registration via per-agent allow-list
- `app/Domain/Agents/Services/Tools/Tool.php` — base class Phase 10 tools extend
- `app/Domain/Agents/Services/BudgetGuard.php` — Phase 10 invokes pre-run; PRCAGT-05 `pricing.daily_pence_cap=500` config key
- `app/Domain/Agents/Services/GuardrailEngine.php` — Phase 10 uses Trusted-tier preset (no prompt-injection / PII strip needed for trusted-internal-only flow)
- `app/Domain/Agents/Clients/ClaudeClient.php` — Phase 10 instantiates with default `claude-sonnet-4-6` + `temperature=0` + `withMaxSteps(8)` + `withMaxTokens(4000)`
- `app/Domain/Agents/Models/AgentRun.php` — `triggering_suggestion_id` populated by Phase 10
- `app/Domain/Agents/Models/AgentRun::tool_calls` JSON — Phase 10 reads back to extract final `propose_margin_band` (D-06)
- `app/Domain/Agents/Console/Commands/ShieldSafeRegenerateCommand.php` — Phase 10 runs this when adding new policies / permissions

### Phase 5 Competitor Analysis (margin_change Suggestion producer — Phase 10 enriches)

- `.planning/phases/05-competitor-analysis/05-CONTEXT.md` — D-05 thresholds (8% margin delta, 3 consecutive scrapes, 10 sales/90d), D-06 debounce, D-07 evidence payload shape (last 3 prices, our_current, proposed, sales_count_90d, pricing_rule)
- `app/Domain/Competitor/Services/MarginAnalyser.php` — produces deterministic `proposed_margin_bps` that Phase 10 enrichment compares against
- `app/Domain/Competitor/Appliers/MarginChangeApplier.php` — registered against kind `margin_change`; approve action UNCHANGED by Phase 10
- `app/Domain/Competitor/Services/SalesCounterService.php` — `read_sales_volume_90d` tool reads `products.last_sales_count_90d` cached column; tool returns cached value with `_cache_age_hours` if `sales_count_computed_at > 24h ago`
- `app/Domain/Competitor/Events/MarginChangeSuggestionCreated.php` — NOT subscribed to in Phase 10 (admin-pull only per D-01); referenced for v2.1 auto-trigger consideration

### Phase 1 Foundation (audit + alerting)

- `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` — Phase 10 EXTENDS the margin_change detail view per D-10 layout
- `app/Foundation/Audit/Services/Auditor.php` — Phase 10 logs out-of-band approvals with structured properties per D-08
- `app/Foundation/Integration/Services/IntegrationLogger.php` — every Anthropic call routes through this (inherited from Phase 8 ClaudeClient)
- `app/Domain/Suggestions/Models/Suggestion.php` — `proposed_by_type/id` morph (Phase 8 D-(unnumbered) activated); Phase 10 sets `proposed_by_type=AgentRun::class` on enrichment

### Phase 3 Pricing Engine (PricingRule context for Suggestion.evidence)

- `app/Domain/Pricing/Models/PricingRule.php` — referenced via `Suggestion.evidence.pricing_rule.id`; tools may surface rule.scope in reasoning context but do NOT modify rules
- `app/Domain/Pricing/Services/PriceCalculator.php` — referenced for context; tools do NOT call (PriceCalculator is the deterministic computer; agents read history, not live computation)
- v1 RuleResolver byte-identical (Phase 9 B-03 verified) — Phase 10 tools may surface rule chain context but never mutate

### v2 Project + milestone artefacts

- `.planning/PROJECT.md` §"Current Milestone: v2.0 Intelligence + B2B" — operator decisions (£200 monthly budget, self-hosted Langfuse, ~5k SKU)
- `.planning/REQUIREMENTS.md` PRCAGT-01..05 — 5 contract REQ-IDs Phase 10 satisfies
- `.planning/ROADMAP.md` §Phase 10 — 5 success criteria; depends on Phase 8 only (Phase 9 not a dependency — pricing agent reads margin_change which Phase 5 produces; Phase 9 trade-pricing affects margin computations but Phase 10's agent reads HISTORY not live computation)
- `.planning/STATE.md` — current milestone status, accumulated decisions

### External documentation

- [Prism PHP — Tools & Function Calling](https://prismphp.com/core-concepts/tools-function-calling/) — Phase 10 tool implementation reference
- [Anthropic — Pricing best practices for prompts](https://docs.anthropic.com/en/docs/build-with-claude/prompt-engineering) — system prompt design reference
- [Langfuse — Filtering by tags](https://langfuse.com/docs/observability) — Phase 10 sets trace tags `kind=pricing`, `suggestion_id={ulid}`, `out_of_band={bool}` for ops triage

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (Phase 8 + Phase 5 + Phase 1 delivered)

- **Phase 8 framework primitives** (every agent infra Phase 10 needs already exists):
  - `RunsAsAgent` contract (Phase 10 PricingAgent implements)
  - `AgentRegistry` (Phase 10 registers `pricing` kind in AppServiceProvider)
  - `ToolBus` with allow-list (Phase 10 declares 5 tools as PricingAgent's allow-list)
  - `Tool` base class (Phase 10's 5 tools extend)
  - `BudgetGuard` (Phase 10 invokes pre-run with `kind='pricing'`; config `agents.daily_caps.pricing=500`)
  - `GuardrailEngine` Trusted preset (Phase 10 uses without modification)
  - `ClaudeClient` (Phase 10 instantiates with default settings)
  - `AgentRun` model + table (Phase 10 reads `tool_calls[]` post-run; mapper extracts final `propose_margin_band`)
  - `AgentRunStarted/Completed/Failed` events (Phase 10 emits via the framework, no new events)
  - `agents` Horizon supervisor (Phase 10 dispatches `RunPricingAgentJob` onto it)
  - `BudgetExceededException` + `MonthlyBudgetExceededException` + `GuardrailViolationException` (Phase 10 catches per D-11 status mapping)
  - `ShieldSafeRegenerateCommand` (Phase 10 runs after adding `run_pricing_agent` permission)
  - `AgentRunResource` Filament (admin-only, read-only — Phase 10 adds nothing here; PricingAgent runs surface in the existing AgentRun list)
- **Phase 5 + Phase 1 + Phase 3 primitives:**
  - `MarginAnalyser` deterministic computation (read by tools as historical context; never invoked live by tools)
  - `Suggestion` model with `evidence` JSON column (Phase 10 reads + writes `agent_*` keys per D-02/D-06/D-08)
  - `Suggestion.proposed_by_type/id` morph (Phase 8 D-(unnumbered); Phase 10 sets to AgentRun on enrichment)
  - `MarginChangeApplier` (UNCHANGED — approve action keeps existing path; Phase 10 only adds enrichment + UI)
  - `Auditor` for out-of-band approval audit (Phase 1 D-04)
  - `products.last_sales_count_90d` + `sales_count_computed_at` (Phase 5 columns; `read_sales_volume_90d` tool reads these)
  - `competitor_prices` table (Phase 5; `read_competitor_prices` tool reads with 90d window + per-competitor grouping)
  - `pricing_rules` + `product_overrides` (Phase 3 + Phase 6); read-only references in evidence

### Established Patterns (from Phase 8)

- **Migration timestamps:** Phase 9 ended `2026_04_26_*`; Phase 10 starts `2026_04_28_*` (planner picks exact minutes; one migration only — `add_agent_rejection_feedback_to_suggestions_table`)
- **Domain layout:** `app/Domain/Agents/Agents/PricingAgent.php`, `app/Domain/Agents/Tools/Pricing/{Read*Tool, ProposeMarginBandTool}.php`, `app/Domain/Agents/Services/PricingAgentResultMapper.php`, `app/Domain/Agents/Jobs/RunPricingAgentJob.php`, `app/Filament/Pages/AgentRunRejectionInboxPage.php`, `resources/views/agents/pricing/system.blade.php`
- **Deptrac dual-YAML lesson:** Phase 8 already shipped `Agents` layer in BOTH depfile.yaml + deptrac.yaml. Phase 10 adds NO new layers; existing allow-list `[Foundation, Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate]` covers everything. `AgentsWriteOnlyViaSuggestionsTest` already enforces no-direct-DB-write from `app/Domain/Agents/`. `AgentToolsNamingTest` already enforces tool naming.
- **PolicyTemplateIntegrityTest:** floor stays at 27 (no new policies; new permission `run_pricing_agent` ships via Shield seeder, not a new policy class). After `shield:safe-regenerate` re-runs, integrity test still passes.
- **EchoAgent fixture deletion:** Phase 8 EchoAgent (`app/Domain/Agents/Agents/EchoAgent.php` + `app/Domain/Agents/Appliers/EchoApplier.php` + `tests/Feature/Agents/EchoAgentTest.php`) gets DELETED. Smoke-test contract migrates to a fixture stub agent class declared inline within `tests/Feature/Agents/FrameworkSmokeTest.php`.
- **Suggestion.evidence JSON evolution:** Phase 10 reads + writes new keys: `agent_run_ids[]` (replaces single `agent_run_id`), `agent_reasoning`, `agent_confidence_0_to_100`, `agent_proposed_band_min_bps`, `agent_proposed_band_max_bps`, `agent_run_status`, `out_of_band_approval{}`, `agent_rejection_feedback{}`. Phase 5 v1 evidence keys preserved (no breakage).
- **Testing DB:** `meetingstore_ops_testing` MySQL (Phase 1 P03 lesson). Phase 10 Feature tests use `RefreshDatabase`; mock Prism via the same `Prism::fake()` shim Phase 8 EchoAgent tests use.

### Integration Points

- **Inbound (admin-pull):** Filament `SuggestionResource::detail` view "Run pricing agent" action → dispatches `RunPricingAgentJob::dispatch($suggestion->id, $user->id)` on `agents` queue
- **Inbound (re-run from rejection inbox):** `AgentRunRejectionInboxPage` row action "Re-run on this suggestion" → same job dispatch path
- **Outbound (Anthropic):** `RunPricingAgentJob::handle()` → resolves `PricingAgent` from `AgentRegistry` → calls `BudgetGuard::checkAndIncrement('pricing')` → instantiates `ClaudeClient` → runs Prism tool-loop → `PricingAgentResultMapper::mapToSuggestion(agent_run, suggestion)` writes enrichment to `Suggestion.evidence`
- **Outbound (Suggestion enrichment):** Mapper writes via fluent `evidence` array merge — never a direct `DB::update()`. Mapper service lives in Agents layer; reads/writes Suggestion model via Eloquent (allowed via Phase 8 AGNT-10 Suggestion → Agents allow-list).
- **Outbound (audit on out-of-band approve):** Phase 5 MarginChangeApplier extension — when `Suggestion.evidence.out_of_band_approval` is set during approve action, `Auditor::log('approved_margin_change_out_of_band', $suggestion, ['agent_run_id' => ..., 'deterministic_bps' => ..., 'band' => ..., 'reason' => ...])`
- **Outbound (rejection feedback):** Phase 1 SuggestionResource reject action extension — when `kind=margin_change` AND `evidence.agent_run_ids[]` is non-empty, modal asks structured questions; persists to `Suggestion.evidence.agent_rejection_feedback`
- **New Filament surfaces:** `AgentRunRejectionInboxPage` (single page, admin + pricing_manager view); `SuggestionResource` margin_change detail view extension (additive — no v1 layout disruption)
- **Net stack delta:** ZERO new composer packages; ZERO new docker services; ZERO new env vars (PRCAGT-05's daily cap + Phase 8 framework env flags suffice).

</code_context>

<specifics>
## Specific Ideas

- **Admin-pull is the safest v2.0 default.** Every margin_change suggestion comes from Phase 5's deterministic noise-suppressed pipeline — admin already triages these manually. Adding agent enrichment as opt-in click rather than auto-trigger preserves the existing workflow + keeps budget predictable. Auto-trigger is a v2.1 candidate after the daily cap is calibrated against actual click-through volumes.
- **Latest-wins idempotency via array protects iterative prompt improvement.** Devs iterate on the system prompt over weeks; admin reruns shows the new prompt's reasoning without losing the audit trail of what previous prompts said. This is the right choice for a v2.0 "we're learning what works" phase.
- **`propose_margin_band` as structured-contract no-op writer** is Phase 8's tool naming convention applied literally. Tool side-effects belong in the mapper, not in the tool. Mapper is where validation + persistence + edge-case handling lives.
- **3 KB soft caps with `_truncated` hint** is more agent-friendly than hard errors. Agent gets a signal it can reason about and adapt; humans don't have to debug "why did the agent fail on this SKU" — they can see the truncation in agent_run.tool_calls and tune the cap or window.
- **Out-of-band approve-with-reason** preserves admin authority while creating the audit trail needed for prompt iteration. Don't gate on the agent (PRCAGT-01); do log when admin overrides agent confidence (D-08 audit_log entry + evidence flag for direct dashboard query).
- **Structured rejection feedback** is the prompt-iteration data source. Without the misleading: yes/no/partial flag, all rejections look the same; with it, devs can filter "show me all rejections where agent reasoning was misleading" + see the notes + tune the prompt.
- **EchoAgent deletion is a small but honest signal:** Phase 8 was scaffolding; Phase 10 is real. The framework-smoke-test contract survives in a fixture stub within tests, not as a deployed agent class.
- **Tool I/O caps + 90d window are the budget guardrails.** Daily 500p cap is the wallet defence; tool windowing + caps are the input-token defence; `withMaxSteps(8)` is the loop defence. All three together make the £200/month ceiling actually achievable.

</specifics>

<deferred>
## Deferred Ideas

These came up during analysis but are explicitly scoped out of Phase 10:

- **Auto-trigger via listener on `MarginChangeSuggestionCreated`** — v2.1 candidate. Requires daily-cap calibration against real admin-pull volumes first. `AGENT_PRICING_AUTO_ENRICH_ENABLED` env flag scaffolding deferred until v2.1.
- **Agent confidence calibration via mapper-computed signal-strength** — agent self-report only in v2.0 (D-07). Mapper-computed dual-track deferred to v2.1 once we have N rejection-misleading data points to assess agent self-report drift.
- **Auto-prompt-feedback (rejection notes auto-summarised into next system prompt)** — v2.1+ candidate. Compounding-drift risk; needs human-in-the-loop review per rejection batch first.
- **Multi-LLM provider fallback** (OpenAI/Gemini via Prism's `withProvider()`) — Claude-only in v2.0 per Phase 8.
- **Pre-flight token estimation** — post-flight only in v2.0 per Phase 8 D-08.
- **Token streaming display on Filament UI** — final-result only in v2.0 per Phase 8 deferred. Livewire `wire:stream` available for v2.1 chatbot work (Phase 14).
- **Per-brand prompt variants** (e.g. Logitech-aware persona) — single prompt per kind in v2.0 per Phase 8 D-(unnumbered). Per-brand variants deferred to v2.1+.
- **Agent-initiated rule edits** (agent calls `propose_pricing_rule_change` tool that creates new suggestion kind) — explicit Out of Scope for Phase 10. Future tool-class candidate but expands the AGNT-10 allow-list deny-list deliberately.
- **Confidence-score-driven auto-apply** (e.g. agent confidence ≥90 + within band = auto-apply) — explicit Out of Scope; AGENT_AUTO_APPLY_ENABLED stays false in v2.0 per Phase 8 D-(unnumbered).
- **Real-time cost ticker on Filament UI** showing daily spend remaining — Phase 7 dashboard `WeeklyReportStatusWidget` is the v2.0 surface; per-run cost is on AgentRunResource detail view. Real-time ticker deferred.
- **Cross-suggestion batch enrichment** ("enrich all 5 pending margin_change suggestions" bulk action) — single-suggestion only in v2.0. Bulk action would re-introduce auto-trigger budget concerns.
- **Tool result caching across agent runs** (e.g. `read_competitor_prices(sku=X)` cached for 1h within a single day) — uncached in v2.0 for predictable input-token usage. Defer to v2.1 if cost data shows it'd help.

### Reviewed Todos (not folded)

No pending todos matched Phase 10 scope at discussion time.

</deferred>

---

*Phase: 10-c1-pricing-agent*
*Context gathered: 2026-04-28 — interactive discuss-phase with 11 implementation decisions captured (D-01..D-11)*
*Phase position: First real consumer of Phase 8 agent framework; EchoAgent (Phase 8 smoke fixture) deleted*
