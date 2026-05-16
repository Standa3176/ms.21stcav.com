# Phase 12: C3 SEO / Content Agent - Context

**Gathered:** 2026-05-07
**Status:** Ready for planning
**Phase position:** Fourth v2 phase consumer (after Phase 8 framework + Phase 10 PricingAgent + Phase 11 Quote flow); second **real** Phase 8 framework consumer

<domain>
## Phase Boundary

Phase 12 ships a `SeoAgent implements RunsAsAgent` (kind `seo`) that batches over Phase 6 AutoCreate drafts where `auto_create_status=pending_review` AND `completeness_score < 85`. The agent proposes content patches for `title` / `short_description` / `long_description` / `meta_description` via the contracted tools (`read_product_draft`, `read_brand_style_guide`, `read_similar_shipped_products`, `propose_content_patch`). Approved patches write to `Product.{field}` (canonical) and set the matching `ProductOverride.pin_{field}=true` so subsequent supplier sync skips that field.

Each agent run emits **one bundled Suggestion** per product (kind `seo_content_patch`) whose payload lists every patched field. The existing Phase 6 `AutoCreateReviewResource` gains a sidebar panel showing the four diffs with per-field approve checkboxes. Out-of-policy generations (competitor brand names, unsupported price claims, marketing superlatives) are caught by an outbound regex guardrail; failed runs produce kind `agent_guardrail_blocked` Suggestions (not surfaced to admin) plus an audit row on `AgentRun.guardrail_failures`.

Trust tier is locked `Trusted` (no untrusted input — inputs come from supplier feeds + AutoCreate drafts). Budget ceiling `seo_agent.daily_pence_cap=300` per Phase 8 D-01 two-layer defence; £200/month global hard ceiling already enforced framework-side. Triggered by **nightly batch** (one scheduled run per night, up to 20 drafts/run; remainder rolls overnight to the next run) per success criterion 1.

The 5 SEOAGT-* requirements pin the contract surface. Discussion resolved 4 implementation decisions (D-01..D-04) covering brand-voice content source, voice scope, patch granularity, and approval write-through target. The remaining ambiguities (confidence band, rejection inbox shape, scheduled time-of-day) fall under Claude's Discretion because the user accepted simpler defaults than Phase 10 PricingAgent on those axes.

</domain>

<decisions>
## Implementation Decisions

### Brand-voice content + guardrail patterns (SEOAGT-02, SEOAGT-04)

- **D-01: Brand-voice content lives in Blade-renderable markdown files; guardrail regex patterns live in `config/seo_agent.php`.**
  - Global default: `resources/agents/brand-voice/_global.md` — the canonical MeetingStore tone-of-voice doc (no-jargon, factual, AV-installer expertise). Git-tracked, edited via PR.
  - Per-brand overrides: `resources/agents/brand-voice/{brand-slug}.md` — optional files for brands needing distinct voice (e.g. `logitech.md`, `poly.md`). Slug derived from `Product.brand` (lowercase, hyphen-separated).
  - The `read_brand_style_guide(brand)` tool reads `{brand-slug}.md` if present, else falls back to `_global.md`. Always returns at least the global content — never null.
  - Tool response shape: `{ brand: 'logitech', source: 'per-brand'|'global', content: '<markdown>', _bytes: N }`.
  - Guardrail patterns: `config/seo_agent.php` returns nested arrays under `guardrails` key:
    ```php
    'guardrails' => [
        'competitor_brands' => ['video conferencing.*by zoom', 'cisco webex', '/* ... */'],
        'price_claims_absolute' => ['/cheapest/i', '/best price/i', '/* ... */'],
        'marketing_superlatives' => ['/revolutionary/i', '/groundbreaking/i', '/* ... */'],
    ]
    ```
  - `SeoOutboundGuardrail` reads the config, compiles the regex array once per run, applies post-generation to each proposed `before`/`after` text. First match → fail entire run (do NOT publish ANY patch from that run) → write `agent_guardrail_blocked` Suggestion with `evidence.failed_pattern_key` + `evidence.matched_excerpt` + `AgentRun.guardrail_failures` audit row. No partial publishing.

- **D-02: Hybrid voice scope.** Global voice file is mandatory; per-brand override files are optional. The agent always has *something* to read; per-brand variation can be added incrementally without blocking the framework. New brands inherit global voice automatically.

### Patch granularity + Filament sidebar UX (SEOAGT-02, SEOAGT-03)

- **D-03: One bundled Suggestion per agent run per product.**
  - `propose_content_patch(sku, field, before, after, reasoning)` is invoked 1-4 times per product during the Prism tool-loop (one call per field the agent thinks needs patching).
  - After tool-loop completes, `SeoAgentResultMapper` (mirror of `PricingAgentResultMapper`) collects ALL `propose_content_patch` calls from `AgentRun.tool_calls[]` and writes ONE Suggestion of kind `seo_content_patch`:
    ```
    Suggestion {
      kind: 'seo_content_patch',
      payload: {
        product_id: 123,
        sku: 'LOGI-MEETUP',
        patches: [
          { field: 'title', before: '...', after: '...', reasoning: '...' },
          { field: 'short_description', before: '...', after: '...', reasoning: '...' },
          /* up to 4 entries */
        ],
        agent_run_id: '<ulid>',
      }
    }
    ```
  - If the agent makes no patch proposals (run completed but `propose_content_patch` was never called), NO Suggestion is created — `AgentRun.evidence.agent_run_status='no_patches'` for visibility.
  - **Filament sidebar panel** on `AutoCreateReviewResource` infolist (right column, below v1 completeness): renders 1-4 diff rows (one per patched field) with a checkbox per row + a single "Approve selected" footer button. Each diff shows `before` (current value) and `after` (proposed) side-by-side, monospace, truncated to 200 chars with full-text reveal on hover. Reasoning appears as a collapsible "why" toggle under each diff.

### Approval write-through (SEOAGT-03)

- **D-04: Approved patch writes Product.{field} canonical + sets `ProductOverride.pin_{field}=true`. No new schema columns.**
  - The pin flag IS the override signal (Phase 6 semantics — verified `ProductOverride` already has `pin_title`, `pin_short_description`, `pin_long_description`, `pin_meta_description` columns).
  - Workflow on approve:
    1. Update `Product.{field}` with the patch's `after` value
    2. Upsert `ProductOverride` for the product, setting `pin_{field}=true` (preserves other pin flags)
    3. Mark the parent Suggestion's `payload.patches[N].applied_at` for the approved indices
    4. If all 4 fields approved → flip Suggestion `status='applied'`. If subset → status stays `'pending'` with the unapproved patches still visible (admin can return later to approve more).
    5. Auditor records `seo.content_patch_applied` with `{product_id, field, agent_run_id, before_hash, after_hash}`. Before/after are hashed (not stored verbatim) to keep audit log lean — full values stay on the Suggestion payload.
  - **Subsequent supplier sync behaviour:** existing Phase 6 logic respects `ProductOverride.pin_X=true` and skips that field on supplier-driven updates. No new behaviour required.

### Claude's Discretion

Areas not user-discussed — planner/researcher picks the default best-practice approach:

- **Confidence band: SKIP.** Phase 10 PricingAgent included LOW/MOD/HIGH confidence for financial decisions. SEO patches are creative outputs reviewed by a human in the AutoCreate sidebar — confidence adds inbox noise without changing approval behaviour. Admin's eyes are the calibration.
- **Rejection inbox: SKIP dedicated page.** Phase 10's rejection inbox triages prompt drift for financial reasoning. For SEO, rejected patches are just "this wording wasn't right" — surface in the existing Suggestion list with a kind filter; no need for a dedicated Filament page. Iteration happens via PR to the brand-voice markdown files.
- **Temperature = 0.4.** Phase 8 STACK.md defaults temp=0 (deterministic) for pricing; REQUIREMENTS.md line 124 explicitly allows higher temp for SEO/chatbot with guardrails. 0.4 balances creativity (genuine paraphrasing) with reproducibility (re-runs on same draft produce similar — not identical — proposals). Set in `config('agents.seo.temperature', 0.4)`; planner can revisit during calibration.
- **`withMaxSteps(8)` matches Phase 10** — agent needs ≤4 patch proposals + 3 read tools + 1 safety margin. Same Prism ceiling.
- **Scheduled time-of-day = 04:30 Europe/London** (between competitor:ftp-pull Sun+Wed 02:00 and the 07:00 supplier:db-sync). Avoids the 03:00 prune cascade. Single nightly run per success criterion 1. Schedule entry uses `cron('30 4 * * *')` (daily, not weekday-only — drafts accumulate every day).
- **Batch eligibility query** = `Product::where('auto_create_status', 'pending_review')->where('completeness_score', '<', 85)->whereDoesntHave('suggestions', fn ($q) => $q->where('kind', 'seo_content_patch')->whereIn('status', ['pending','applied']))->limit(20)`. Skips products that already have an unresolved or applied SEO suggestion — re-runs only happen if a prior suggestion is rejected. Order: `completeness_score ASC` (worst-first).
- **Idempotency.** Re-running an eligible draft (e.g. after a rejection) creates a NEW AgentRun + a NEW Suggestion. Old suggestions stay in history with status='rejected'. No `agent_run_ids[]` array on SEO (different from Phase 10's enrichment model — SEO patches are full replacements, not enrichments).
- **Tool implementation files** at `app/Domain/Agents/Tools/Seo/{ReadProductDraftTool, ReadBrandStyleGuideTool, ReadSimilarShippedProductsTool, ProposeContentPatchTool}.php`. Each extends Phase 8's `Tool` base; each has a Pest unit test exercising the response shape.
- **`SeoAgent` class location:** `app/Domain/Agents/Agents/SeoAgent.php` (mirrors PricingAgent placement).
- **Migrations:** ZERO needed. ProductOverride pin columns already exist; Suggestion table already supports the new kind; no new audit-log columns.
- **Permissions.** New Shield permission `run_seo_agent` (admin + pricing_manager get it; sales + read_only do not). Apply via `shield:safe-regenerate`.
- **Test scope.** Pest Feature tests for: 4 tools (response shape + bytes cap), `SeoAgentResultMapper` (extracts bundled patches from tool_calls), `RunSeoAgentJob` (queue test with mocked Anthropic), `SeoAgentScheduledCommand` (eligible-query selects right products, caps at 20, skips already-suggested), Filament sidebar panel component test (4-field diff render + per-field approve), guardrail short-circuit test (regex match → guardrail_blocked Suggestion, no patches applied). Total: ~20-25 Pest cases.

### Folded Todos

None — no pending todos matched Phase 12 scope at discussion time.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 8 Agent Framework (heavy reuse — every primitive Phase 12 needs)

- `.planning/phases/08-c4-agent-framework/08-CONTEXT.md` — 9 decisions D-01..D-09 (budget two-layer defence, AgentRun retention, GDPR scrub, Blade system prompts, provenance morph, structured-summary snapshot)
- `.planning/phases/08-c4-agent-framework/08-RESEARCH.md` — Prism API, Langfuse shape, BudgetGuard atomic increments, ToolBus naming
- `app/Domain/Agents/Contracts/RunsAsAgent.php` — contract `SeoAgent` implements
- `app/Domain/Agents/Services/AgentRegistry.php` — Phase 12 registers `seo` kind here in AppServiceProvider
- `app/Domain/Agents/Services/ToolBus.php` — Phase 12 tool registration via per-agent allow-list
- `app/Domain/Agents/Services/Tools/Tool.php` — base class Phase 12 tools extend
- `app/Domain/Agents/Services/BudgetGuard.php` — Phase 12 invokes pre-run; `seo.daily_pence_cap=300` config key
- `app/Domain/Agents/Services/GuardrailEngine.php` — Phase 12 adds new `SeoOutboundGuardrail` as a post-run hook; Trusted-tier preset for pre-run
- `app/Domain/Agents/Clients/ClaudeClient.php` — Phase 12 instantiates with `claude-sonnet-4-6` + `temperature=0.4` + `withMaxSteps(8)`
- `app/Domain/Agents/Models/AgentRun.php` — `triggering_suggestion_id` is null for SEO (batch-driven, not suggestion-pull); `triggering_correlation_id` set per draft

### Phase 10 PricingAgent (mirror pattern — copy + adapt)

- `.planning/phases/10-c1-pricing-agent/10-CONTEXT.md` — Phase 10's 11 decisions; particularly D-04 (tool I/O windowing + caps), D-05 (3KB soft cap + _truncated hint), D-06 (mapper extracts final tool call), Claude's Discretion (Blade system prompt + Trusted tier + EchoAgent deletion patterns)
- `app/Domain/Agents/Agents/PricingAgent.php` — class skeleton to mirror for SeoAgent
- `app/Domain/Agents/Tools/Pricing/*.php` — 5 tool implementations; SEO mirrors the 3-KB-cap + truncation-hint pattern
- `app/Domain/Agents/Mappers/PricingAgentResultMapper.php` (or equivalent location) — extracts final tool call from `AgentRun.tool_calls[]`; SEO mapper extracts ALL `propose_content_patch` calls into a bundled Suggestion
- `app/Domain/Agents/Jobs/RunPricingAgentJob.php` — Phase 12 has both a single-product job (used by re-run from rejection) and a scheduled batch command; the per-product job mirrors this file

### Phase 6 AutoCreate (Phase 12's integration target)

- `.planning/phases/06-product-auto-create/06-CONTEXT.md` — completeness score formula, pending_review semantics, pin_X column intent
- `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php` — Phase 12 EXTENDS the detail/infolist with the SEO sidebar panel
- `app/Domain/Pricing/Models/ProductOverride.php` — verified `pin_title` / `pin_short_description` / `pin_long_description` / `pin_meta_description` columns exist (lines 42-45 in fillable, cast to bool)
- `app/Domain/Products/Models/Product.php` — `auto_create_status`, `completeness_score`, `completeness_missing_fields`, `name`, `short_description`, `long_description`, `meta_description` are all fillable

### Phase 1 Foundation (audit + suggestion seam)

- `app/Domain/Suggestions/Models/Suggestion.php` — Phase 12 adds new kind `seo_content_patch`; `proposed_by_type=AgentRun::class`, `proposed_by_id=AgentRun.id`
- `app/Domain/Suggestions/Services/SuggestionApplierResolver.php` — Phase 12 registers new `SeoContentPatchApplier` for kind `seo_content_patch`
- `app/Foundation/Audit/Services/Auditor.php` — Phase 12 records `seo.content_patch_applied` per approved field
- `app/Foundation/Integration/Services/IntegrationLogger.php` — every Anthropic call routes through this (inherited from Phase 8 ClaudeClient)

### Project + milestone artefacts

- `.planning/PROJECT.md` §"Current Milestone: v2.0 Intelligence + B2B" — operator decisions (£200 monthly budget, self-hosted Langfuse, ~5k SKU)
- `.planning/REQUIREMENTS.md` §"C3. SEO / Content Agent (SEOAGT) — Phase 12" — SEOAGT-01..05 contract surface; line 124 confirms temp>0 allowed for SEO with guardrails

### Brand-voice docs (NEW — Phase 12 will create)

- `resources/agents/brand-voice/_global.md` — global MeetingStore tone-of-voice (mandatory, planner writes first draft)
- `resources/agents/brand-voice/{brand-slug}.md` — optional per-brand overrides (planner ships an empty directory + one example file for Logitech as a pattern)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **Phase 8 `Tool` base class** + ToolBus naming enforcement — 4 new SEO tools subclass this with zero framework changes
- **Phase 8 `BudgetGuard`** with `seo.daily_pence_cap=300` config slot already provisioned (Phase 8 D-05 default fail-safe)
- **Phase 10 `RunPricingAgentJob` + result mapper pattern** — Phase 12 copies this shape almost verbatim, swapping in Seo tools + bundled-patch mapper logic
- **Phase 6 `AutoCreateReviewResource` infolist** — already renders product fields; Phase 12 adds a sidebar Filament section with the diff panel
- **`ProductOverride` pin columns** — exact columns already exist for all 4 SEO-patchable fields; zero migrations needed
- **`Suggestion.proposed_by_type/id` morph** activated in Phase 8 — Phase 12 sets `proposed_by_type=AgentRun::class` for forensic provenance

### Established Patterns

- **Trusted-tier agents** skip pre-run prompt-injection / PII guardrails per Phase 8 D-(unnumbered); SEO inputs are internal supplier data + AutoCreate drafts, never customer text. Post-run brand-voice guardrail is the load-bearing one for SEO
- **Blade system prompts** at `resources/views/agents/{kind}/system.blade.php` with sha256 hash on `agent_runs.system_prompt_hash` for version forensics
- **Per-tool 3 KB soft cap + `_truncated` hint** (Phase 10 D-05) — SEO tools follow this; particularly important for `read_similar_shipped_products` which could otherwise return 50+ products
- **Mapper extracts from `AgentRun.tool_calls[]` after Prism tool-loop completes** (Phase 10 D-06) — SEO mapper bundles all `propose_content_patch` calls into one Suggestion payload instead of taking the last one
- **Scheduled batch commands** follow the routes/console.php convention with `withoutOverlapping(60)` + `onOneServer()` + `timezone('Europe/London')`

### Integration Points

- `app/Providers/AppServiceProvider.php` — register `SeoAgent::class` in `AgentRegistry` + register new `SeoContentPatchApplier` in `SuggestionApplierResolver` + register new `RunSeoAgentScheduledCommand` in `commands()` block
- `routes/console.php` — new schedule entry: `Schedule::command('agents:run-seo-batch')->cron('30 4 * * *')->onOneServer()->timezone('Europe/London')`
- `config/agents.php` — extend `daily_caps` array with `seo => 300` (likely already present per Phase 8 D-05); add `agents.seo.temperature => 0.4`
- `config/seo_agent.php` (NEW) — `guardrails` array with 3 pattern keys
- `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php` — add Filament `Section` to infolist for SEO patches sidebar
- Shield permissions seeded via `shield:safe-regenerate` after the new SeoContentPatchPolicy + RunSeoAgentBatchPolicy land

</code_context>

<specifics>
## Specific Ideas

- **Brand-voice markdown structure** (per `_global.md` planner draft): three sections — "Tone & voice", "Words to use", "Words to avoid". Planner ships a first draft based on the MeetingStore frontend's existing product descriptions as the reference voice.
- **Logitech example file** (`logitech.md`) is the on-disk pattern showing how per-brand override works — short doc (~50 lines) noting Logitech-specific terminology ("RightSense", "MeetUp", etc.) that the global voice doesn't need.
- **Filament sidebar layout** mirrors the existing PricingAgent enrichment panel pattern from Phase 10 D-10: title bar with "SEO content patches (N proposed)" + collapsible per-field diff blocks with checkbox-select + footer "Approve selected" action.

</specifics>

<deferred>
## Deferred Ideas

- **Per-suggestion confidence band** (Phase 10 pattern) — defer to v2.1 if SEO patch quality calibration needs it; v2.0 ships without confidence scoring because admin eyes are the validation step
- **Dedicated rejection inbox page** (Phase 10 pattern) — defer to v2.1 if prompt-iteration cadence justifies a dedicated triage view; v2.0 uses the standard Suggestion list with kind filter
- **Auto-apply for SEO patches** (`agents.seo.auto_apply_threshold`) — never planned; SEO patches are content choices, admin review is the load-bearing gate. Don't add this even if PricingAgent gets one
- **AGENT_SEO_AUTO_ENRICH_ENABLED flag** — not relevant; SEO is already batch-scheduled, no event-driven enrichment branch to gate
- **DB-managed brand voice + guardrails via Filament** — deferred per D-01; revisit if ops needs frequent edits without dev involvement (low-likelihood for v2.0)

</deferred>

---

*Phase: 12-c3-seo-content-agent*
*Context gathered: 2026-05-07*
