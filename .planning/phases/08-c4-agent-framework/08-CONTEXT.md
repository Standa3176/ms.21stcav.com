# Phase 8: C4 Agent Framework - Context

**Gathered:** 2026-04-24
**Status:** Ready for planning
**Phase position:** First phase of v2.0 Intelligence + B2B milestone

<domain>
## Phase Boundary

Phase 8 ships the greenfield `app/Domain/Agents/` layer that all subsequent v2 agents (Phase 10 PricingAgent, Phase 12 SeoAgent, Phase 14 ProductFinderAgent, Phase 15 AdAgent) consume. Scope: `RunsAsAgent` contract; `AgentRegistry` service; `AgentRun` ULID Eloquent model with structured-summary snapshots; `BudgetGuard` with **monthly kill-switch + daily soft caps** (resolves the £200/month vs daily-cap math conflict per D-01); `ToolBus` with `propose*`/`read*`/`search*` naming convention enforced architecturally; `GuardrailEngine` chain with pre/post hooks; Prism-based `ClaudeClient` wrapping `prism-php/prism ^0.100.1`; self-hosted Langfuse Docker observability via `mliviu79/laravel-langfuse-prism`; `agents` Horizon supervisor (tries=1, no retry); Deptrac `Agents` layer in BOTH depfile.yaml + deptrac.yaml with `AgentsWriteOnlyViaSuggestionsTest`; `shield:safe-regenerate` artisan wrapper automating P5-F restoration; shadow-mode `AGENT_WRITE_ENABLED` + `AGENT_AUTO_APPLY_ENABLED` flags default false; Filament `AgentRunResource` admin-only.

The 13 REQ-IDs (AGNT-01..13) define the contract surface. This phase ships the framework AND a stub agent (Claude's Discretion: `EchoAgent` proving end-to-end flow without business logic) to verify framework integrity. Phase 10's PricingAgent then becomes the first "real" consumer.

Scope is fixed by ROADMAP.md Phase 8 + REQUIREMENTS.md AGNT-01..13. Discussion resolved 9 implementation decisions (D-01..D-09) covering the budget math conflict and AgentRun retention conflict that REQ-IDs left ambiguous.

</domain>

<decisions>
## Implementation Decisions

### Budget split + monthly ceiling reconciliation (resolves AGNT-04 math conflict)

The math problem: REQ AGNT-04 proposes pricing 500p/seo 300p/chatbot 200p-per-session/ad 300p daily. If all hit daily caps every day, that's ~£11/day = ~£330/month — **65% over the £200/month operator-locked ceiling**.

- **D-01:** **Monthly cap as kill-switch ATOP daily caps.** Two-layer defence:
  - **Layer 1 (per-feature daily soft caps):** matches AGNT-04 spec (pricing 500p, seo 300p, chatbot 200p/session, ad 300p) — prevents any single agent dominating the spend. Enforced via atomic `Cache::add` counters per `agents.daily.{kind}.{YYYY-MM-DD}`.
  - **Layer 2 (global monthly hard ceiling):** £200/month (config: `agents.monthly_ceiling_pence=20000`). Enforced via separate `Cache::add` counter per `agents.monthly.{YYYY-MM}`. Increments AFTER each successful Anthropic call regardless of which agent.
  - When monthly ceiling reaches 100%, ALL new agent dispatches reject with `MonthlyBudgetExceededException`. Daily caps still apply as the inner check.
- **D-02:** **Kill-switch behaviour: block new + complete in-flight.** When `MonthlyBudgetExceededException` fires:
  - In-flight runs (already calling Anthropic) complete normally — no mid-stream truncation
  - Pending queue jobs flip to `status=monthly_budget_blocked` and write a suggestion of kind `agent_budget_exceeded` (so ops sees the kill-switch fired without inbox flooding — first occurrence per month creates one suggestion, subsequent dispatches log to integration_events but don't re-suggest)
  - New `agent:run` invocations from CLI fail fast with the exception + actionable message ("Monthly budget reached — review at /admin/agent-runs or raise via `agents:set-monthly-cap` ops command")
- **D-03:** **Daily cap exceed = hard fail.** Per AGNT-04 spec: throw `BudgetExceededException`, run records `status=budget_exceeded`, surfaces in Filament. Same shape as monthly cap; soft-fail option rejected because it defeats the cap purpose.
- **D-04:** **Day boundary = Europe/London** (matches v1 scheduling convention — all v1 schedules run Europe/London; operator's mental model maps to local time). Cache key TTL aligns to next 00:00 London.
- **D-05:** **Default cap for unknown agent kind = 100 pence/day.** When a new agent kind is first encountered without explicit `config('agents.daily_caps.{kind}')` entry, BudgetGuard applies 100p as fail-safe. Prevents accidental config-drift cost surprise; forces explicit operator choice when scaling an agent up.

### Agent run retention strategy (resolves AGNT-03 vs Phase 1 D-04 conflict)

The conflict: REQ AGNT-03 says `AgentRun` retained indefinitely; Phase 1 D-04 says `audit_log` retained 365 days. AgentRun rows reference activity_log entries via correlation_id — those get pruned at 365d, leaving dangling references.

- **D-06:** **Snapshot relevant audit context ONTO `AgentRun` row.** AgentRun is self-contained:
  - `triggering_suggestion_id` (nullable ULID, indexed)
  - `triggering_correlation_id` (string)
  - `agent_kind` (enum)
  - `tool_calls` (JSON array — each entry: `tool_name`, `inputs`, `outputs`, `tokens_used`, `latency_ms`; each input/output truncated to 4KB)
  - `agent_reasoning_summary` (text, truncated to 8KB — the agent's final output text)
  - `finish_reason` (enum: `end_turn`, `max_tokens`, `tool_use`, `stop_sequence`, `error`)
  - `prompt_token_count`, `completion_token_count`, `cost_pence`
  - `langfuse_trace_id` (string, advisory — used to deep-link to Langfuse for the first 90 days; after Langfuse rolling retention prunes, this column remains for forensic identification but the upstream trace data is gone)
  - `started_at`, `completed_at`, `status` (enum: `running`, `completed`, `failed`, `budget_exceeded`, `guardrail_blocked`, `monthly_budget_blocked`)
  References to v1 audit_log become advisory-only (may be missing post-prune); AgentRun does not depend on them for replay or forensics. Self-contained agent history is cheap (~10KB per run); consistent with Phase 6 GDPR scrub-in-place philosophy.
- **D-07:** **5 years rolling retention horizon.** `AgentRun` rows older than 5 years (1825 days) are exported to JSON archive then pruned. Matches typical UK financial/audit retention norm; sufficient for any audit defence; pruneable so disk doesn't grow unbounded forever. New scheduled command `agents:prune-archive` runs annually (configurable):
  - Exports rows where `completed_at < NOW() - INTERVAL 5 YEAR` to `storage/app/agent-archives/agent-runs-{YYYY}.json.gz`
  - DELETEs the rows after archive write succeeds
  - Logs prune action to `audit_log` with `actor=system` + `description=agent_run_archived`
  - Disk projection: ~5MB/month at 100 runs/day; ~3GB after 5y; archives sit at <100MB compressed
- **D-08:** **Anthropic payload storage = structured summary only (NOT full request/response).** Full payload remains in Langfuse for the 90-day rolling window. AgentRun keeps only the structured summary defined in D-06. Privacy-respecting (E4 chatbot's customer free-text inputs don't persist verbatim past 90d Langfuse retention); manageable disk growth (~10KB/run vs ~50KB if full payload).
- **D-09:** **GDPR erasure on customer-bearing AgentRun rows = scrub in-place.** Mirrors Phase 4 D-13 + Phase 6 GDPR pattern:
  - `tool_calls` JSON entries with PII (customer email/phone/name in inputs or outputs) get values replaced with `REDACTED-{sha256-prefix-of-email}` tokens
  - `agent_reasoning_summary` replaced with `[scrubbed per GDPR erasure {gdpr_log_ulid}]`
  - `cost_pence`, `prompt_token_count`, `completion_token_count`, `agent_kind`, timestamps preserved (financial + operational integrity)
  - `langfuse_trace_id` preserved (Langfuse has its own GDPR erasure pathway — separate trigger via `agents:gdpr-purge-langfuse`)
  - GDPR erasure command `gdpr:erase-bitrix-customer` (Phase 4) extends to call new `AgentRunGdprScrubber` service; mirrors existing pattern. Audit entry written to `gdpr_erasure_log` (Phase 6 table) with `agent_run_ids[]` field listing which AgentRun rows were touched.

### Claude's Discretion

The following areas were not user-discussed — planner/researcher picks the default best-practice approach:

- **Langfuse Docker placement** — Default: same VPS as `ops.meetingstore.co.uk`, behind subdomain `lf.ops.meetingstore.co.uk` with admin-only HTTP basic auth (no public exposure). Postgres + ClickHouse + Langfuse Server containers via `docker-compose.langfuse.yml`. Disk allocation: 2 GB initial, alarm at 5 GB. Trace retention 90 days; cost-aggregate retention 1 year (lighter table). `docs/ops/observability.md` runbook documents start/stop, retention prune cadence, backup posture (mysqldump-equivalent for Postgres), alert thresholds.
- **Stub agent for E2E framework verification** — Default: `EchoAgent` (kind `echo`). Single tool: `read_health_check()` returning timestamp + git SHA. System prompt: "Return the current timestamp and confirm framework health". Run via `php artisan agent:run echo --dry-run` succeeds end-to-end through the framework — produces shadow Suggestion (kind `echo_health`) with the timestamp/SHA in evidence. Used as the AGNT-12 acceptance test fixture. Not a real business consumer; deleted in Phase 10 once PricingAgent proves the pattern. Living `EchoAgent` survives in tests as the canonical "framework smoke test" pattern.
- **MCP PHP SDK adoption** — Default: skip in v2.0. Prism's native `withTools()` covers the C1/C2/C3 pattern adequately; MCP adds a layer of indirection without earning its weight on this milestone. Re-evaluate when Phase 14 chatbot or v2.1 channel-feeds work surfaces a need for cross-process tool composition. STACK.md note added.
- **AGENT_AUTO_APPLY_ENABLED post-cutover policy** — Default: stays `false` permanently in v2.0 even after C1/C2/C3 ships. Per-suggestion-kind override possible via future column `suggestions.auto_apply_eligible` (Phase 8 ships this column nullable; defaults null = manual approval; v2.1 may flip per-kind for low-stakes flows). Manual-approval-only is the v2.0 safety posture.
- **Suggestion provenance morph** — Default: activate Phase 1's existing `proposed_by_type` + `proposed_by_id` morph columns (currently unused). Polymorphic relation `Suggestion::proposedBy()` resolves to either `AgentRun` (for agent producers) or `null` (for rule-based producers like Phase 5 MarginAnalyser). Filament `SuggestionResource` shows "Proposed by: Agent {kind} run {ulid-prefix}" link or "Proposed by: System ({rule-class})" badge. No new model; reuses morph contract.
- **Prompt / system message storage** — Default: per-agent system prompts live in Blade views at `resources/views/agents/{kind}/system.blade.php`. Compiled at agent-instantiation time via `view()->render()` so variables interpolate cleanly. Versioning via git history + `agent_runs.system_prompt_hash` column (sha256 of rendered prompt) — enables querying "all runs that used prompt version X". No DB-stored prompts; no prompt-management UI in v2.0.
- **Token budget pre-flight estimation** — Default: post-flight only. Anthropic's token usage is reported on the response; record actual after each call. Pre-flight estimation is unreliable for tool-use loops where the LLM may invoke 0-N tools. Cache::add increments after the response lands. `tries=1` on the agents queue means a partial multi-tool-call run that exceeds budget mid-stream gets aborted by Prism's `withMaxSteps(8)` cap; the budget delta from in-flight calls is recorded.
- **Trust-tier tagging implementation** — Default: explicit enum `TrustTier { Trusted, Mixed, Untrusted }` passed as constructor arg to `RunsAsAgent::execute(input, TrustTier $tier)`. Pricing/SEO agents lock `Trusted`; ProductFinder chatbot locks `Untrusted`. GuardrailEngine reads the tier and selects pre-run guardrails accordingly (Untrusted gets prompt-injection XML fencing + sensitive-fields strip; Trusted skips both for performance). Compile-time check via Pest architecture test — agent classes must declare their TrustTier in a static method.

### Folded Todos

None — no pending todos matched Phase 8 scope at discussion time.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 Foundation (heavy reuse)

- `.planning/milestones/v1.50.1-ROADMAP.md` §Phase 1 — Foundation seams (DomainEvent, Auditor, IntegrationLogger, BaseCommand, AlertRecipient, suggestions seam, Horizon supervisors, shadow-mode gate pattern). Phase 8 reuses ALL of these.
- v1 phase 01 SUMMARY files (archived in milestones/v1.50.1-phases/ if `/gsd-cleanup` ran; otherwise in git history at tag v1.50.1) — implementation precedents for the patterns Phase 8 follows
- `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` — Phase 8 extends this for kind=`agent_*` providence morph display
- `app/Foundation/Integration/Services/IntegrationLogger.php` — every Anthropic call routes through this
- `app/Domain/Suggestions/Models/Suggestion.php` — `proposed_by_type/id` morph columns activated here (currently unused)

### v2 Research artefacts (CRITICAL — produced 2026-04-24)

- `.planning/research/STACK.md` — Prism ^0.100.1 + Langfuse-Prism shim + custom BudgetGuard rationale (NOT laravel-fuse); explicit anti-recommendations for laravel/ai (PHP 8.3+ floor) + anthropic-ai/sdk (beta)
- `.planning/research/FEATURES.md` §C4 — Agent framework table-stakes vs differentiators; Suggestions seam as load-bearing
- `.planning/research/ARCHITECTURE.md` §Agents — 5 new Deptrac layers; one-way arrow patterns; build-order dependencies
- `.planning/research/PITFALLS.md` §A1-A9 — token runaway (CRITICAL), prompt injection (CRITICAL), DB-write bypass (CRITICAL); mitigation strategies
- `.planning/research/SUMMARY.md` — synthesized v2.0 overview; 8-phase build order; 17 unique operator decisions grouped by blocking horizon

### Phase 4 CRM (suggestion producer pattern reference)

- v1 `CrmPushRetryApplier` registration pattern in `app/Providers/AppServiceProvider.php:142` — Phase 8 follows for `EchoApplier` (stub) + future agent appliers

### Phase 5 Competitor (MarginAnalyser → MarginChangeApplier)

- `MarginChangeApplier` (registered against kind `margin_change` in AppServiceProvider) — Phase 10 PricingAgent enriches existing `margin_change` suggestions; Phase 8 framework must support this enrichment pattern (read existing suggestion + write enrichment fields to its `evidence` JSON without creating a new suggestion)

### Phase 6 ProductAutoCreate (P5-F shield restoration precedent)

- `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php` and the surrounding shield restoration script (commit `dba497c`) — Phase 8 generalises this into `shield:safe-regenerate` artisan command

### Phase 7 Cutover (operator carry-forward gates)

- `cutover:checklist` artisan command in `app/Console/Commands/Cutover/CutoverChecklistCommand.php` — pattern for `agents:checklist` if Phase 8 scope grows; for v2.0 Phase 8 doesn't ship a checklist command, but the structured-gate pattern applies for Phase 15 ad-agent pre-flight

### Project + milestone artefacts

- `.planning/PROJECT.md` §"Current Milestone: v2.0 Intelligence + B2B" — locked operator decisions (£200 budget, self-hosted Langfuse, ~5k SKU)
- `.planning/REQUIREMENTS.md` AGNT-01..13 — 13 contract REQ-IDs Phase 8 satisfies
- `.planning/ROADMAP.md` §Phase 8 — 6 success criteria; build dependencies (none — net-new domain)
- `.planning/STATE.md` — current milestone status, accumulated decisions

### External documentation

- [Prism PHP — Tools & Function Calling](https://prismphp.com/core-concepts/tools-function-calling/) — Phase 8 ClaudeClient implementation reference
- [Anthropic prompt-injection defences](https://www.anthropic.com/research/prompt-injection-defenses) — Phase 8 GuardrailEngine pre-run checks reference
- [Langfuse self-hosted Docker](https://langfuse.com/self-hosting/docker-compose) — Phase 8 observability deployment reference
- [Packagist mliviu79/laravel-langfuse-prism](https://packagist.org/packages/mliviu79/laravel-langfuse-prism) — bus-factor caveat; custom-OTel fallback documented in observability.md

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (v1 delivered)

- **Phase 1 Foundation seams** — every primitive Phase 8 needs already exists:
  - `DomainEvent` base + `ShouldDispatchAfterCommit` for `AgentRunStarted` / `AgentRunCompleted` / `AgentRunFailed` events
  - `BaseCommand` for `agent:run` + `agents:prune-archive` + `shield:safe-regenerate` + `agents:gdpr-purge-langfuse` artisan commands
  - `Auditor` for AgentRun lifecycle audit + GDPR erasure entries
  - `IntegrationLogger` for every Prism HTTP call to Anthropic + every Langfuse trace POST
  - `Context::hydrated` queue boundary correlation_id propagation
  - `ApplySuggestionJob` + `SuggestionApplier` contract — Phase 8 registers `EchoApplier` (stub for kind `echo_health`); later phases register kind-specific appliers
  - `AlertRecipient` Notifiable distribution — extend with `receives_agent_alerts` boolean (matches v1 receives_* pattern from Phase 2/4/5/6/7)
  - `agents` Horizon supervisor pre-allocated in v1 Phase 1 FOUND-09 (along with `whatsapp-inbound` for Phase 13) — Phase 8 just registers jobs onto it
- **Phase 5 SuggestionApplierResolver** (`app/Domain/Suggestions/Services/SuggestionApplierResolver.php`) — kind→class mapping; Phase 8 framework wires registrations through this resolver
- **Phase 6 P5-F shield restoration script** — turns into `shield:safe-regenerate` artisan command (Phase 8 AGNT-11)
- **Phase 1 `Suggestion` model `proposed_by_type/id` morph** — currently unused; Phase 8 activates it for agent provenance
- **`spatie/laravel-activitylog` ^4.12** (Phase 1) — `LogsActivity` trait on `AgentRun` for the audit-log subset; structured-summary snapshots remain on the model directly per D-06
- **`spatie/laravel-permission ^6.0` + `bezhansalleh/filament-shield ^3.3`** — admin-only AgentRunResource via existing role infrastructure; LIKE pattern `%_agent_run` adds to RolePermissionSeeder

### Established Patterns (from v1 SUMMARY files)

- **Migration timestamps:** v1 ended 2026_04_24_*; Phase 8 starts `2026_04_25_*` (planner picks exact minutes)
- **Domain layout:** `app/Domain/Agents/` populated from zero per Phase 8 spec — `Models/AgentRun.php`, `Models/SystemPromptHash.php`, `Services/AgentRegistry.php`, `Services/BudgetGuard.php`, `Services/ToolBus.php`, `Services/GuardrailEngine.php`, `Services/Tools/...` (Tool contract base), `Services/Guardrails/...`, `Clients/ClaudeClient.php`, `Console/Commands/AgentRunCommand.php`, `Console/Commands/AgentsPruneArchiveCommand.php`, `Console/Commands/ShieldSafeRegenerateCommand.php`, `Console/Commands/AgentsGdprPurgeLangfuseCommand.php`, `Filament/Resources/AgentRunResource.php`, `Policies/AgentRunPolicy.php`, `Appliers/EchoApplier.php`, `Agents/EchoAgent.php`, `Events/AgentRunStarted.php`, `Events/AgentRunCompleted.php`, `Events/AgentRunFailed.php`, `Exceptions/BudgetExceededException.php`, `Exceptions/MonthlyBudgetExceededException.php`, `Exceptions/UnauthorisedToolException.php`, `Exceptions/GuardrailViolationException.php`
- **Deptrac dual-YAML lesson (v1 Phase 5 P05-05):** `Agents` layer added to BOTH `depfile.yaml` AND `deptrac.yaml`. Allow-list per AGNT-10: `[Foundation, Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate]` (data domains read-only). NO direct write to any data domain — enforced by `AgentsWriteOnlyViaSuggestionsTest`. Layer also denies imports from `Webhooks`, `Sync`, `Cutover`, `Marketing` (last is v2 Phase 15).
- **PolicyTemplateIntegrityTest:** floor bumped (Phase 7 ended at 26 policies; Phase 8 adds AgentRunPolicy → floor 27). New `shield:safe-regenerate` wrapper auto-restores the new policy after regen.
- **Filament Resource conventions:** `AgentRunResource` follows v1 patterns (Phase 4 CrmPushLogResource read-only filtered view + Phase 7 NotificationCentrePage tabs). Admin-only via Shield permissions — `view_any_agent_run`, `view_agent_run` with no create/update/delete (AgentRuns are produced by the framework, never edited).
- **Testing DB:** `meetingstore_ops_testing` MySQL (Phase 1 P03 lesson). All Phase 8 Feature tests use `RefreshDatabase` against this DB; mock Prism via `Prism::fake()` (or test-shim equivalent — researcher to confirm Prism's testing pattern in 08-RESEARCH.md).
- **Architecture test pattern (`tests/Architecture/`):** Phase 5 + 7 established the exit-code assertion pattern for Deptrac architectural tests. Phase 8 ships:
  - `AgentsWriteOnlyViaSuggestionsTest` — grep-asserts zero `DB::insert/update/delete` calls in `app/Domain/Agents/**` (excluding `Models/AgentRun.php`'s own writes)
  - `AgentToolsNamingTest` — every tool class extends `App\Domain\Agents\Services\Tools\Tool` and has a `name()` returning a string starting with `propose_`, `read_`, or `search_`
  - `DeptracAgentsLayerTest` — positive + negative path against the Agents layer
- **Listener-based extension (v1 invariant):** Phase 8 does NOT modify any v1 code. v1 SuggestionApplierResolver receives a new applier registration in AppServiceProvider; Suggestion model gets a method on it (no new column from Phase 8 — uses existing morph). All other touch points are net-new files.

### Integration Points

- **Inbound (Phase 8 itself doesn't subscribe to events):** Phase 10/12/14/15 will dispatch agent runs from their own listeners (e.g. Phase 10's `EnrichMarginChangeListener` listens to `MarginChangeSuggestionCreated` from v1 Phase 5 → invokes `AgentRegistry::resolve('pricing')->execute(...)`)
- **Outbound (Anthropic API):** `ClaudeClient` wraps Prism's Anthropic provider. All HTTP calls go through Prism → Guzzle → Anthropic. IntegrationLogger captures: endpoint, request body, response body, latency, status, correlation_id. Langfuse auto-instruments via the laravel-langfuse-prism shim.
- **Outbound (Langfuse):** `mliviu79/laravel-langfuse-prism` middleware POSTs traces to `LANGFUSE_HOST=https://lf.ops.meetingstore.co.uk` (config-driven). Trace IDs correlate to `AgentRun.langfuse_trace_id`. Custom-OTel middleware fallback (~150 LOC) ships alongside in case the shim breaks (per STACK.md bus-factor mitigation); shipped INACTIVE as commented-out fallback in `config/agents.php` so operator can swap by uncommenting + restarting Horizon.
- **Outbound (Suggestions):** Agents write via `SuggestionApplierResolver`-resolved `AgentSuggestionWriter` service (NOT direct DB insert). Writer enforces: `proposed_by_type=AgentRun::class`, `proposed_by_id={agent_run.id}`, `kind` from agent's declared output kind, `evidence` from agent output, `correlation_id` from triggering event.
- **Outbound (audit_log via spatie):** `AgentRun` model uses `LogsActivity` trait with `->logOnlyDirty()->logOnly(['status', 'completed_at'])` — captures lifecycle transitions only; full content stays on AgentRun row per D-06 self-contained snapshot pattern. Archival pruning (D-07) writes its own audit_log entry.
- **Outbound (notification distribution):** AlertRecipient with `receives_agent_alerts=true` notified on:
  - First monthly_budget_blocked event of the month (suppressed for subsequent same-month dispatches)
  - Any agent run with `status=failed` (via Phase 1 ThrottledFailedJobNotifier 5-min dedup pattern)
  - First guardrail_blocked of each guardrail kind per day (e.g. first prompt-injection-blocked of the day, first PII-leak-blocked of the day)

### New migrations

- `2026_04_25_010000_create_agent_runs_table.php` — ULID PK + 14 columns per D-06
- `2026_04_25_010100_add_proposed_by_to_suggestions_table.php` — IF morph columns missing (Phase 1 D-14 says they exist; verify; this migration is conditional / no-op if already shipped)
- `2026_04_25_010200_add_auto_apply_eligible_to_suggestions_table.php` — nullable boolean per D-08 Claude's Discretion (preparing for v2.1 per-kind auto-apply)
- `2026_04_25_010300_add_receives_agent_alerts_to_alert_recipients.php` — nullable→default true boolean, backfill existing rows
- `2026_04_25_010400_create_agent_archive_runs_table.php` (optional — if planner picks per-archive-run audit row pattern; otherwise audit_log entry per archive run is sufficient — planner decides)

### New Filament surfaces

- `AgentRunResource` (admin-only, read-only) — list view filtered by kind/status/cost/date; detail view with tool calls + reasoning summary + cost breakdown + Langfuse trace deep-link

### Net stack delta

- `composer require prism-php/prism:^0.100`
- `composer require mliviu79/laravel-langfuse-prism`
- `docker-compose.langfuse.yml` (NEW file in repo root) for self-hosted Langfuse stack
- `docs/ops/observability.md` (NEW file) — Langfuse runbook
- `docs/ops/shield-regeneration.md` (NEW file) — `shield:safe-regenerate` documentation

</code_context>

<specifics>
## Specific Ideas

- **The two-layer budget defence is load-bearing.** Day caps prevent any single agent dominating the day's spend; monthly cap protects the wallet. Both must be in place before the framework ships. Test fixtures should exercise: (a) daily cap hit on one agent doesn't disable other agents, (b) monthly cap hit on aggregate spend disables all agents until next month.
- **AgentRun row is the canonical agent forensics artefact.** Audit_log entries are advisory after 365d. Langfuse traces are advisory after 90d. AgentRun row remains for 5 years with structured summary. This is the column-by-column source of truth for "what did this agent actually do?"
- **`shield:safe-regenerate` is the Phase 8 keystone for v2 hygiene.** Phase 8 ships it, Phases 10/12/14/15 use it. Without it, every later phase repeats the manual P5-F restoration; over 8 phases that's ~3 hours of avoidable rework.
- **EchoAgent is the smoke test, not a deliverable.** It exists to prove Phase 8 framework integrity end-to-end (registry → BudgetGuard → ToolBus → ClaudeClient → Langfuse → SuggestionApplierResolver → Filament display). Phase 10 deletes it once PricingAgent proves the pattern with real business value.
- **Provenance morph activation is cheap but high-leverage.** Phase 1 D-14 already shipped `proposed_by_type/id` columns; Phase 8 just sets them on agent-produced suggestions and surfaces in Filament. Distinguishes "this margin_change was reasoned by an agent" vs "this was rule-based" — critical for audit + iteration on agent prompts.
- **GDPR scrub-in-place mirrors v1 patterns exactly.** AgentRun GDPR handling reuses Phase 4/6 conventions; no new GDPR surface area. Operator runbook for GDPR erasure stays single-page.
- **Default 100p/day cap for unfamiliar agent kinds is the safety net** for accidental future agents (e.g. someone writes a new agent class but forgets to add config entry). Cheap fail-safe; explicit configuration required to scale up.
- **Self-hosted Langfuse on `lf.ops.meetingstore.co.uk` keeps observability under EU-residency compliance.** Same VPS as ops.meetingstore.co.uk, behind admin HTTP basic auth. No public exposure. Postgres + ClickHouse co-tenant on the same VPS — adds ops surface but stays within the cutover-handover footprint operator already manages.

</specifics>

<deferred>
## Deferred Ideas

These came up during analysis but are explicitly scoped out of Phase 8:

- **Per-suggestion-kind auto-apply** (`suggestions.auto_apply_eligible` column) — column ships in Phase 8 per Claude's Discretion D-(unnumbered); applier logic flips to consult the column in v2.1. Phase 8 does NOT wire actual auto-apply behaviour; `AGENT_AUTO_APPLY_ENABLED` stays false.
- **MCP PHP SDK adoption** — Prism's native tools cover v2.0; MCP deferred. Re-evaluate at v2.1 if Phase 14 chatbot or channel-feeds (Phase 8+/v2.1) need cross-process tool composition.
- **Pre-flight token estimation** — post-flight only in v2.0. Pre-flight estimation deferred until ops has 2+ months of real cost data to calibrate against.
- **Prompt management UI** — Blade-files-on-disk in v2.0. DB-stored prompts + Filament prompt editor + version diffing deferred to v2.1+ (or skipped if Blade pattern proves operationally sufficient).
- **Agent provider abstraction** — Claude-only in v2.0 via Prism's Anthropic provider. OpenAI/Gemini fallback wiring at the Prism `withProvider()` level deferred.
- **Token streaming to Filament UI** — Phase 8 returns final result; no live token streaming display. Livewire `wire:stream` available via Filament 3.3 if needed in v2.1 chatbot work.
- **Custom Langfuse self-hosted alerting** — Phase 8 ships Langfuse Docker but does NOT configure alert rules within Langfuse. Ops sets these per their preference post-deploy. Documented in `observability.md`.
- **`agents:checklist` command** — Phase 15 may ship (gated on Phase 8 framework + cutover data). Phase 8 doesn't pre-build the checklist scaffold.
- **Agent versioning** — running same agent kind under different prompt versions concurrently. v2.0 = single active prompt per kind (latest Blade view). Versioning via git history + `system_prompt_hash` column for forensics; no concurrent A/B routing.
- **WhatsApp integration of agent outputs** — Phase 13 + Phase 14 intersection, not Phase 8. Phase 8 ships kind-agnostic suggestion writing; channels consume in their own phases.

### Reviewed Todos (not folded)

No pending todos matched Phase 8 scope at discussion time.

</deferred>

---

*Phase: 08-c4-agent-framework*
*Context gathered: 2026-04-24 — interactive discuss-phase with 9 implementation decisions captured (D-01..D-09)*
