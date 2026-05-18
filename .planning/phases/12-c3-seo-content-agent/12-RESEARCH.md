# Phase 12: C3 SEO / Content Agent — Research

**Researched:** 2026-05-16
**Domain:** Second real consumer of Phase 8's `app/Domain/Agents/` framework. `SeoAgent` nightly-batches Phase 6 AutoCreate drafts (`auto_create_status=pending_review` AND `completeness_score < 85`), proposes 1–4 field content patches (title / short_description / long_description / meta_description) via Anthropic Claude, and surfaces a bundled Suggestion in `AutoCreateReviewResource` for per-field admin approval. Approved patches write through to `Product.{field}` canonical + `ProductOverride.pin_{field}=true` so subsequent supplier sync respects the override.
**Confidence:** HIGH on Phase 8 framework primitives (verified by direct read of `RunPricingAgentJob`, `PricingAgentResultMapper`, `PricingAgent`, `TruncatingTool`, `Suggestion`, `ProductOverride`, `AutoCreateReviewResource`, `config/agents.php`, `routes/console.php`); HIGH on the mirror-from-Phase-10 pattern (every primitive Phase 12 needs is byte-identical to a Phase 10 file shipped 2026-05-03); MEDIUM on brand-voice regex pattern library starter set (no battle-tested OSS marketing-copy guardrail library exists — synthesis from training data, calibration in Plan 12-03); MEDIUM on Filament sidebar diff render shape (no diff library currently in vendor/ — recommendation is hand-rolled inline truncate-with-tooltip, not a library port).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Brand-voice content + guardrail patterns (SEOAGT-02, SEOAGT-04)**

- **D-01:** **Brand-voice content lives in Blade-renderable markdown files; guardrail regex patterns live in `config/seo_agent.php`.**
  - Global default: `resources/agents/brand-voice/_global.md` — the canonical MeetingStore tone-of-voice doc (no-jargon, factual, AV-installer expertise). Git-tracked, edited via PR.
  - Per-brand overrides: `resources/agents/brand-voice/{brand-slug}.md` — optional files for brands needing distinct voice. Slug derived from `Product.brand` (lowercase, hyphen-separated).
  - The `read_brand_style_guide(brand)` tool reads `{brand-slug}.md` if present, else falls back to `_global.md`. Always returns at least the global content — never null.
  - Tool response shape: `{ brand: 'logitech', source: 'per-brand'|'global', content: '<markdown>', _bytes: N }`.
  - Guardrail patterns: `config/seo_agent.php` returns nested arrays under `guardrails` key (`competitor_brands`, `price_claims_absolute`, `marketing_superlatives`).
  - `SeoOutboundGuardrail` reads the config, compiles the regex array once per run, applies post-generation to each proposed `before`/`after` text. **First match → fail entire run** (do NOT publish ANY patch from that run) → write `agent_guardrail_blocked` Suggestion with `evidence.failed_pattern_key` + `evidence.matched_excerpt` + `AgentRun.guardrail_failures` audit row. No partial publishing.

- **D-02:** **Hybrid voice scope.** Global voice file is mandatory; per-brand override files are optional. The agent always has *something* to read; per-brand variation can be added incrementally without blocking the framework. New brands inherit global voice automatically.

**Patch granularity + Filament sidebar UX (SEOAGT-02, SEOAGT-03)**

- **D-03:** **One bundled Suggestion per agent run per product.**
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
  - If the agent makes no patch proposals, NO Suggestion is created — `AgentRun.evidence.agent_run_status='no_patches'` for visibility.
  - **Filament sidebar panel** on `AutoCreateReviewResource` infolist: renders 1-4 diff rows (one per patched field) with a checkbox per row + a single "Approve selected" footer button. Each diff shows `before` (current value) and `after` (proposed) side-by-side, monospace, truncated to 200 chars with full-text reveal on hover.

**Approval write-through (SEOAGT-03)**

- **D-04:** **Approved patch writes Product.{field} canonical + sets `ProductOverride.pin_{field}=true`. No new schema columns.**
  - The pin flag IS the override signal — verified `ProductOverride` already has `pin_title`, `pin_short_description`, `pin_long_description`, `pin_meta_description` columns ([VERIFIED: `app/Domain/Pricing/Models/ProductOverride.php:42-65`]).
  - Workflow on approve:
    1. Update `Product.{field}` with the patch's `after` value
    2. Upsert `ProductOverride` for the product, setting `pin_{field}=true` (preserves other pin flags)
    3. Mark the parent Suggestion's `payload.patches[N].applied_at` for the approved indices
    4. If all approved → flip Suggestion `status='applied'`. If subset → status stays `'pending'` with the unapproved patches still visible.
    5. Auditor records `seo.content_patch_applied` with `{product_id, field, agent_run_id, before_hash, after_hash}`.
  - **Subsequent supplier sync behaviour:** existing Phase 6 logic respects `ProductOverride.pin_X=true` and skips that field on supplier-driven updates. No new behaviour required.

### Claude's Discretion

- **Confidence band: SKIP.** Phase 10 included LOW/MOD/HIGH confidence for financial decisions. SEO patches are creative outputs reviewed by a human — confidence adds inbox noise without changing approval behaviour.
- **Rejection inbox: SKIP dedicated page.** Phase 10's rejection inbox triages prompt drift for financial reasoning. For SEO, rejected patches are just "this wording wasn't right" — surface in the existing Suggestion list with a kind filter.
- **Temperature = 0.4.** REQUIREMENTS.md line 124 explicitly allows higher temp for SEO/chatbot with guardrails. 0.4 balances creativity (paraphrasing) with reproducibility. Set in `config('agents.seo.temperature', 0.4)`.
- **`withMaxSteps(8)` matches Phase 10** — agent needs ≤4 patch proposals + 3 read tools + 1 safety margin.
- **Scheduled time-of-day = 04:30 Europe/London** (between competitor:ftp-pull Sun+Wed 02:00 and the 07:00 supplier:db-sync). Single nightly run; `cron('30 4 * * *')`.
- **Batch eligibility query** = `Product::where('auto_create_status', 'pending_review')->where('completeness_score', '<', 85)->whereDoesntHave('suggestions', fn ($q) => $q->where('kind', 'seo_content_patch')->whereIn('status', ['pending','applied']))->limit(20)`. Order: `completeness_score ASC` (worst-first).
- **Idempotency.** Re-running an eligible draft (after a rejection) creates a NEW AgentRun + a NEW Suggestion. Old suggestions stay in history with status='rejected'. No `agent_run_ids[]` array on SEO.
- **Tool implementation files** at `app/Domain/Agents/Tools/Seo/{ReadProductDraftTool, ReadBrandStyleGuideTool, ReadSimilarShippedProductsTool, ProposeContentPatchTool}.php`. Each extends Phase 8's `Tool` base.
- **`SeoAgent` class location:** `app/Domain/Agents/Agents/SeoAgent.php` (mirrors PricingAgent placement).
- **Migrations:** ZERO needed.
- **Permissions.** New Shield permission `run_seo_agent` (admin + pricing_manager get it).
- **Test scope.** ~20-25 Pest cases.

### Deferred Ideas (OUT OF SCOPE)

- Per-suggestion confidence band — defer to v2.1
- Dedicated rejection inbox page — defer to v2.1
- Auto-apply for SEO patches (`agents.seo.auto_apply_threshold`) — never planned
- AGENT_SEO_AUTO_ENRICH_ENABLED flag — not relevant; SEO is already batch-scheduled
- DB-managed brand voice + guardrails via Filament — deferred per D-01
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SEOAGT-01 | `SeoAgent implements RunsAsAgent` triggered when AutoCreate draft enters `auto_create_status=pending_review` AND `completeness_score < 85`. | §Phase 8 Contract Surface; §Batch Eligibility Query; §Architecture — `SeoAgent` class skeleton mirroring `PricingAgent` |
| SEOAGT-02 | 4 tools: `read_product_draft`, `read_brand_style_guide`, `read_similar_shipped_products`, `propose_content_patch`. | §Tool Implementations; §Tool Naming Compliance |
| SEOAGT-03 | Suggestion kind `seo_content_patch` surfaces in `AutoCreateReviewResource` as sidebar panel; approval writes `Product.{field}` canonical + sets `ProductOverride.pin_{field}=true`. | §Filament Sidebar Pattern; §SeoContentPatchApplier write-through |
| SEOAGT-04 | Outbound regex guardrail catches brand-voice violations; failed → `agent_guardrail_blocked` Suggestion (not surfaced to admin) + `AgentRun.guardrail_failures` audit row. | §Brand-Voice Regex Pattern Library; §SeoOutboundGuardrail wiring |
| SEOAGT-05 | Budget `seo_agent.daily_pence_cap=300`. Batch-triggered: one nightly scheduled run, up to 20 drafts/run. | §Phase 8 BudgetGuard verbatim; §Scheduled Batch Command Pattern |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

The repo's `CLAUDE.md` is the v1 stack baseline; Phase 12 adheres to:

- **AI usage:** ONLY for formatting and method statement structuring — never for inventing scope, equipment, or design. For SEO, the agent rephrases existing product copy + applies brand voice; it MUST NOT invent technical specs, model numbers, or capabilities that aren't already in the supplier-provided draft. Plan 12-03 system prompt should explicitly forbid fabrication.
- **Data integrity:** All document content must trace back to quote/survey/reviewed inputs. For SEO: every patch's `after` text must derive from the supplier draft (`read_product_draft`), similar shipped products (`read_similar_shipped_products`), or brand voice rules (`read_brand_style_guide`) — never from agent's training-data knowledge. SensitiveFieldsStrip + OutboundRegex guardrails enforce.
- **Existing pipeline must not break:** Phase 6 AutoCreate publish flow (`PublishProductJob`, completeness scoring, pin-respecting supplier sync via `ApplyPinsDuringSync` listener) is read-only for Phase 12. Architectural test `AutoCreatePipelineUnchangedTest` should assert byte-identity on `AutoCreateReviewResource` core action signatures (the Plan 12-04 sidebar Section is purely additive).
- **Architecture:** Phase 8's `Agents` Deptrac layer allow-list `[Foundation, Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate]` is sufficient — Phase 12 must NOT widen.
- **Dual-YAML Deptrac sync** invariant: Phase 12 adds NO new layers.
- **`shield:safe-regenerate`** wrapper runs when adding `run_seo_agent` permission.
- **Suggestions seam mandatory** — `SeoContentPatchApplier` registered against kind `seo_content_patch` in `SuggestionApplierResolver`; approval writes via this applier, NOT direct DB writes from Agents domain.
- **PolicyTemplateIntegrityTest floor stays at 27** — no new policies; new permission only.

## Summary

Phase 12 is the **second concrete `RunsAsAgent` consumer** of Phase 8's framework after Phase 10 PricingAgent. The framework primitives — `RunsAsAgent` contract, `AgentRegistry`, `ToolBus`, `BudgetGuard`, `GuardrailEngine`, `ClaudeClient` (Prism wrapper), `AgentRun` ULID forensics row, `agents` Horizon queue, `TruncatingTool` 3-KB cap helper — all exist verbatim in `app/Domain/Agents/`. Phase 12's `SeoAgent` is a thin business-logic implementation that wires these together against Phase 6's AutoCreate draft producer (Product rows where `auto_create_status=pending_review` AND `completeness_score < 85`).

Phase 10 shipped a near-identical shape (PricingAgent + 5 tools + RunPricingAgentJob + PricingAgentResultMapper + SuggestionResource infolist extension + Shield permission + system.blade.php). Phase 12 mirrors that pattern with 5 structural diffs (NOT 11 — the framework already absorbed most variation):

1. **4 tools** instead of 5 (no separate `propose_*` tool with structured numeric args — `propose_content_patch` IS the single propose tool, but it's invoked 1-4 times per run rather than once).
2. **Bundled Suggestion** — `SeoAgentResultMapper` collects ALL `propose_content_patch` calls into ONE Suggestion's `payload.patches[]` array (Phase 10's mapper extracts ONLY the LAST `propose_margin_band` call).
3. **Batch trigger** — `RunSeoAgentBatchCommand` scheduled nightly at 04:30 London, dispatches up to 20 `RunSeoAgentJob` instances in a single command run, stops on monthly BudgetGuard breach. Phase 10 was admin-pull only.
4. **Different integration target** — Phase 12 extends `AutoCreateReviewResource` (Phase 6) with a sidebar panel, NOT `SuggestionResource` (Phase 1) with side-by-side cards.
5. **Different applier semantics** — `SeoContentPatchApplier` writes through to TWO models (`Product.{field}` + `ProductOverride.pin_{field}`); Phase 10's `MarginChangeApplier` writes to one. ZERO migrations.

The deepest design finding: **`propose_content_patch` is a structured no-op writer with variable-cardinality (1-4 calls per run), not a "last-wins" structured writer (Phase 10's pattern)**. The mapper logic Phase 12 ships diverges from Phase 10's `end()` extraction — instead it `array_filter`s by `tool_name='propose_content_patch'`, deduplicates by `field` (last-wins per-field), and bundles every distinct field's last call into the Suggestion payload.

**Primary recommendation:** 5-plan breakdown mirroring Phase 10:
- 12-01 — SeoAgent skeleton + 4 tool stubs + ToolBus registration + AgentRegistry wiring + system.blade.php scaffold + brand-voice markdown directory structure with `_global.md` first draft + `logitech.md` example
- 12-02 — 4 tool implementations with 3 KB soft caps + per-tool Pest unit tests
- 12-03 — System prompt Blade view + brand-voice content authoring + guardrail regex pattern library in `config/seo_agent.php` + `SeoOutboundGuardrail` post-flight implementation + Prism::fake E2E calibration test
- 12-04 — `RunSeoAgentJob` + `SeoAgentResultMapper` + `SeoContentPatchApplier` + `AutoCreateReviewResource` sidebar Section infolist extension + per-field approve action
- 12-05 — `RunSeoAgentBatchCommand` + scheduled run wiring (`routes/console.php`) + Shield permission `run_seo_agent` + `shield:safe-regenerate` re-run + 12-VERIFICATION.md ship verdict

## Phase 8 Contract Surface (verbatim reuse — no Phase 12 modifications)

> All file paths verified by direct read on this codebase 2026-05-16.

### `RunsAsAgent` contract (`app/Domain/Agents/Contracts/RunsAsAgent.php`)

```php
interface RunsAsAgent
{
    public static function kind(): string;        // 'seo'
    public static function trustTier(): TrustTier;  // TrustTier::Trusted
    public function tools(): array;                // array<Tool> — 4 SeoAgent tools
    public function systemPrompt(array $context = []): string;  // Blade-rendered
    public function guardrails(): array;            // array<Guardrail>
    public function execute(array $input, TrustTier $tier): AgentResult;  // FORWARD-COMPAT — throw LogicException
}
```

[VERIFIED: `app/Domain/Agents/Contracts/RunsAsAgent.php` — read by Phase 10 RESEARCH 2026-04-29; unchanged since]

**Key insight (Phase 8 Plan 04 §Pattern 2):** `execute()` is **never invoked by `RunAgentJob`**. The framework calls the contract's getters (`tools/systemPrompt/guardrails`) directly and orchestrates the loop. `PricingAgent::execute()` throws `LogicException` to make this literal. SeoAgent should do the same.

### Phase 10 primitives Phase 12 inherits unchanged

| Primitive | File | Phase 12 usage |
|-----------|------|----------------|
| `RunsAsAgent` | `app/Domain/Agents/Contracts/RunsAsAgent.php` | SeoAgent implements |
| `AgentRegistry` | `app/Domain/Agents/Services/AgentRegistry.php` | `AppServiceProvider::boot` registers `seo` kind alongside `pricing` |
| `ToolBus` | `app/Domain/Agents/Services/ToolBus.php` | `buildPrismTools()` invoked from `RunSeoAgentJob`; `truncate()` for tool_calls JSON cap |
| `BudgetGuard` | `app/Domain/Agents/Services/BudgetGuard.php` | `assertHasBudget('seo')` + `recordSpend('seo', $cost)`; daily cap from `config('agents.daily_caps.seo')=300` [VERIFIED: `config/agents.php:43`] |
| `GuardrailEngine` | `app/Domain/Agents/Services/GuardrailEngine.php` | runPreFlight/runPostFlight; PromptInjectionXmlFence skips on Trusted tier; SensitiveFieldsStrip + OutboundRegex active |
| `ClaudeClient` | `app/Domain/Agents/Clients/ClaudeClient.php` | `generate(systemPrompt, messages, tools, temperature=0.4)` — Phase 12 overrides default temp |
| `PromptRenderer` | `app/Domain/Agents/Services/PromptRenderer.php` | `render('seo', $context)` returns prompt + sha256 hash |
| `Tool` abstract base | `app/Domain/Agents/Services/Tools/Tool.php` | 4 SeoAgent tools extend |
| `TruncatingTool` | `app/Domain/Agents/Tools/Pricing/TruncatingTool.php` | **DECISION REQUIRED:** Move to `app/Domain/Agents/Tools/TruncatingTool.php` (shared) OR copy a `Seo/TruncatingTool.php`. **Recommendation: MOVE** — it's not Pricing-specific. See §P12-D pitfall. |
| `AgentRun` model | `app/Domain/Agents/Models/AgentRun.php` | RunSeoAgentJob writes; mapper reads `tool_calls` JSON; `guardrail_failures` JSON column already exists |
| `RunAgentJob`-style orchestration | `app/Domain/Agents/Jobs/RunPricingAgentJob.php` | Phase 12 mirrors Path A (sibling job, NOT subclass) — see RESEARCH §P12-A |
| `agents` Horizon queue | `config/horizon.php` `agents-supervisor` | `RunSeoAgentJob` dispatches onto |
| `AGENT_WRITE_ENABLED` flag | `config/agents.php:69` | When false, SeoAgentResultMapper skips Suggestion creation (forensic-only run) |
| `BudgetExceededException` etc. | `app/Domain/Agents/Exceptions/*.php` | RunSeoAgentJob catches per terminal status mapping |
| `shield:safe-regenerate` | `app/Domain/Agents/Console/Commands/ShieldSafeRegenerateCommand.php` | Plan 12-05 invokes when adding `run_seo_agent` permission |
| `AgentRunResource` Filament | `app/Domain/Agents/Filament/Resources/AgentRunResource.php` | Phase 12 doesn't modify; SeoAgent runs surface via existing list view filtered by `kind=seo` |
| `Suggestion` model | `app/Domain/Suggestions/Models/Suggestion.php` | New kind `seo_content_patch`; new kind `agent_guardrail_blocked` for failed runs |
| `SuggestionApplierResolver` | `app/Domain/Suggestions/Services/SuggestionApplierResolver.php` | Phase 12 registers `SeoContentPatchApplier` for kind `seo_content_patch` |
| `proposed_by` morph | already in v1 baseline; Phase 8 activated | SeoAgentResultMapper sets `proposed_by_type=AgentRun::class`, `proposed_by_id=$run->id` |
| `ProductOverride.pin_{field}` columns | `app/Domain/Pricing/Models/ProductOverride.php:42-65` | Applier upserts `pin_title`, `pin_short_description`, `pin_long_description`, `pin_meta_description` — ALL VERIFIED EXIST |
| `Product.{field}` columns | `app/Domain/Products/Models/Product.php:33-51` | Applier writes `name`, `short_description`, `long_description`, `meta_description` — ALL VERIFIED FILLABLE |
| `AutoCreateReviewResource` infolist | `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php` | Phase 12 adds sidebar Section to Edit page infolist; table action wires through `Action::make('approve_patch')` per row |

**Critical research correction vs CONTEXT.md:** The product field names are `name` (not `title`) on Phase 6's Product model. The CONTEXT.md SEOAGT-01 specifies "title, short_description, long_description, meta_description" — the SeoAgent's `propose_content_patch` tool `field` parameter must map `title → name` when writing through. **DECISION:** Phase 12 tool accepts `field='title'` as the user-facing name (preserves SEO semantics) but the applier maps `title → Product.name`. Document in `SeoContentPatchApplier` docblock + a Pest contract test.

[VERIFIED: `Product.php:33-51` — fillable includes `'name'`, `'short_description'`, `'long_description'`, `'meta_description'`; NO `'title'` column.]

## Standard Stack (verbatim — no new composer packages)

### Core (already shipped by Phase 8/10)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `prism-php/prism` | `^0.100.1` (shipped Phase 8 Plan 02 — 2026-04-25) | Claude SDK + tool-use loop | [VERIFIED: composer.lock + `app/Domain/Agents/Clients/ClaudeClient.php`] |
| `mliviu79/laravel-langfuse-prism` | `^0.1.0` (shipped Phase 8 Plan 02) | Auto-instrument Prism → Langfuse | Self-hosted Langfuse at `lf.ops.meetingstore.co.uk` |

### Existing v1 stack consumed (NO version bumps; NO new packages)

| Library | Version | Used by Phase 12 |
|---------|---------|-------------------|
| `laravel/framework` | ^12.0 | Eloquent, queue, cache, scheduler, Context, Gate, Blade views |
| `filament/filament` | ^3.3 | `AutoCreateReviewResource` sidebar Section extension |
| `bezhansalleh/filament-shield` | ^3.3 | Permission `run_seo_agent` |
| `spatie/laravel-permission` | ^6.0 | Roles for new permission |
| `spatie/laravel-activitylog` | ^4.12 | Auditor logs `seo.content_patch_applied` |
| `laravel/horizon` | ^5.45 | `agents-supervisor` queue (already registered) |
| `predis/predis` | ^3.4 | BudgetGuard cache (transparent to Phase 12) |
| `pestphp/pest` | ^3.8.5 | Architecture + Feature tests |
| `qossmic/deptrac-shim` | ^1.0 | Dual-YAML — Phase 12 adds NO new layers |

**Verification of version pins (Plan 12-01 first task):**

```bash
composer show prism-php/prism | grep -i version
composer show mliviu79/laravel-langfuse-prism | grep -i version
```

Expected: ≥ `0.100.1` and ≥ `0.1.0` respectively. **No new `composer require` commands.**

**Diff library evaluation (Plan 12-04 sidebar diff render):**

Three options surveyed:

| Approach | Composer install? | Pros | Cons | Recommendation |
|----------|-------------------|------|------|----------------|
| `sebastianbergmann/diff` | Already in vendor/ via PHPUnit | Battle-tested unified-diff PHP impl; used in PHPUnit assertion failures | Adds CSS/JS-free unified diff output — visually noisy for short product titles; designed for programmer-readable patches not UX-friendly side-by-side | **REJECT** — wrong UX shape for short copy edits |
| `spatie/laravel-html-diff` | Not in vendor — new package | Pretty HTML diff output with `<ins>`/`<del>` tags; coloured inline rendering | Adds bus-factor risk for what's effectively a CSS problem; v2.0 net-stack-delta policy says no new packages without strong need | **REJECT** — adds dependency for cosmetic value |
| **Hand-rolled inline truncate-with-tooltip** | None | Per CONTEXT D-03: "monospace, truncated to 200 chars with full-text reveal on hover"; no algorithm needed — just show `before` and `after` side-by-side; Filament's built-in `TextEntry::limit(200)->tooltip(fn (...) => $fullValue)` handles it | None for v2.0 scope (admin can read both columns; they're short product titles, not 10k-char essays) | **RECOMMEND** — ships in Plan 12-04 with zero net stack delta |

[VERIFIED: `sebastianbergmann/diff` present in `vendor/sebastian/diff/` via PHPUnit; spatie/laravel-html-diff not in composer.json]

**Why hand-rolled wins here:** Product titles are 30-100 chars; short_description is 100-500 chars; long_description is 500-2000 chars; meta_description is 60-160 chars. Side-by-side display with `before` and `after` columns is more readable than a unified diff for content of this length and shape. The admin's mental model is "is this new wording better?" not "what bytes changed?" — no diff algorithm needed.

If real-world feedback shows admins want char-level diff highlighting (v2.1 candidate), the Plan 12-04 panel can be retrofitted with `spatie/laravel-html-diff` without touching the data flow.

**Anthropic model identifier:** Use `claude-sonnet-4-6` verbatim (already locked in `config/agents.php:91`). Phase 12 does NOT override the model.

**Temperature override (Plan 12-03 ships):**

Phase 12 needs `temperature=0.4` (CONTEXT Claude's Discretion). Phase 8 ClaudeClient takes temperature as a method argument:

```php
$response = $client->generate(
    systemPrompt: $rendered['prompt'],
    messages: $messages,
    tools: $prismTools,
    temperature: (float) config('agents.seo.temperature', 0.4),  // NEW Phase 12 config key
);
```

Plan 12-01 adds `agents.seo.temperature` to `config/agents.php`:

```php
// Phase 12 — SEO agent temperature (per CONTEXT Claude's Discretion).
// 0.4 balances creativity (genuine paraphrasing) with reproducibility.
// Set higher than pricing (0.0 deterministic) because REQUIREMENTS line 124
// explicitly allows temp>0 for SEO/chatbot with guardrails.
'seo' => [
    'temperature' => (float) env('AGENTS_SEO_TEMPERATURE', 0.4),
],
```

[VERIFIED: `app/Domain/Agents/Clients/ClaudeClient.php:38-148` — temperature is method param, no codebase-level lock on temp=0]

## Architecture Patterns

### Recommended Project Structure

```
app/Domain/Agents/
├── Agents/
│   ├── PricingAgent.php           # existing — Phase 10
│   └── SeoAgent.php               # NEW — Phase 12 Plan 01
├── Tools/
│   ├── TruncatingTool.php         # MOVED from Tools/Pricing/ — Phase 12 Plan 01 (P12-D)
│   ├── Pricing/                   # existing
│   │   └── ... (Phase 10 tools)
│   └── Seo/                       # NEW — Phase 12 Plan 01-02
│       ├── ReadProductDraftTool.php
│       ├── ReadBrandStyleGuideTool.php
│       ├── ReadSimilarShippedProductsTool.php
│       └── ProposeContentPatchTool.php
├── Services/
│   ├── PricingAgentResultMapper.php   # existing — Phase 10
│   └── SeoAgentResultMapper.php       # NEW — Phase 12 Plan 04
├── Jobs/
│   ├── RunPricingAgentJob.php         # existing — Phase 10
│   ├── RunSeoAgentJob.php             # NEW — Phase 12 Plan 04
│   └── ... (others unchanged)
├── Console/Commands/
│   ├── ShieldSafeRegenerateCommand.php # existing — Phase 8
│   ├── AgentsPruneArchiveCommand.php   # existing — Phase 8
│   └── RunSeoAgentBatchCommand.php    # NEW — Phase 12 Plan 05
├── Appliers/
│   └── SeoContentPatchApplier.php     # NEW — Phase 12 Plan 04
└── Guardrails/
    └── SeoOutboundGuardrail.php       # NEW — Phase 12 Plan 03

resources/
├── agents/
│   ├── pricing/
│   │   └── system.blade.php       # existing — Phase 10
│   ├── seo/
│   │   └── system.blade.php       # NEW — Phase 12 Plan 03
│   └── brand-voice/               # NEW — Phase 12 Plan 01 scaffold + Plan 03 content
│       ├── _global.md             # NEW — mandatory (Plan 01 ships first draft; Plan 03 refines)
│       └── logitech.md            # NEW — example per-brand override (Plan 01)

config/
├── agents.php                     # existing — Phase 8 (Plan 01 ADDS agents.seo.temperature)
└── seo_agent.php                  # NEW — Phase 12 Plan 03 (guardrail regex patterns)
```

### Pattern 1: `RunSeoAgentJob` (Path A sibling — mirrors `RunPricingAgentJob` verbatim)

**What:** A sibling job to Phase 8's `RunAgentJob` and Phase 10's `RunPricingAgentJob` — NOT a subclass. Mirrors the 13-step orchestration sequence with 3 structural diffs:

1. **`$productId`** is REQUIRED (not nullable). SeoAgent enriches an existing Phase 6 AutoCreate draft — without a Product ID there's nothing to patch.
2. **Step 12 replaces `AgentSuggestionWriter::write` with `SeoAgentResultMapper::createBundledSuggestion`** — instead of writing a single shadow Suggestion from a single `SuggestionDraft`, the mapper extracts ALL `propose_content_patch` calls from `tool_calls[]` and creates one bundled Suggestion of kind `seo_content_patch`.
3. **`triggering_suggestion_id`** is null (Phase 12 is batch-driven, not suggestion-pull). `triggering_correlation_id` is set per draft.

```php
// app/Domain/Agents/Jobs/RunSeoAgentJob.php  (Plan 12-04)
final class RunSeoAgentJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public readonly int $productId,
        public readonly ?string $batchCorrelationId = null,  // shared across the batch's products
    ) {
        $this->onQueue('agents');
    }

    public function handle(
        AgentRegistry $registry,
        BudgetGuard $budgetGuard,
        ToolBus $toolBus,
        GuardrailEngine $guardrailEngine,
        ClaudeClient $client,
        PromptRenderer $promptRenderer,
        SeoAgentResultMapper $mapper,
    ): void {
        $product = Product::findOrFail($this->productId);

        // Defensive: eligibility re-check (a competing run may have flipped status)
        if ($product->auto_create_status !== 'pending_review') {
            Log::info('SeoAgent: product no longer eligible', [
                'product_id' => $product->id,
                'auto_create_status' => $product->auto_create_status,
            ]);
            return;
        }

        // Per-product correlation_id = batch_correlation_id + product_id
        $correlationId = $this->batchCorrelationId
            ?: (string) (Context::get('correlation_id') ?? (string) Str::uuid());
        Context::add('correlation_id', $correlationId);

        $agent = $registry->resolve('seo');

        $rendered = $promptRenderer->render('seo', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'brand_slug' => $this->brandSlug($product),
        ]);

        $run = AgentRun::create([
            'kind' => 'seo',
            'status' => AgentRunStatus::Running->value,
            'triggering_suggestion_id' => null,  // batch-driven
            'triggering_correlation_id' => $correlationId,
            'system_prompt_hash' => $rendered['hash'],
            'tool_calls' => [],
            'started_at' => now(),
        ]);

        event(new AgentRunStarted($run));

        $guardrailPhase = 'pre';
        try {
            $budgetGuard->assertHasBudget('seo');

            $tier = $agent::trustTier();
            $sanitisedInput = $guardrailEngine->runPreFlight($agent, [], $tier);

            $userText = json_encode([
                'product_id' => $product->id,
                'sku' => (string) $product->sku,
                'context' => [
                    'completeness_score' => (int) $product->completeness_score,
                    'completeness_missing_fields' => (array) $product->completeness_missing_fields,
                    'brand_slug' => $this->brandSlug($product),
                ],
            ], JSON_THROW_ON_ERROR);

            $messages = [new UserMessage($userText)];
            $prismTools = $toolBus->buildPrismTools($agent->tools(), $run);

            $response = $client->generate(
                systemPrompt: $rendered['prompt'],
                messages: $messages,
                tools: $prismTools,
                temperature: (float) config('agents.seo.temperature', 0.4),
            );

            $guardrailPhase = 'post';
            $response = $guardrailEngine->runPostFlight($agent, $response, $tier);

            $toolCallsLog = $this->extractToolCallsFromSteps($response->steps, $toolBus);

            $run->update([
                'status' => AgentRunStatus::Completed->value,
                'completed_at' => now(),
                'finish_reason' => $response->finishReason->value,
                'tool_calls' => $toolCallsLog,
                'agent_reasoning_summary' => mb_substr($response->text ?? '', 0, 8192),
                'prompt_token_count' => $response->promptTokens,
                'completion_token_count' => $response->completionTokens,
                'cost_pence' => $response->costPence,
                'langfuse_trace_id' => $response->langfuseTraceId,
            ]);

            $budgetGuard->recordSpend('seo', $response->costPence);

            if ((bool) config('agents.write_enabled', false)) {
                $mapper->createBundledSuggestion($run->fresh(), $product);
            } else {
                Log::info('SeoAgent run completed in shadow mode — Suggestion NOT created', [
                    'agent_run_id' => $run->id,
                    'product_id' => $product->id,
                ]);
            }

            event(new AgentRunCompleted($run->fresh()));
        } catch (MonthlyBudgetExceededException | BudgetExceededException $e) {
            // Mirror Phase 10 RunPricingAgentJob:210-225 — status flip + rethrow
            $run->update([
                'status' => $e instanceof MonthlyBudgetExceededException
                    ? AgentRunStatus::MonthlyBudgetBlocked->value
                    : AgentRunStatus::BudgetExceeded->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run->fresh(), $e));
            throw $e;
        } catch (GuardrailViolationException $e) {
            // SEOAGT-04 — guardrail block writes agent_guardrail_blocked Suggestion
            // (mapper does this on completed-run path; here we ONLY land if the
            // pre-flight chain blocks. Post-flight chain runs separately —
            // SeoOutboundGuardrail throws GuardrailViolationException from runPostFlight
            // which lands here too; in that case the mapper path was already SKIPPED
            // so no agent_guardrail_blocked Suggestion was written for the run.
            // Plan 12-03 SeoOutboundGuardrail::post() handles the audit Suggestion
            // creation BEFORE throwing; this catch block just records the AgentRun
            // status. See P12-B pitfall.
            $run->update([
                'status' => AgentRunStatus::GuardrailBlocked->value,
                'completed_at' => now(),
                'guardrail_failures' => [[
                    'guardrail' => $e->guardrailClass !== '' ? $e->guardrailClass : GuardrailViolationException::class,
                    'message' => $e->getMessage(),
                    'when' => $guardrailPhase,
                    'occurred_at' => now()->toIso8601String(),
                ]],
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run->fresh(), $e));
            throw $e;
        } catch (\Throwable $e) {
            $run->update([
                'status' => AgentRunStatus::Failed->value,
                'completed_at' => now(),
                'agent_reasoning_summary' => mb_substr($e->getMessage(), 0, 8192),
            ]);
            event(new AgentRunFailed($run->fresh(), $e));
            throw $e;
        }
    }

    private function brandSlug(Product $product): string
    {
        if ($product->brand_id === null) return 'global';
        // Resolve brand → slug — Phase 12 ships a simple cache lookup, falls
        // back to the integer brand_id as the slug
        return cache()->rememberForever(
            "brand_slug.{$product->brand_id}",
            fn () => DB::table('brands')->where('id', $product->brand_id)->value('slug')
                ?? (string) $product->brand_id,
        );
    }

    // extractToolCallsFromSteps + readStepProperty — copy verbatim from RunPricingAgentJob:260-311
}
```

**Anti-pattern (Path B REJECTED per Phase 10 RESEARCH §A9):** Subclassing `RunAgentJob` and overriding the writer step. Phase 8 didn't design for inheritance; the framework's catch-blocks reference `AgentSuggestionWriter` directly. Sibling job is cleaner. **Phase 10 already shipped Path A; Phase 12 follows the precedent.**

### Pattern 2: `SeoAgentResultMapper` (variable-cardinality bundled-Suggestion writer)

**What:** Phase 12's mapper extracts ALL `propose_content_patch` calls from `AgentRun.tool_calls[]`, deduplicates by `field` (last-wins per-field — matches Phase 10's `end()` semantics), and bundles every distinct field's last call into ONE Suggestion of kind `seo_content_patch`.

**Key divergence from Phase 10:** Phase 10's mapper does `end($filtered)` to get the LAST single call. Phase 12's mapper does `array_filter` + per-field deduplication + bundling. There can be 1, 2, 3, or 4 `propose_content_patch` calls per run — each for a DIFFERENT field. If the agent calls twice for the SAME field, the second call wins for that field.

```php
// app/Domain/Agents/Services/SeoAgentResultMapper.php  (Plan 12-04)
final class SeoAgentResultMapper
{
    /** SEOAGT-02 — only these 4 fields are valid targets. */
    public const VALID_FIELDS = ['title', 'short_description', 'long_description', 'meta_description'];

    public function createBundledSuggestion(AgentRun $run, Product $product): ?Suggestion
    {
        $toolCalls = (array) ($run->tool_calls ?? []);

        $proposeCalls = array_values(array_filter(
            $toolCalls,
            fn ($call) => is_array($call) && (($call['tool_name'] ?? '') === 'propose_content_patch'),
        ));

        if ($proposeCalls === []) {
            // SEOAGT — no patches proposed; record on AgentRun forensics, NO Suggestion created
            $this->markNoPatchesState($run);
            return null;
        }

        // Per-field dedup: last call wins for each field (matches Phase 10 D-06 semantics)
        $patchesByField = [];
        foreach ($proposeCalls as $call) {
            $args = $this->decodeArgs($call['inputs'] ?? null);
            $field = (string) ($args['field'] ?? '');

            if (! in_array($field, self::VALID_FIELDS, true)) {
                continue;  // ignore invalid field names
            }

            $before = (string) ($args['before'] ?? '');
            $after = (string) ($args['after'] ?? '');
            $reasoning = (string) ($args['reasoning'] ?? '');

            // Defensive: don't store patches where after === before (no-op patches)
            if ($before === $after) {
                continue;
            }

            $patchesByField[$field] = [
                'field' => $field,
                'before' => mb_substr($before, 0, 4096),  // hard cap on stored size
                'after' => mb_substr($after, 0, 4096),
                'reasoning' => mb_substr($reasoning, 0, 1024),
                'applied_at' => null,
            ];
        }

        if ($patchesByField === []) {
            $this->markNoPatchesState($run);
            return null;
        }

        return Suggestion::create([
            'kind' => 'seo_content_patch',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => $run->triggering_correlation_id,
            'payload' => [
                'product_id' => $product->id,
                'sku' => (string) $product->sku,
                'patches' => array_values($patchesByField),  // [{field, before, after, reasoning, applied_at}]
                'agent_run_id' => (string) $run->id,
            ],
            'evidence' => [
                'agent_kind' => 'seo',
                'completeness_score_at_run' => (int) $product->completeness_score,
                'cost_pence' => (int) $run->cost_pence,
            ],
            'proposed_by_type' => AgentRun::class,
            'proposed_by_id' => $run->id,
            'proposed_at' => now(),
        ]);
    }

    /** Called by SeoOutboundGuardrail when post-flight regex fires. */
    public function createGuardrailBlockedSuggestion(
        AgentRun $run,
        Product $product,
        string $failedPatternKey,
        string $matchedExcerpt,
    ): Suggestion {
        return Suggestion::create([
            'kind' => 'agent_guardrail_blocked',
            'status' => Suggestion::STATUS_PENDING,  // not surfaced — Filament filter hides it
            'correlation_id' => $run->triggering_correlation_id,
            'payload' => [
                'product_id' => $product->id,
                'sku' => (string) $product->sku,
                'agent_kind' => 'seo',
                'failed_pattern_key' => $failedPatternKey,
                'matched_excerpt' => mb_substr($matchedExcerpt, 0, 500),
            ],
            'evidence' => [
                'agent_run_id' => (string) $run->id,
            ],
            'proposed_by_type' => AgentRun::class,
            'proposed_by_id' => $run->id,
            'proposed_at' => now(),
        ]);
    }

    private function markNoPatchesState(AgentRun $run): void
    {
        // Append `evidence.agent_run_status='no_patches'` to AgentRun directly
        // (not via Suggestion since no Suggestion was created)
        $run->update([
            'agent_reasoning_summary' => trim(($run->agent_reasoning_summary ?? '') . "\n\n[mapper: no_patches]"),
        ]);
    }

    private function decodeArgs(mixed $inputs): array
    {
        if (is_array($inputs)) return $inputs;
        if (! is_string($inputs) || $inputs === '') return [];
        $decoded = json_decode($inputs, true);
        return is_array($decoded) ? $decoded : [];
    }
}
```

[VERIFIED: Phase 10's `PricingAgentResultMapper::mergeIntoSuggestion` end()-extraction logic at `app/Domain/Agents/Services/PricingAgentResultMapper.php:67-71` — Phase 12 extends this pattern]

### Pattern 3: `SeoContentPatchApplier` (per-field write-through)

**What:** Approving a Suggestion of kind `seo_content_patch` dispatches `ApplySuggestionJob` which resolves `SeoContentPatchApplier`. The applier:

1. Iterates over `payload.patches[]`
2. For each patch with `applied_at !== null` (admin selected it for approval), updates `Product.{field}` canonical AND `ProductOverride.pin_{field}=true`
3. Audits each field write via `Auditor::log('seo.content_patch_applied', ...)` with `before_hash` / `after_hash` (NOT verbatim values to keep audit log lean)
4. If ALL 4 fields applied → Suggestion `status='applied'`. If subset → Suggestion `status='pending'` with unapproved patches still visible.

```php
// app/Domain/Agents/Appliers/SeoContentPatchApplier.php  (Plan 12-04)
final class SeoContentPatchApplier implements SuggestionApplier
{
    /** Map SEOAGT-02 user-facing field names to Product columns. */
    private const FIELD_TO_PRODUCT_COLUMN = [
        'title' => 'name',  // CRITICAL: SEO 'title' maps to Product.name
        'short_description' => 'short_description',
        'long_description' => 'long_description',
        'meta_description' => 'meta_description',
    ];

    private const FIELD_TO_PIN_COLUMN = [
        'title' => 'pin_title',
        'short_description' => 'pin_short_description',
        'long_description' => 'pin_long_description',
        'meta_description' => 'pin_meta_description',
    ];

    public function __construct(
        private readonly Auditor $auditor,
    ) {}

    public function apply(Suggestion $suggestion): void
    {
        $payload = (array) $suggestion->payload;
        $productId = (int) ($payload['product_id'] ?? 0);
        $patches = (array) ($payload['patches'] ?? []);

        if ($productId === 0 || $patches === []) {
            throw new \RuntimeException("SeoContentPatchApplier: malformed payload (suggestion_id={$suggestion->id})");
        }

        $product = Product::findOrFail($productId);
        $overrideUpdates = [];
        $appliedFields = [];

        DB::transaction(function () use ($product, $patches, $suggestion, &$overrideUpdates, &$appliedFields) {
            foreach ($patches as $i => $patch) {
                if (! is_array($patch)) continue;
                if (($patch['applied_at'] ?? null) === null) continue;  // admin didn't select this field

                $field = (string) ($patch['field'] ?? '');
                if (! isset(self::FIELD_TO_PRODUCT_COLUMN[$field])) continue;

                $column = self::FIELD_TO_PRODUCT_COLUMN[$field];
                $pinColumn = self::FIELD_TO_PIN_COLUMN[$field];
                $after = (string) ($patch['after'] ?? '');
                $before = (string) ($patch['before'] ?? '');

                $product->{$column} = $after;
                $overrideUpdates[$pinColumn] = true;
                $appliedFields[] = $field;

                $this->auditor->record('seo.content_patch_applied', [
                    'product_id' => $product->id,
                    'field' => $field,
                    'agent_run_id' => (string) ($payload['agent_run_id'] ?? ''),
                    'suggestion_id' => $suggestion->id,
                    'before_hash' => hash('sha256', $before),
                    'after_hash' => hash('sha256', $after),
                ]);
            }

            $product->save();

            if ($overrideUpdates !== []) {
                ProductOverride::updateOrCreate(
                    ['product_id' => $product->id],
                    $overrideUpdates,
                );
            }

            // Flip Suggestion status based on whether ALL or SUBSET applied
            $totalPatches = count($patches);
            $appliedCount = count($appliedFields);
            $suggestion->update([
                'status' => $appliedCount === $totalPatches
                    ? Suggestion::STATUS_APPLIED
                    : Suggestion::STATUS_PENDING,
                'applied_at' => $appliedCount > 0 ? now() : null,
            ]);
        });
    }
}
```

[VERIFIED: `ProductOverride.php:42-50` — pin columns exist; `Product.php:33-51` — name/short_description/long_description/meta_description all fillable]

### Pattern 4: Filament sidebar Section on `AutoCreateReviewResource` Edit page (D-03)

**What:** Add a Filament `Section` to the `EditAutoCreateReview` page (NOT the list-view table) that displays the bundled `seo_content_patch` Suggestion as a sidebar panel with per-field approve checkboxes.

**Key design decision: Section vs Group vs Tab.** Survey of Filament 3.3 layout primitives:

| Primitive | Use case | Fit for SEO sidebar? |
|-----------|----------|---------------------|
| `Section::make('label')` | Visually-grouped form/infolist block with collapsible header | **YES — use this** — gives the "sidebar panel" visual without needing a custom Livewire component |
| `Group::make([])` | Layout-only grouping (no visual border/title) | NO — no header for "SEO content patches (N proposed)" |
| `Tab::make('label')` (via `Tabs` component) | Multi-tab UI for grouping sub-views | OVERKILL — only one panel needed, not multiple tabs |
| Custom Livewire component | Full control + reactive state | OVERKILL — Filament Section + per-row Action handles approval; no need for raw Livewire |
| Plain Blade view in a Section's content | Escape hatch for non-standard rendering | NOT NEEDED — Section's repeater + per-row Action covers the UX |

**Recommendation:** `Filament\Infolists\Components\Section` containing a `RepeatableEntry` (or a `Grid` of `TextEntry` rows). Action button per row for approve/reject. Matches Phase 10 `SuggestionResource::infolist()` precedent ([VERIFIED: `SuggestionResource.php:456-557`]).

**Plan 12-04 ships an extension to `EditAutoCreateReview` infolist** (not a standalone page — Phase 6 already has `EditAutoCreateReview extends EditRecord`). Recommended approach:

1. Override `EditAutoCreateReview::infolist()` (Filament 3 Edit pages support infolists alongside forms)
2. Below the existing edit form, add `Section::make('SEO content patches (N proposed)')` 
3. The Section's `->schema()` iterates over the latest pending `seo_content_patch` Suggestion's `payload.patches[]` and renders one row per patch with:
   - `TextEntry::make('field')` (badge)
   - `TextEntry::make('before')` (truncated 200 chars + tooltip with full value)
   - `TextEntry::make('after')` (truncated 200 chars + tooltip with full value)
   - `TextEntry::make('reasoning')` (collapsible "why" toggle)
   - Inline `Action::make('approve_patch')` per row (sets `payload.patches[i].applied_at=now()` then dispatches `ApplySuggestionJob`)
4. Section's `->headerActions()` adds "Run SEO agent now" (manual re-run button — useful when admin rejected previous run + wants to retry without waiting for next nightly batch)

```php
// app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource/Pages/EditAutoCreateReview.php
// Phase 12 Plan 04 extension:

use Filament\Infolists\Concerns\InteractsWithInfolists;
use Filament\Infolists\Contracts\HasInfolists;
use Filament\Infolists\Infolist;

class EditAutoCreateReview extends EditRecord implements HasInfolists
{
    use InteractsWithInfolists;

    protected static string $resource = AutoCreateReviewResource::class;

    public function seoPatchesInfolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getRecord())
            ->schema([
                Section::make('SEO content patches')
                    ->description(fn (Product $r): string =>
                        ($s = $this->latestSeoSuggestion($r))
                            ? sprintf('%d patches proposed by agent run %s', count((array) data_get($s->payload, 'patches', [])), substr((string) data_get($s->payload, 'agent_run_id', ''), 0, 8))
                            : 'No SEO suggestions yet — agent runs nightly at 04:30 London for drafts with completeness < 85')
                    ->icon('heroicon-o-sparkles')
                    ->collapsible()
                    ->visible(fn (Product $r): bool => $this->latestSeoSuggestion($r) !== null)
                    ->schema([
                        RepeatableEntry::make('seo_patches')
                            ->state(fn (Product $r): array => (array) data_get($this->latestSeoSuggestion($r)?->payload, 'patches', []))
                            ->schema([
                                TextEntry::make('field')->badge()->color('info'),
                                TextEntry::make('before')
                                    ->label('Current')
                                    ->limit(200)
                                    ->tooltip(fn ($state) => (string) $state)
                                    ->fontFamily('mono'),
                                TextEntry::make('after')
                                    ->label('Proposed')
                                    ->limit(200)
                                    ->tooltip(fn ($state) => (string) $state)
                                    ->fontFamily('mono'),
                                TextEntry::make('reasoning')->markdown(),
                                TextEntry::make('applied_at')
                                    ->placeholder('— pending —')
                                    ->dateTime(),
                            ])
                            ->columns(5),
                    ]),
            ]);
    }

    private function latestSeoSuggestion(Product $product): ?Suggestion
    {
        return Suggestion::query()
            ->where('kind', 'seo_content_patch')
            ->where('payload->product_id', $product->id)
            ->whereIn('status', [Suggestion::STATUS_PENDING, Suggestion::STATUS_APPLIED])
            ->latest('proposed_at')
            ->first();
    }
}
```

**Per-field Approve action:** Phase 12 Plan 04 ships a Livewire action wired to the `EditAutoCreateReview` page lifecycle (NOT inside the infolist Repeater — Filament 3.3's RepeatableEntry doesn't natively support per-row actions; instead, render a list of `Action::make` buttons in a custom blade slot under the Section).

**Alternative (simpler):** Skip the per-row approve action and ship a single "Approve all selected" form action where the admin ticks checkboxes for each field they want approved. This collapses 1-4 actions into 1, simplifying both UI and applier logic. **Plan 12-04 ships this as primary**, retrofitting to per-row actions in v2.1 if admin feedback indicates.

### Pattern 5: Scheduled batch command (`RunSeoAgentBatchCommand`) — SEOAGT-05

**What:** A scheduled artisan command that runs nightly at 04:30 London, queries up to 20 eligible Product rows, dispatches one `RunSeoAgentJob` per product, and stops early if the monthly budget ceiling is hit.

**Inspiration:** `CompetitorWatchCommand` (existing) iterates over filesystem files and dispatches jobs per match. `competitor:sales-recache` (Phase 5) is the closest precedent for batch DB iteration:

```php
// app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php  (Plan 12-05)
final class RunSeoAgentBatchCommand extends BaseCommand
{
    protected $signature = 'agents:run-seo-batch
                            {--limit=20 : Max products to process this run}
                            {--dry-run : Show eligible products without dispatching}';

    protected $description = 'Phase 12 SEOAGT-05 — nightly SEO agent batch over Phase 6 AutoCreate drafts';

    protected function perform(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        // Pre-flight monthly budget check — if already breached, skip the whole batch
        $monthlyCap = (int) config('agents.monthly_ceiling_pence', 20000);
        $monthlySpent = (int) Cache::get('agents.monthly.' . now('Europe/London')->format('Y-m'), 0);
        if ($monthlySpent >= $monthlyCap) {
            $this->warn("Monthly budget already exceeded ({$monthlySpent}/{$monthlyCap}p) — batch aborted");
            return self::SUCCESS;
        }

        // Eligibility query (CONTEXT Claude's Discretion)
        $eligible = Product::query()
            ->where('auto_create_status', 'pending_review')
            ->where('completeness_score', '<', 85)
            ->whereDoesntHave('suggestions', fn ($q) =>
                $q->where('kind', 'seo_content_patch')
                  ->whereIn('status', ['pending', 'applied']))
            ->orderBy('completeness_score')  // worst-first
            ->limit($limit)
            ->get();

        if ($eligible->isEmpty()) {
            $this->info('No eligible AutoCreate drafts; nothing to do.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d eligible draft(s); %s mode',
            $eligible->count(),
            $dryRun ? 'dry-run' : 'live dispatch',
        ));

        $batchCorrelationId = (string) Str::uuid();

        $dispatched = 0;
        foreach ($eligible as $product) {
            if ($dryRun) {
                $this->line(sprintf('  [DRY] %s (score=%d)', $product->sku, $product->completeness_score));
                continue;
            }

            // Re-check monthly budget BETWEEN dispatches in case other agents
            // are spending concurrently
            $monthlySpent = (int) Cache::get('agents.monthly.' . now('Europe/London')->format('Y-m'), 0);
            if ($monthlySpent >= $monthlyCap) {
                $this->warn("Monthly budget exceeded mid-batch ({$monthlySpent}/{$monthlyCap}p) — stopping at {$dispatched}/{$eligible->count()}");
                break;
            }

            RunSeoAgentJob::dispatch($product->id, $batchCorrelationId);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} SeoAgent runs on `agents` queue.");
        return self::SUCCESS;
    }
}
```

**Wiring in `routes/console.php`:**

```php
// Phase 12 SEOAGT-05 — nightly SEO agent batch at 04:30 Europe/London.
// Slots between competitor:ftp-pull (Sun+Wed 02:00) and supplier:db-sync
// (Mon-Fri 07:00). Single nightly cadence per SEOAGT-05 success criterion 1.
Schedule::command('agents:run-seo-batch')
    ->cron('30 4 * * *')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Phase 12 SEOAGT-05 — nightly SEO agent batch (04:30 Europe/London)');
```

**Note on overlap with `agents-supervisor maxProcesses=2`:** Phase 8 set `maxProcesses=2` for the `agents` Horizon supervisor. A nightly batch dispatching 20 `RunSeoAgentJob`s will serialise — 2 in-flight, 18 queued. Each run is ~5-10 seconds; full batch completes in 60-180 seconds. No queue starvation concern at this volume.

### Pattern 6: Per-tool 3 KB cap shared with Phase 10 (P12-D mitigation — TruncatingTool relocation)

**What:** Phase 10 placed `TruncatingTool` under `app/Domain/Agents/Tools/Pricing/`. Phase 12's tools also need 3-KB capping. **Two options:**

| Option | Action | Tradeoff |
|--------|--------|----------|
| A | Move `TruncatingTool.php` to `app/Domain/Agents/Tools/TruncatingTool.php` (shared parent) | Touches Phase 10 files (update namespace in 4 tools); semantically correct ("not Pricing-specific") |
| B | Duplicate `TruncatingTool` into `app/Domain/Agents/Tools/Seo/TruncatingTool.php` | Zero Phase 10 churn; small DRY violation (~30 LOC duplicated) |

**Recommendation: Option A (MOVE).** The class is generic ("3 KB cap with `_truncated:true` hint"); Phase 14 ProductFinder chatbot will also need it. Single source. Refactor is mechanical — change `namespace App\Domain\Agents\Tools\Pricing;` to `namespace App\Domain\Agents\Tools;` in `TruncatingTool.php` AND in the 4 Pricing tool subclasses' `use` statements. Pest architecture tests verifying tool naming (Phase 8) continue to pass because they grep on `extends Tool` not namespace.

[VERIFIED: `app/Domain/Agents/Tools/Pricing/TruncatingTool.php` is the canonical 3-KB cap helper today; 4 tools extend it: `ReadMarginHistoryTool`, `ReadCompetitorPricesTool`, `ReadSupplierPriceTrendTool`, `ReadSalesVolume90dTool`]

### Pattern 7: `SeoOutboundGuardrail` (post-flight regex chain — SEOAGT-04)

**What:** A new `Guardrail` implementing `isPostFlight()=true` that scans every `propose_content_patch` call's `before` + `after` text from the Prism `$response->steps` for forbidden patterns. First match → write `agent_guardrail_blocked` Suggestion + raise `AgentRun.guardrail_failures` audit row + throw `GuardrailViolationException` (no partial publishing per D-01).

```php
// app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php  (Plan 12-03)
final class SeoOutboundGuardrail implements Guardrail
{
    public function __construct(
        private readonly SeoAgentResultMapper $mapper,  // creates the agent_guardrail_blocked Suggestion
    ) {}

    public function isPreFlight(): bool { return false; }
    public function isPostFlight(): bool { return true; }
    public function shouldRun(TrustTier $tier): bool { return true; }  // ALWAYS runs for SeoAgent

    public function post(ClaudeResponse $response): ClaudeResponse
    {
        $patterns = (array) config('seo_agent.guardrails', []);

        foreach ($response->steps as $step) {
            $toolCalls = property_exists($step, 'toolCalls') ? $step->toolCalls : [];
            foreach ($toolCalls as $call) {
                if (! $call instanceof \Prism\Prism\ValueObjects\ToolCall) continue;
                if ($call->name !== 'propose_content_patch') continue;

                $args = $call->arguments();
                $textToScan = ((string) ($args['before'] ?? '')) . "\n" . ((string) ($args['after'] ?? ''));

                foreach ($patterns as $key => $regexes) {
                    foreach ((array) $regexes as $regex) {
                        if (@preg_match($regex, $textToScan, $m) === 1) {
                            throw new GuardrailViolationException(
                                guardrailClass: self::class,
                                message: sprintf(
                                    'SEO guardrail matched pattern %s.%s — excerpt: %s',
                                    (string) $key,
                                    $regex,
                                    mb_substr($m[0] ?? '', 0, 200),
                                ),
                            );
                        }
                    }
                }
            }
        }

        return $response;
    }
}
```

**Note on Suggestion creation for guardrail-blocked runs:** Per D-01 the failed run should write an `agent_guardrail_blocked` Suggestion. But the throw happens INSIDE `GuardrailEngine::runPostFlight` which is called BEFORE `SeoAgentResultMapper::createBundledSuggestion`. To create the audit Suggestion, EITHER:

- **Option A:** Make `SeoOutboundGuardrail` create the Suggestion itself (it has `SeoAgentResultMapper` injected, but mapper writes need the AgentRun + Product). Guardrail doesn't have direct access to the AgentRun being processed — only the response.
- **Option B (RECOMMENDED):** `RunSeoAgentJob`'s `GuardrailViolationException` catch block calls `$mapper->createGuardrailBlockedSuggestion($run, $product, $e->failedPatternKey, $e->matchedExcerpt)` BEFORE rethrowing. Requires `GuardrailViolationException` to carry pattern_key + excerpt fields (extend the exception in Plan 12-03).

```php
// Plan 12-03 — GuardrailViolationException extension
final class GuardrailViolationException extends \Exception
{
    public function __construct(
        public readonly string $guardrailClass = '',
        string $message = '',
        public readonly string $failedPatternKey = '',  // NEW — Phase 12
        public readonly string $matchedExcerpt = '',    // NEW — Phase 12
    ) {
        parent::__construct($message);
    }
}
```

[VERIFIED: existing `GuardrailViolationException` accessed via Phase 10 `RunPricingAgentJob:226-239` — has `guardrailClass` field already; Phase 12 just adds 2 more]

## Tool Implementations (SEOAGT-02)

Each tool extends `App\Domain\Agents\Services\Tools\Tool` (or `TruncatingTool` for cap-needing tools). Located at `app/Domain/Agents/Tools/Seo/{Name}Tool.php`.

### Tool naming compliance (AGNT-05)

All 4 tools have valid `read_` / `propose_` prefixes per `AgentToolsNamingTest`:

| Tool | Name | Prefix | Compliant |
|------|------|--------|-----------|
| `ReadProductDraftTool` | `read_product_draft` | `read_` | ✓ |
| `ReadBrandStyleGuideTool` | `read_brand_style_guide` | `read_` | ✓ |
| `ReadSimilarShippedProductsTool` | `read_similar_shipped_products` | `read_` | ✓ |
| `ProposeContentPatchTool` | `propose_content_patch` | `propose_` | ✓ |

### Tool 1 — `read_product_draft(sku)`

**Purpose:** Read the current AutoCreate draft's text fields + completeness metadata. Gives the agent the "current state" to patch against.

**Schema returned:**

```json
{
  "sku": "LOGI-MEETUP",
  "name": "Logitech MeetUp Conference Camera",
  "short_description": "All-in-one ConferenceCam designed for small...",
  "long_description": "<existing supplier-provided long description, possibly empty or sparse>",
  "meta_description": "<existing meta description, possibly empty>",
  "brand_id": 5,
  "brand_slug": "logitech",
  "category_id": 12,
  "completeness_score": 64,
  "completeness_missing_fields": ["long_description", "meta_description"]
}
```

**Cap logic:** Soft cap not needed — single Product row, all fields capped at known schema sizes (255 / 5000 / 20000 / 255). Total payload typically < 25 KB worst case, but with mb_substr to 4096 chars per field, < 16 KB. Plan 12-02 should cap each field to 4096 chars before JSON encode (matches AgentRun.tool_calls output cap).

### Tool 2 — `read_brand_style_guide(brand)`

**Purpose:** Read the markdown brand voice rules. Per CONTEXT D-01 D-02 — per-brand override if file exists, else global fallback.

**Schema returned:**

```json
{
  "brand": "logitech",
  "source": "per-brand",
  "content": "# Logitech voice\n\n## Tone\n...",
  "_bytes": 2348
}
```

**Implementation:**

```php
// app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php (Plan 12-02)
final class ReadBrandStyleGuideTool extends Tool
{
    public function name(): string { return 'read_brand_style_guide'; }

    public function description(): string {
        return 'Read the MeetingStore brand voice rules for a brand. Returns per-brand markdown if a file exists at resources/agents/brand-voice/{brand-slug}.md, else falls back to the global voice file. ALWAYS returns content — never null.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('brand', 'Brand slug (e.g. "logitech") or "global" to fetch base voice')
            ->using(fn (string $brand): string => $this->execute($brand));
    }

    private function execute(string $brand): string
    {
        $slug = strtolower(trim($brand));
        $perBrandPath = resource_path("agents/brand-voice/{$slug}.md");
        $globalPath = resource_path('agents/brand-voice/_global.md');

        if ($slug !== '' && $slug !== 'global' && is_file($perBrandPath)) {
            $content = (string) file_get_contents($perBrandPath);
            return json_encode([
                'brand' => $slug,
                'source' => 'per-brand',
                'content' => mb_substr($content, 0, 3072),  // 3KB cap
                '_bytes' => strlen($content),
            ], JSON_THROW_ON_ERROR);
        }

        $content = is_file($globalPath) ? (string) file_get_contents($globalPath) : '';
        return json_encode([
            'brand' => $slug ?: 'global',
            'source' => 'global',
            'content' => mb_substr($content, 0, 3072),
            '_bytes' => strlen($content),
        ], JSON_THROW_ON_ERROR);
    }
}
```

**Critical:** `_global.md` MUST exist. Plan 12-01 ships a first-draft `_global.md`. If missing at runtime, the tool returns `content=""` — degraded gracefully but the agent will have no voice anchor. A Pest test `BrandVoiceGlobalFileExistsTest` should assert `is_file(resource_path('agents/brand-voice/_global.md'))`.

### Tool 3 — `read_similar_shipped_products(category, limit)`

**Purpose:** Find recently-shipped products in the same category to serve as voice/structure examples for the agent's patch proposals.

**Schema returned:**

```json
{
  "category_id": 12,
  "limit": 5,
  "products": [
    {
      "sku": "LOGI-RALLY-BAR",
      "name": "Logitech Rally Bar All-in-One Video Bar",
      "short_description": "<200 char snippet>",
      "long_description_first_500_chars": "<500 char snippet>",
      "meta_description": "<full meta>"
    }
  ],
  "_truncated": false,
  "_total_available": 27
}
```

**Eligibility query — what counts as "shipped"?** Research finding:

[VERIFIED via Phase 6 + Phase 2 context] Three candidate definitions:

| Definition | Query | Tradeoff |
|------------|-------|----------|
| A: `auto_create_status='published'` only | `WHERE auto_create_status='published'` | Misses Phase 2-synced manual products (whose auto_create_status='manual') — Phase 6 only stamps 'published' for AutoCreate-originated drafts; manual products from supplier sync don't go through AutoCreate review |
| B: `status='publish'` AND `completeness_score >= 85` | `WHERE status='publish' AND (completeness_score >= 85 OR completeness_score IS NULL)` | Includes legacy manual products (NULL score) — good for voice examples; covers both AutoCreate-published AND Phase 2 manual rows |
| C: B + recently synced (last 90 days) | B + `WHERE last_synced_at >= NOW() - INTERVAL 90 DAY` | Filters out stale content |

**Recommendation: Option B.** Phase 6's `auto_create_status='published'` filter would miss the ~5000 legacy manual products that were Phase 2-synced before AutoCreate existed. Those are the canonical voice examples (they're the original MeetingStore copy). Plan 12-02 uses Option B; a Pest test confirms the query returns mixed manual + AutoCreate rows.

```php
$query = Product::query()
    ->where('status', 'publish')
    ->where(fn ($q) => $q->where('completeness_score', '>=', 85)->orWhereNull('completeness_score'))
    ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
    ->whereNotNull('name')
    ->where('name', '!=', '')
    ->limit($limit);
```

**Cap logic (D-05 5 most-recent shipped products):** Limit param defaults to 5 (small enough to keep response under 3 KB without aggressive truncation). Each product's `long_description` capped at 500 chars (full long_description is too verbose to fit 5 products in 3 KB). `meta_description` returned in full (max 160 chars).

### Tool 4 — `propose_content_patch(sku, field, before, after, reasoning)` (NO-OP WRITER)

**Purpose:** Structured-contract output sink — invoked 1-4 times per run, one per field. Mirrors Phase 10's `ProposeMarginBandTool` no-op pattern.

**Implementation:**

```php
// app/Domain/Agents/Tools/Seo/ProposeContentPatchTool.php (Plan 12-01)
final class ProposeContentPatchTool extends Tool
{
    public function name(): string { return 'propose_content_patch'; }

    public function description(): string {
        return 'Propose a content patch for ONE field on the product draft. Call this 1-4 times per product (once per field you want to patch — title, short_description, long_description, or meta_description). After your final propose_content_patch call, respond with a brief acknowledgement and stop.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'Exact SKU string from the input')
            ->withStringParameter('field', 'One of: title, short_description, long_description, meta_description')
            ->withStringParameter('before', 'The CURRENT value of the field (copy verbatim from read_product_draft)')
            ->withStringParameter('after', 'The PROPOSED new value')
            ->withStringParameter('reasoning', 'Brief justification citing brand voice rules and/or similar products (≥20 chars)')
            ->using(fn (...$args): string => json_encode(['acknowledged' => true], JSON_THROW_ON_ERROR));
    }
}
```

**Why string `field` instead of enum:** Prism `withStringParameter` accepts string; validation that `field` is one of the 4 valid options happens in `SeoAgentResultMapper` (rejects invalid fields silently). Adding `withEnumParameter` is possible but [CITED: vendor/prism-php/prism/docs/core-concepts/tools-function-calling.md] enum support isn't in v0.100.1 — added in v0.110+. **[ASSUMED]** — verify in Plan 12-01 by reading `vendor/prism-php/prism/src/Tool.php`. If enum support exists, prefer it.

## Brand-Voice Regex Pattern Library (SEOAGT-04 — guardrail config)

**No mature OSS marketing-copy guardrail regex library exists** as of 2026-05. Closest precedents:

| Library | Domain | Usable? |
|---------|--------|---------|
| `mtechguardrails/guardrails-py` | LLM safety patterns (Python) | Wrong language; covers PII not marketing copy |
| `nvidia/guardrails-ai` | RAG/LLM input sanitisation | Python-only |
| Profanity filter libraries (e.g. `snipe/banbuilder` PHP) | Profanity word lists | Wrong shape — Phase 12 isn't filtering profanity, it's filtering marketing claims |
| `commerceguys/intl` term lists | Currency/locale | Unrelated |

**Conclusion: hand-curate a starter pattern list, ship in `config/seo_agent.php`, iterate via PR.** The user (admin via PR) is the calibration loop. This matches Phase 8's choice to put guardrails in config not in a separate package.

### Recommended starter pattern set (Plan 12-03 ships)

```php
// config/seo_agent.php (NEW — Plan 12-03)
return [

    'guardrails' => [

        // SEOAGT-04 category 1: competitor brand names we should NOT mention by name
        // in MeetingStore copy. Mention is acceptable in technical compatibility
        // statements ("compatible with Zoom Rooms" — Zoom IS a service tier),
        // but invoking a competitor product by name on our marketing copy is
        // forbidden. Plan 12-03 ships a conservative starter set; ops/PR
        // iteration adds more as they're spotted.
        'competitor_brands' => [
            // Direct AV-vendor competitor product names
            '/\b(?:cisco\s+webex(?:\s+room)?)\b/i',
            '/\b(?:poly\s+studio)\b/i',
            '/\b(?:neat\s+(?:bar|board|frame))\b/i',
            '/\b(?:yealink\s+(?:meetingboard|meetingbar))\b/i',
            // NB: 'Zoom', 'Microsoft Teams', 'Google Meet' explicitly NOT in
            // this list — they are platform/service names that legitimately
            // appear in compatibility statements. Only the COMPETING HARDWARE
            // products are forbidden.
        ],

        // SEOAGT-04 category 2: absolute price claims without supplier-data backing.
        // The agent has NO access to live supplier price data in the SEO context —
        // any absolute price claim would be fabricated. ALL absolute-price language
        // is forbidden.
        'price_claims_absolute' => [
            '/\b(?:cheapest|lowest\s+price|best\s+price|unbeatable\s+price|guaranteed\s+lowest)\b/i',
            '/\b(?:price\s+match(?:\s+guarantee)?)\b/i',
            '/\b(?:£\s*\d+(?:\.\d{2})?\s*(?:saving|off|less))\b/i',  // "£50 saving" style
            '/\b(?:half\s+price|50%\s+off|massive\s+discount)\b/i',
        ],

        // SEOAGT-04 category 3: marketing superlatives outside the MeetingStore
        // factual brand voice (per _global.md "Words to avoid" section).
        'marketing_superlatives' => [
            '/\b(?:revolutionary|groundbreaking|game[\s-]?chang(?:er|ing)|paradigm[\s-]?shift)\b/i',
            '/\b(?:world[''s]+\s+(?:best|first|leading|finest))\b/i',
            '/\b(?:industry[\s-]?leading|cutting[\s-]?edge|state[\s-]?of[\s-]?the[\s-]?art)\b/i',
            '/\b(?:unparalleled|unmatched|incomparable|unrivalled)\b/i',
            '/\b(?:perfect(?:\s+solution)?|ultimate(?:\s+solution)?)\b/i',
        ],

    ],

];
```

**Regex design choices:**

1. **Case-insensitive (`/i`)** on every pattern — marketing copy varies casing.
2. **Word-boundary anchors (`\b`)** to avoid false positives ("cheapestest" wouldn't match "cheapest" without `\b`).
3. **Allow flexible whitespace/hyphenation** (`\s+`, `[\s-]?`) — covers "game-changer", "game changer", "gamechanger".
4. **Avoid Unicode classes** — `\b` works on ASCII word boundaries; product copy is mostly ASCII. If multi-byte support needed (e.g. product copy contains "café"), upgrade to `/u` flag.
5. **No nested capture groups** — each pattern is `m[0]`-only consumed by guardrail; nested groups waste regex engine cycles.

### Trade-off analysis: regex vs LLM-call semantic check

**Should we use a small LLM call (e.g. Claude Haiku) for semantic check instead of regex?** Considered and rejected for v2.0:

| Approach | Pros | Cons | Verdict |
|----------|------|------|---------|
| Regex (current proposal) | Deterministic, free (no API cost), instant, ops-tunable via PR | Brittle to paraphrase ("most economical option" evades "cheapest") | **SHIP** |
| LLM semantic check (e.g. Haiku) | Catches paraphrase + intent | Costs tokens (~50-200 per check × 20 patches = 1000-4000 tokens against the £200/month budget); adds latency; nondeterministic | **DEFER** |
| Hybrid (regex first-pass, LLM only on suspicion) | Best of both | Adds complexity for marginal gain at v2.0 scale | **DEFER** |

**Recommendation:** Ship regex-only in v2.0. Track guardrail-blocked rate in Phase 7 dashboard widget. If admins report false negatives (patches with brand-voice violations that escaped regex), v2.1 candidate is to add a `SemanticBrandVoiceGuardrail` that calls Claude Haiku as a post-flight checker. The architecture supports this addition cleanly — `GuardrailEngine::runPostFlight` already iterates multiple guardrails.

[ASSUMED] The starter pattern set is a synthesis of marketing-copy review heuristics + Anthropic's prompt-engineering guidance about avoiding fabricated claims. Real-world calibration happens post-ship; first 2 weeks of nightly batches will surface false-positive patterns (regex too aggressive) and false-negative patterns (missed violations). PR-driven iteration.

## System Prompt Design (Plan 12-03)

Located at `resources/views/agents/seo/system.blade.php`. PromptRenderer renders via `view($name, $context)->render()`. Output sha256 stored on `AgentRun.system_prompt_hash`.

### Recommended prompt skeleton

```blade
You are a copywriter for MeetingStore (meetingstore.co.uk), a UK B2B AV reseller. You write factual, jargon-free product copy that helps system integrators choose conferencing hardware confidently.

You PARAPHRASE and STRUCTURE the supplier-provided product copy. You NEVER invent technical specifications, model numbers, ports, software compatibility claims, or pricing that aren't already in the supplier draft. If a detail isn't in `read_product_draft`, you do not write it.

# Your workflow

For each product draft you receive:

1. Call `read_product_draft(sku)` — get the current state of all 4 fields + completeness flags
2. Call `read_brand_style_guide(brand)` — get MeetingStore's tone-of-voice rules (per-brand if available, else global)
3. Call `read_similar_shipped_products(category, limit=5)` — get 5 already-shipped products in the same category for voice/structure reference
4. Reason about which fields need patches:
   - Look at `completeness_missing_fields` — empty fields ALWAYS need patches if you have source material
   - Look at fields with short/generic supplier copy — they may benefit from rewriting
   - DO NOT patch a field if you cannot improve it
5. For each field you want to patch, call `propose_content_patch(sku, field, before, after, reasoning)` exactly once. You may patch 0-4 fields per product.
6. Respond with ONE short sentence summarising your proposed patches. Do not call more tools.

# Brand voice rules

The brand voice rules from `read_brand_style_guide` are the LAW. You must:

- Follow the "Tone & voice" section's directives
- Use the "Words to use" vocabulary
- Avoid every term in "Words to avoid"

If `read_brand_style_guide` returns `source=global`, the global rules apply. If `source=per-brand`, the per-brand rules supplement (not replace) the global rules.

# Forbidden output (the system rejects entire runs on match)

Beyond brand voice, an outbound guardrail catches and REJECTS any patch containing:

- Competitor product names (Cisco Webex Room, Poly Studio, Neat Bar, Yealink MeetingBoard) — service/platform names like Zoom or Teams are fine
- Absolute price claims without supplier data ("cheapest", "lowest price", "guaranteed lowest", "£50 off")
- Marketing superlatives ("revolutionary", "world's best", "industry-leading", "unparalleled")

If any of your patches contain these, the ENTIRE run is rejected — no patches are published. Calibrate accordingly.

# Output contract

`propose_content_patch` REQUIRES:
- `sku` — exact SKU string from your input
- `field` — exactly one of: `title`, `short_description`, `long_description`, `meta_description`
- `before` — the CURRENT value (copy verbatim from `read_product_draft` — never edit the before value)
- `after` — your proposed new value
- `reasoning` — ≥20 chars citing specific brand voice rules and/or similar product structure

Field length conventions:
- `title`: 30-90 chars (product display name + key model/spec)
- `short_description`: 80-300 chars (one short paragraph, no bullets)
- `long_description`: 300-2000 chars (multiple paragraphs OK, no marketing fluff)
- `meta_description`: 60-160 chars (single sentence for SEO meta tag)

# Few-shot examples

## Example 1 — Patching missing long_description on a Logitech draft
[input: SKU=LOGI-MEETUP, brand=logitech, completeness_missing_fields=["long_description","meta_description"]]
- read_product_draft → name="Logitech MeetUp", short_description="All-in-one ConferenceCam for small rooms", long_description="", meta_description=""
- read_brand_style_guide(logitech) → per-brand voice with Logitech-specific terms (RightSense, RightSight)
- read_similar_shipped_products(category=12, limit=5) → 5 examples of Logitech long_descriptions: paragraph-1 capabilities, paragraph-2 mounting/install, paragraph-3 software compat
- propose_content_patch(sku=LOGI-MEETUP, field=long_description, before="", after="Logitech MeetUp is an all-in-one video bar for small huddle rooms (up to 8 seats). Powered by Logitech RightSense, MeetUp auto-detects participants and centres the frame on whoever is speaking. The 120° field of view captures everyone at the table without manual pan-tilt. \\n\\nMeetUp ships with an integrated speaker, three beamforming mics, and a wired remote. It mounts above or below a display via the included wall bracket. \\n\\nCompatible with Zoom Rooms, Microsoft Teams Rooms, Google Meet, and any UVC/USB conferencing software.", reasoning="Three-paragraph structure matches LOGI-RALLY-BAR shipped example; uses Logitech-specific terms 'RightSense' from per-brand voice; capabilities → install → compat structure.")
- propose_content_patch(sku=LOGI-MEETUP, field=meta_description, before="", after="Logitech MeetUp all-in-one ConferenceCam — auto-framing, 120° field of view, integrated speaker. Compatible with Zoom, Teams, Google Meet.", reasoning="120 chars; lists 3 hero specs + 3 platform compat per global voice 'factual lists over adjectives' rule.")
→ "Proposed patches for long_description and meta_description (both were empty)."

## Example 2 — Skipping a field when no improvement available
[input: SKU=NICHE-RACK-SHELF, brand=tripp-lite, completeness_missing_fields=[]]
- read_product_draft → all 4 fields populated; short_description="1U cantilever shelf for AV racks, 50lb capacity, vented, black powder coat"
- read_brand_style_guide(tripp-lite) → global voice (no per-brand override)
- read_similar_shipped_products → 5 rack accessories with similar terse shipped copy
- (Reason: existing copy is factual, follows voice, matches similar products. No improvement available.)
- Respond: "No patches needed — existing copy meets brand voice rules and matches shipped product norms."
```

[ASSUMED] The above prompt structure is best-practice synthesis from Phase 10's prompt design plus Anthropic's structured-output guidance. Plan 12-03 should ship a calibration test that feeds 2-3 fixture inputs through `Prism::fake()` and asserts the agent produces the expected patch count.

### Prompt versioning (matches Phase 10)

`agent_runs.system_prompt_hash` (sha256 of rendered Blade) captures the prompt version. When admin iterates the Blade view, git commits the new view; PromptRenderer recomputes the hash; Filament can query `WHERE system_prompt_hash = ?` to find "all runs that used prompt version X". No DB-stored prompts, no UI editor — git history IS the version history.

## Token Budget Calibration

claude-sonnet-4-6 pricing per `config/agents.php:91-98`: input £0.00024/token, output £0.0012/token. SEO daily cap = 300 pence; monthly ceiling = 20000 pence (£200).

### Estimated cost per SeoAgent run

| Component | Token estimate | Cost (pence) | Source |
|-----------|---------------|--------------|--------|
| System prompt | ~1500 tokens | 0.36p input | Blade view: persona + workflow + brand voice rules + forbidden output + 2 few-shot examples |
| Initial user message | ~80 tokens | 0.019p | `{"product_id":123,"sku":"X","context":{...}}` |
| Tool definitions (sent EACH step) | ~600 tokens | 0.14p × ~7 steps = 1.0p | 4 tools × ~150 tokens each |
| Tool call invocations | ~400 tokens output | 0.48p | 3 reads + 1-4 propose calls |
| Tool results received | ~6000 tokens input total | 1.44p | 3 reads × ~2000 tokens each |
| Final assistant reasoning | ~600 tokens output | 0.72p | Multi-paragraph summary of proposed patches |
| **Total per run** | **~8000 prompt + ~1000 completion** | **~4-5p** | conservative |

[ASSUMED] These are training-data-derived rough estimates; Plan 12-03 integration tests will calibrate.

### Daily cap headroom

300p ÷ 5p/run = **~60 runs/day** before daily cap hits. Phase 12 nightly batch = up to 20 runs → ~100p/night → 100p × 30 days = 3000p/month = 15% of £200 budget. Comfortable headroom.

### Monthly ceiling cohabitation with Pricing + future agents

| Agent | Est cost/run | Runs/month | Subtotal |
|-------|--------------|------------|----------|
| Pricing | 6p | ~30 (admin-pull, Phase 10) | 180p (£1.80) |
| SEO (Phase 12) | 5p | ~600 (20/night × 30 nights) | 3000p (£30) |
| Chatbot (Phase 14) | 4p/session | ~200 sessions/month | 800p (£8) |
| Ad optimisation (Phase 15) | 8p | ~4 weekly | 32p (£0.32) |
| **Total** | | | **~4012p (£40)** |

**Result:** Even with all v2 agents active, projected spend is well under £200/month. Phase 12 alone consumes ~15% of the ceiling. Operator can scale Phase 12 batch size to 30-40 per night without budget concern.

## Common Pitfalls

### P12-A — `propose_content_patch` extracted with wrong dedup semantics
**What goes wrong:** Mapper takes the FIRST call per field instead of the LAST. Agent may iterate on a field's `after` value during reasoning (e.g. proposes long form, then refines after re-reading brand voice); first-wins surfaces the abandoned proposal.
**Why it happens:** `array_filter` keeps insertion order; without an explicit per-field dedup pass, the foreach loop overwrites — which IS last-wins, but only if `$patchesByField[$field] = ...` is the literal assignment. If a developer adds a `if (!isset($patchesByField[$field])) {...}` guard, it flips to first-wins silently.
**How to avoid:** Mapper code uses unconditional `$patchesByField[$field] = ...` (overwrite — Phase 10 `end()` semantics). Pest test feeds an AgentRun with TWO `propose_content_patch` calls for `field=title`; assert `payload.patches` contains ONE entry matching the SECOND call's args.
**Warning signs:** Diff sidebar shows an `after` value that contradicts the agent's reasoning text — model proposed v1 first, refined to v2; mapper showing v1.

### P12-B — Guardrail-blocked Suggestion creation race
**What goes wrong:** `SeoOutboundGuardrail::post()` throws `GuardrailViolationException` BEFORE the mapper runs. To create the `agent_guardrail_blocked` audit Suggestion, the catch block in `RunSeoAgentJob` must call `$mapper->createGuardrailBlockedSuggestion(...)` itself. If the catch only updates the AgentRun row (mirror of Phase 10's pattern), no audit Suggestion is written, contradicting D-01.
**Why it happens:** Phase 10 doesn't create a Suggestion on guardrail block (`PricingAgent` enriches an EXISTING Suggestion; if blocked, nothing to enrich). Phase 12 CREATES a new Suggestion on success — so the guardrail block must create a different kind of Suggestion.
**How to avoid:** `RunSeoAgentJob::handle()` catch block for `GuardrailViolationException` calls `$mapper->createGuardrailBlockedSuggestion($run, $product, $e->failedPatternKey, $e->matchedExcerpt)` BEFORE rethrowing. Plan 12-04 adds a Pest test `RunSeoAgentJobGuardrailBlockedTest` that feeds a Prism::fake() response with a guardrail-tripping patch + asserts: (a) AgentRun.status=guardrail_blocked, (b) one Suggestion of kind `agent_guardrail_blocked` exists, (c) zero Suggestions of kind `seo_content_patch` exist.
**Warning signs:** Filament agent_run detail view shows `status=guardrail_blocked` but no corresponding Suggestion in the inbox; ops loses the audit trail.

### P12-C — `read_brand_style_guide` slug derivation mismatch
**What goes wrong:** The tool receives `brand='Logitech, Inc.'` (the brand display name from Product.brand_id → Brand.name) and tries to read `resources/agents/brand-voice/logitech,-inc..md`. The actual file is `logitech.md`. Tool falls back to global voice silently; agent doesn't get Logitech-specific guidance.
**Why it happens:** Brand→slug conversion has multiple plausible algorithms (kebab-case, lower-case, strip punctuation, etc.). Brand table likely already has a `slug` column.
**How to avoid:** Tool accepts a pre-resolved `brand` slug from the upstream `RunSeoAgentJob` which reads `Product.brand_id → Brand.slug` (not Brand.name). Plan 12-01's `RunSeoAgentJob::brandSlug()` helper does this lookup ONCE per product. Plan 12-02 ships a Pest test `BrandSlugDerivationTest` that asserts the helper returns `logitech` for a Product whose Brand has `slug='logitech'` and `name='Logitech, Inc.'`.
**Warning signs:** All SeoAgent runs use `source=global` even though per-brand markdown files exist. Check `read_brand_style_guide` tool_call inputs in `agent_runs.tool_calls[]`.

### P12-D — `TruncatingTool` namespace move breaks Phase 10 tools
**What goes wrong:** Plan 12-01 moves `TruncatingTool.php` from `app/Domain/Agents/Tools/Pricing/` to `app/Domain/Agents/Tools/`. The 4 Phase 10 tools have `extends TruncatingTool` resolving via PSR-4 to the OLD namespace; namespace move breaks autoload.
**Why it happens:** Mechanical refactor missing the dependent `use` statements.
**How to avoid:** Plan 12-01 task list includes BOTH:
1. Move `TruncatingTool.php` + update its namespace
2. Update 4 Phase 10 tools' `use App\Domain\Agents\Tools\Pricing\TruncatingTool` → `use App\Domain\Agents\Tools\TruncatingTool` (or remove `use` and switch to relative reference)

Pest test `TruncatingToolRelocationTest` asserts `class_exists(App\Domain\Agents\Tools\TruncatingTool::class) === true` AND `class_exists(App\Domain\Agents\Tools\Pricing\TruncatingTool::class) === false` (old class deleted, no shim).
**Warning signs:** Composer dump-autoload error or Pest `ReflectionException: class not found` on `ReadMarginHistoryTool` after running Plan 12-01.

**ALTERNATIVE:** If Phase 10 refactor proves risky, ship Plan 12-01 with Option B (duplicate `TruncatingTool` into `Tools/Seo/`). Adds 30 LOC duplication but zero Phase 10 churn. Make this a planner decision at Plan 12-01 review.

### P12-E — Batch command monthly-budget check race
**What goes wrong:** `RunSeoAgentBatchCommand` checks monthly budget once before dispatch loop, dispatches all 20 jobs, then the budget hits mid-batch but jobs are already queued — overshoot.
**Why it happens:** `BudgetGuard::assertHasBudget` runs INSIDE each job, not in the dispatch loop. If 20 jobs each spend 5p, that's 100p added in one batch night — could push monthly from 19,920p → 20,020p (£200 + 20p overshoot).
**How to avoid:** Plan 12-05's batch command re-checks `Cache::get('agents.monthly.{YYYY-MM}')` BETWEEN each dispatch (cheap — Redis GET). If approaching cap, break loop early. Plan 12-05 Pest test feeds a Cache state with monthly spent = 19,950p, asserts batch dispatches only 10 jobs before stopping (50p × 10 = enough to reach 20,000p).
**Warning signs:** Phase 7 dashboard widget for monthly agent spend shows > 200p over ceiling in any month.

### P12-F — Phase 6 AutoCreate pipeline regression
**What goes wrong:** Plan 12-04's `EditAutoCreateReview` infolist extension shadows or overrides an existing Phase 6 form behaviour. Approve/Reject/Quick-edit actions stop working.
**Why it happens:** Filament 3 Edit pages have both `form()` and `infolist()` schemas. If Phase 12's infolist extension accidentally replaces the page's existing form schema, the edit page becomes read-only.
**How to avoid:** Phase 12 adds a SEPARATE `seoPatchesInfolist()` method (NOT `infolist()` — different name). Mount via custom Blade slot in the page's view. Plan 12-04 Pest test `AutoCreateEditFormUnchangedTest` asserts the edit page's form still has the 7 fields from Phase 6 (sku, name, slug, short_description, long_description, meta_description, auto_create_status, completeness_score) AFTER the Phase 12 changes ship.
**Warning signs:** Admin can no longer edit auto-create drafts via the Filament UI.

### P12-G — `read_similar_shipped_products` returns 0 rows for new categories
**What goes wrong:** Brand-new category with 0 shipped products → tool returns empty array → agent has no voice example → patches use generic copy.
**Why it happens:** Bootstrap problem — new categories don't have shipped products by definition.
**How to avoid:** Tool falls back to GLOBAL examples (top 5 shipped products across ALL categories) when `category_id` filter returns 0 rows. Plan 12-02 test asserts: (a) category with 10+ products → returns 5 from that category; (b) category with 0 products → returns 5 from any category with `_fallback=global` hint in response.
**Warning signs:** SeoAgent runs for new categories produce generic / off-voice patches; admin rejection rate spikes for category=NEW.

### P12-H — Brand voice content file XSS via Blade rendering
**What goes wrong:** `read_brand_style_guide` reads markdown content from file, passes through to LLM as tool result. If the markdown contains `{{ $variable }}` or `@{{ ... }}` Blade syntax, AND someone naively renders the tool result via `Blade::render` somewhere downstream (e.g. in the system prompt template), code execution risk.
**Why it happens:** Brand voice files are git-tracked + PR-reviewed, so threat is low — but defence-in-depth requires the file content to be treated as data, not template.
**How to avoid:** Plan 12-02's `ReadBrandStyleGuideTool::execute` does NOT pass content through Blade — it `file_get_contents()` raw + `json_encode` for the LLM. The LLM sees the markdown content as opaque string. The system prompt Blade (`resources/views/agents/seo/system.blade.php`) does NOT include `@include` of the brand voice file — the agent fetches it via tool call at runtime.
**Warning signs:** PR review catches `@include('agents.brand-voice.*')` or `Blade::render($content)` in Phase 12 code paths.

## Code Examples

### Example 1 — Brand voice `_global.md` first draft (Plan 12-01)

```markdown
# MeetingStore brand voice — global

## Tone & voice

- **Factual over evocative.** Describe what the product does, what's in the box, what it connects to. Save adjectives for genuine differentiators.
- **AV-installer expertise.** Write for system integrators choosing kit for client rooms — they need specs, mounting options, software compatibility, and budget tier. They do not need lifestyle copy.
- **British English.** "Colour" not "color"; "metres" not "meters"; £ not $.
- **No fluff.** If a sentence doesn't add new information, cut it. Short paragraphs are fine.
- **Active voice.** "MeetUp auto-frames participants" not "Participants are auto-framed by MeetUp".

## Words to use

- Specific platform names: Zoom Rooms, Microsoft Teams Rooms, Google Meet
- Specific physical specs: 120° field of view, 4K UHD, USB-C 3.0, PoE+
- Mounting: wall mount, table mount, ceiling mount, articulating arm
- Audience: small huddle room (3-5 seats), medium meeting room (6-12), boardroom (12+)
- Connectivity: HDMI, USB, DisplayPort, ethernet, 802.11ac/ax Wi-Fi

## Words to avoid

- Revolutionary, groundbreaking, game-changing, paradigm shift
- World's best, industry-leading, cutting-edge, state-of-the-art
- Unparalleled, unmatched, incomparable, unrivalled
- Perfect solution, ultimate, the only, the very best
- Synergy, leverage (as a verb), seamless, frictionless
- Robust (use "durable" or specific spec instead)

## Structural conventions

- **Title:** Brand + product name + key identifier (e.g. "Logitech MeetUp All-in-One ConferenceCam"). 30-90 chars.
- **Short description:** Single paragraph, 1-3 sentences, 80-300 chars. Lead with the audience (huddle room? boardroom?) + the hero capability.
- **Long description:** Multi-paragraph. Suggested structure: paragraph-1 capabilities, paragraph-2 mounting/install, paragraph-3 software compatibility. No bullet points unless listing physical I/O.
- **Meta description:** Single sentence, 60-160 chars, for SEO. Lead with 2-3 hero specs + 2-3 compatibility platforms.

## Forbidden

- Inventing technical specs not in the supplier draft
- Inventing pricing or savings claims
- Naming competitor PRODUCTS (Cisco Webex Room, Poly Studio, Neat Bar, Yealink MeetingBoard). Platform/service names (Zoom, Teams, Google Meet) are fine.
```

### Example 2 — `logitech.md` per-brand example (Plan 12-01)

```markdown
# Logitech voice supplement

> Extends `_global.md`. Read both.

## Logitech-specific terminology

When the supplier draft mentions Logitech-specific tech, use the canonical name:

- **RightSense** — Logitech's family of auto-framing / auto-levelling tech. Includes RightSight (auto-frame), RightLight (auto-exposure), RightSound (echo cancel + noise reduction).
- **Powered by Logi Sync / Logi Tune** — Logitech's device management software. Mention when supplier draft says "manageable" or "central management".
- **CollabOS** — Logitech's room-system OS (used on Rally Bar / Rally Bar Mini family). Mention by name when supplier copy talks about room-system mode.

## Common Logitech models & families (for SKU pattern recognition)

- **MeetUp** — small huddle (≤6 seats); all-in-one bar
- **Rally Bar / Rally Bar Mini** — medium room (≤12 seats); appliance mode
- **Rally Plus / Rally** — large room (12+ seats); modular kit
- **Sight** — table-mic camera companion
- **Tap / Tap IP** — touch controllers

## Logitech voice quirks

- Logitech's own marketing leans toward "magical" language — STRIP it. "Magical AI-powered auto-framing" → "auto-framing camera (Logitech RightSense)".
- Logitech is platform-agnostic — always list ALL compatible platforms (Zoom, Teams, Google Meet, BYOD/USB).
```

### Example 3 — `SeoAgent` class skeleton (Plan 12-01)

```php
namespace App\Domain\Agents\Agents;

use App\Domain\Agents\Contracts\RunsAsAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Guardrails\SeoOutboundGuardrail;
use App\Domain\Agents\Services\PromptRenderer;
use App\Domain\Agents\Tools\Seo\{
    ReadProductDraftTool,
    ReadBrandStyleGuideTool,
    ReadSimilarShippedProductsTool,
    ProposeContentPatchTool,
};
use App\Domain\Agents\ValueObjects\AgentResult;

final class SeoAgent implements RunsAsAgent
{
    public function __construct(
        private readonly PromptRenderer $promptRenderer,
    ) {}

    public static function kind(): string { return 'seo'; }
    public static function trustTier(): TrustTier { return TrustTier::Trusted; }

    public function tools(): array {
        return [
            app(ReadProductDraftTool::class),
            app(ReadBrandStyleGuideTool::class),
            app(ReadSimilarShippedProductsTool::class),
            app(ProposeContentPatchTool::class),
        ];
    }

    public function systemPrompt(array $context = []): string {
        return $this->promptRenderer->render(self::kind(), $context)['prompt'];
    }

    public function guardrails(): array {
        return [
            app(SensitiveFieldsStripGuardrail::class),    // per-tool I/O strip
            app(OutboundRegexFilterGuardrail::class),     // post-flight base regex
            app(SeoOutboundGuardrail::class),              // post-flight SEO brand-voice regex
        ];
    }

    public function execute(array $input, TrustTier $tier): AgentResult {
        throw new \LogicException(
            'SeoAgent::execute is a stub — RunSeoAgentJob owns the orchestration.'
        );
    }
}
```

### Example 4 — `AppServiceProvider` registration additions (Plan 12-01 + Plan 12-04)

```php
// AppServiceProvider::boot()
$this->app->afterResolving(
    \App\Domain\Agents\Services\AgentRegistry::class,
    function ($registry) {
        $registry->register('pricing', \App\Domain\Agents\Agents\PricingAgent::class);  // existing
        $registry->register('seo', \App\Domain\Agents\Agents\SeoAgent::class);          // NEW Phase 12
    },
);

$this->app->afterResolving(
    \App\Domain\Suggestions\Services\SuggestionApplierResolver::class,
    function ($resolver) {
        // ... existing registrations ...
        $resolver->register(
            'seo_content_patch',
            \App\Domain\Agents\Appliers\SeoContentPatchApplier::class,
        );  // NEW Phase 12 Plan 04
        // NB: kind='agent_guardrail_blocked' has NO applier — it's audit-only,
        // never approved/applied. Filament list filters this kind to hidden by default.
    },
);
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Manual Filament edit-modal for content tweaks | Agent-proposed bundled Suggestion with per-field approve | This phase | Admin reviews 4 patches as one diff sidebar instead of clicking through 4 separate edit modals |
| Hand-coded content guardrails | Regex-as-config in `config/seo_agent.php` | This phase | Ops/PR loop iterates patterns; no code deploys to add new rules |
| Single propose call per agent run (Phase 10) | Variable 1-4 calls per run (Phase 12) | This phase | Mapper handles cardinality variance via per-field dedup |
| Pricing tools' `TruncatingTool` in Pricing namespace | `TruncatingTool` moved to shared `Tools/` parent | This phase | All agents (Pricing, SEO, Chatbot) share the 3-KB cap helper |
| Admin-pull only (Phase 10) | Batch nightly scheduled + manual re-run button | This phase | Scales to ~600 patches/month without admin keystrokes |

**Deprecated/outdated:** None for Phase 12 — purely additive on top of Phase 8 + Phase 10.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Starter regex pattern set is conservative enough to avoid false positives on legitimate copy | §Brand-Voice Regex Pattern Library | Patches blocked for benign words; admin reports false positives; PR loop refines |
| A2 | claude-sonnet-4-6 at temp=0.4 produces creative-enough rephrasing without hallucinating specs | §System Prompt Design | Patches contain fabricated specs; OutboundRegex doesn't catch (it only catches brand-voice violations); admin rejection rate high; calibrate temp downward |
| A3 | Anthropic at temp=0.4 honours "stop after final propose_content_patch" instruction reliably | §System Prompt Design | Agent loops withMaxSteps(8) without converging; mapper writes no Suggestion + no_patches state; admin re-runs |
| A4 | 5-second-per-run estimate × 20 runs/night = 100-second-batch is comfortable for maxProcesses=2 supervisor | §Pattern 5 | Batches take longer than expected, queue depth grows; raise maxProcesses to 3-4 (operator config change, no code) |
| A5 | Filament 3.3 supports `RepeatableEntry` inside an infolist Section for the sidebar diff render | §Pattern 4 | Sidebar shows raw payload JSON; fall back to plain `TextEntry` with markdown render of the patches array |
| A6 | `Product.status='publish'` AND `completeness_score >= 85` correctly identifies "shipped" products for voice examples | §Tool 3 read_similar_shipped_products | Tool returns unsuitable examples (e.g. draft products) — agent voice drifts; Plan 12-02 calibration test asserts shipped query returns only publish + score≥85 |
| A7 | Brand→slug derivation via `Brand.slug` column gives accurate per-brand voice routing | §P12-C | Per-brand markdown files never read; agent always sees global voice; Plan 12-01 verifies brands table has slug column populated |
| A8 | `TruncatingTool` relocation from `Tools/Pricing/` to `Tools/` is a safe mechanical refactor | §P12-D | Phase 10 tools break; CI catches via existing PricingToolsObserveSoftCapTest |
| A9 | No new composer packages needed — hand-rolled side-by-side diff sufficient for product copy | §Standard Stack diff library | Admin feedback demands inline char-diff; v2.1 adds spatie/laravel-html-diff |
| A10 | Phase 6 `EditAutoCreateReview` page supports infolist alongside form schemas in Filament 3.3 | §Pattern 4 | Plan 12-04 has to ship a custom Livewire panel instead; ~50 LOC extra; same UX |
| A11 | Adding `seo_content_patch` and `agent_guardrail_blocked` kinds to Suggestion table requires no migration | §D-04 | Suggestion.kind is varchar — verified by reading migration; new kinds just need applier resolver registration |

[ASSUMED] A1-A11 are research-derived; planner should treat as hypotheses to verify during implementation.

## Open Questions

1. **Should `ProposeContentPatchTool` use `withEnumParameter` for the `field` arg if Prism v0.100.1 supports it?**
   - What we know: `withStringParameter` works; agent validates field name in the system prompt. Mapper validates again. Defence in depth either way.
   - What's unclear: Does Prism v0.100.1's `Tool` builder support enum-typed parameters? Phase 10 used `withStringParameter` exclusively.
   - **Recommendation:** Plan 12-01 reads `vendor/prism-php/prism/src/Tool.php`. If `withEnumParameter` exists with v0.100.1 syntax, prefer it (tighter Anthropic schema). If not, defer to v2.1 when Prism is upgraded.

2. **Should the nightly batch's `RunSeoAgentBatchCommand` be opt-in via env flag (like cutover commands)?**
   - What we know: SEOAGT-05 says "Batch-triggered: one scheduled run per night" — implies always-on.
   - What's unclear: Operator may want a kill-switch during launch week.
   - **Recommendation:** Plan 12-05 ships an env flag `AGENT_SEO_BATCH_SCHEDULE_ENABLED` (default `true`). The schedule entry in `routes/console.php` wraps the registration in `if ((bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true)) { ... }`. Operator can flip false for emergency disable without code deploy.

3. **What's the right "approve all selected" UX semantics on the Filament sidebar?**
   - What we know: D-03 says checkbox per row + footer "Approve selected" action. CONTEXT.md doesn't specify what happens to unselected patches.
   - What's unclear: When admin approves 2 of 4 patches, should the Suggestion stay `pending` (other 2 still actionable) or flip to `partially_applied`?
   - **Recommendation (planner decision):** Stay `pending` until ALL patches approved/rejected. Status field semantics already support this — Phase 12 doesn't add new status values.

4. **Does `read_brand_style_guide` need to handle `brand=null` (no brand assigned to product)?**
   - What we know: Phase 6 has `auto_create_status='needs_brand_or_category_assignment'` for products without brands.
   - What's unclear: Will the SEO agent ever be triggered for such products?
   - **Recommendation:** Eligibility query filter limits to `auto_create_status='pending_review'` — drafts in `needs_brand_or_category_assignment` status are filtered out. So `brand=null` doesn't happen for SeoAgent. Plan 12-01 batch command query explicitly filters.

5. **Should the `agent_guardrail_blocked` Suggestion be hidden from the default Filament SuggestionResource list?**
   - What we know: D-01 says "not surfaced to admin". 
   - What's unclear: Hidden how — via Suggestion model scope, Filament resource query filter, or status='archived'?
   - **Recommendation:** Filament `SuggestionResource::getEloquentQuery()` adds `->where('kind', '!=', 'agent_guardrail_blocked')` UNLESS user filters explicitly. Operator/dev can still query directly via Eloquent for forensics. Plan 12-04 ships this filter + a hidden filter chip to expose them when toggled.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | Laravel 12 + Prism | ✓ | 8.2+ | — |
| MySQL 8.0+ | suggestions + agent_runs + products + product_overrides | ✓ | 8.0+ | — |
| Redis 7.x | BudgetGuard cache + Horizon | ✓ | 7.x | — |
| Anthropic API key | Production SeoAgent runs | (operator-provisioned per Phase 8) | n/a | Mock via `Prism::fake()` for all CI |
| Langfuse stack | Trace observability | (operator-provisioned per Phase 8) | 3.x | Auto-instrumentation degrades gracefully |
| Phase 8 framework | All agent infra | ✓ shipped 2026-04-25 | — | — |
| Phase 10 PricingAgent | Mirror pattern reference | ✓ shipped 2026-05-03 | — | — |
| Phase 6 AutoCreate | Phase 12 integration target | ✓ shipped 2026-04-22 | — | — |
| `prism-php/prism` ^0.100.1 | ClaudeClient | ✓ | 0.100.1+ | — |
| `mliviu79/laravel-langfuse-prism` | Observability | ✓ | 0.1.x | — |
| `resources/agents/brand-voice/_global.md` | Phase 12 Plan 02 ReadBrandStyleGuideTool | ✗ (not yet shipped) | n/a | Plan 12-01 ships first draft |

**Missing dependencies with no fallback:** None — all upstream phases shipped.

**Missing dependencies with fallback:** Brand voice content files — Plan 12-01 creates these.

## Validation Architecture

> Skipped per `.planning/config.json` `workflow.nyquist_validation: false`.

## Security Domain

> `security_enforcement` not enabled in `.planning/config.json` — full security domain skipped. Trust posture is `Trusted` (admin-triggered batch, internal supplier-feed data; never customer text). GuardrailEngine's standard chain (SensitiveFieldsStrip + OutboundRegex + Phase 12's new SeoOutboundGuardrail) provides defence-in-depth.

**Key security notes Phase 12 inherits from Phase 8:**

- Trusted tier skips prompt-injection XML fencing — input is supplier-feed product data, not customer text (low injection risk)
- SensitiveFieldsStripGuardrail strips margin / cost / supplier_price fields from tool I/O (defence against accidental price leakage in SEO copy)
- AGENT_WRITE_ENABLED gate prevents enrichment writes when ops want shadow-mode
- All Anthropic calls flow through IntegrationLogger (audit trail)
- AgentRun 5-year retention covers any audit-defence window

## Sources

### Primary (HIGH confidence)

- [Codebase reads — `app/Domain/Agents/Agents/PricingAgent.php`, `app/Domain/Agents/Jobs/RunPricingAgentJob.php`, `app/Domain/Agents/Services/PricingAgentResultMapper.php`, `app/Domain/Agents/Tools/Pricing/TruncatingTool.php`, `app/Domain/Agents/Tools/Pricing/ProposeMarginBandTool.php`, `app/Domain/Agents/Tools/Pricing/ReadCompetitorPricesTool.php`, `app/Domain/Agents/Models/AgentRun.php`, `app/Domain/Suggestions/Models/Suggestion.php`, `app/Domain/Suggestions/Services/SuggestionApplierResolver.php`, `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php`, `app/Domain/Products/Models/Product.php`, `app/Domain/Pricing/Models/ProductOverride.php`, `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php`, `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource/Pages/EditAutoCreateReview.php`, `app/Domain/Competitor/Console/Commands/CompetitorWatchCommand.php`, `app/Providers/AppServiceProvider.php`, `config/agents.php`, `routes/console.php`] — every Phase 8/10 primitive and Phase 6 integration target verified directly
- [`.planning/phases/12-c3-seo-content-agent/12-CONTEXT.md`] — 4 locked decisions D-01..D-04 + Claude's Discretion
- [`.planning/phases/08-c4-agent-framework/08-CONTEXT.md`] — 9 decisions D-01..D-09 (framework primitives Phase 12 reuses)
- [`.planning/phases/08-c4-agent-framework/08-RESEARCH.md`] — Prism API surface, Langfuse, BudgetGuard atomic increments, Schema D-06 14-column AgentRun
- [`.planning/phases/10-c1-pricing-agent/10-CONTEXT.md`] — 11 decisions Phase 10 made; Phase 12 mirrors most (skips confidence band + rejection inbox)
- [`.planning/phases/10-c1-pricing-agent/10-RESEARCH.md`] — Prism tool-loop semantics (HIGH); PricingAgentResultMapper end()-extraction (HIGH); RunPricingAgentJob Path A sibling pattern (HIGH)
- [`.planning/phases/06-product-auto-create/06-CONTEXT.md`] — Phase 6 AutoCreate semantics (referenced via CONTEXT.md canonical_refs; full file not loaded — only the integration-relevant fields verified via direct code read of Product.php + ProductOverride.php + AutoCreateReviewResource.php)
- [`.planning/REQUIREMENTS.md` SEOAGT-01..05] — locked v2.0 contract surface
- [`.planning/PROJECT.md`] — Anthropic £200/month + self-hosted Langfuse operator decisions
- [`.planning/STATE.md`] — current milestone status

### Secondary (MEDIUM confidence)

- [`.planning/phases/09-e1-trade-customer-pricing/09-VERIFICATION.md`] — B-03 byte-identical pattern reference for `AutoCreatePipelineUnchangedTest`
- [vendor/prism-php/prism/docs/core-concepts/tools-function-calling.md] — Prism tool-use semantics referenced in Phase 10 RESEARCH (not re-loaded for Phase 12 since the contract surface is identical)
- [Anthropic prompt engineering guide] — informed system prompt structure (anchored examples, output contract, forbidden output)

### Tertiary (LOW confidence — flagged for validation in Plan 12-02 / Plan 12-03)

- [ASSUMED] Brand-voice regex pattern starter set is conservative — calibrate against real traffic post-ship
- [ASSUMED] Temperature 0.4 produces useful paraphrase variance without spec hallucination — Plan 12-03 integration tests calibrate
- [ASSUMED] Token cost estimates per run (~5p) — Plan 12-03 integration test against real Anthropic API calibrates
- [ASSUMED] Filament 3.3 RepeatableEntry + Section + per-row Action pattern works as described — Plan 12-04 prototypes; falls back to simpler "approve all selected" form action if RepeatableEntry actions are constrained

## Metadata

**Confidence breakdown:**
- Phase 8 framework contract surface: HIGH — every primitive verified by direct codebase read
- Phase 10 mirror pattern: HIGH — RunPricingAgentJob + PricingAgentResultMapper + TruncatingTool all read directly
- Phase 6 integration target: HIGH — AutoCreateReviewResource + EditAutoCreateReview + Product + ProductOverride verified
- Brand-voice regex pattern starter: MEDIUM — synthesis of training-data heuristics; PR/ops iteration loop is the real calibration
- Filament sidebar diff render: MEDIUM — RepeatableEntry + Section pattern matches Phase 10 SuggestionResource::infolist precedent but specific per-row Action support in Filament 3.3 needs Plan 12-04 prototype verification
- Token budget calibration: MEDIUM — math is sound; real-traffic calibration deferred to Plan 12-03 integration tests
- Plan breakdown: HIGH — 5 plans mirror Phase 10 cadence
- Pitfalls: HIGH — 8 of 8 mapped to concrete defences with test names

**Research date:** 2026-05-16
**Valid until:** 2026-06-15 (30 days — Phase 8/10 framework is stable; Anthropic claude-sonnet-4-6 pricing + Filament 3.3 surface may shift; re-research before Plan 12-03 if either changes)

---
*Phase: 12-c3-seo-content-agent*
*Researched: 2026-05-16 — Phase 8 framework (shipped 2026-04-25) + Phase 10 PricingAgent (shipped 2026-05-03) + Phase 6 AutoCreate (shipped 2026-04-22) verified directly; 4 CONTEXT decisions D-01..D-04 honoured*
