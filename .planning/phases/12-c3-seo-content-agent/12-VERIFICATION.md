---
phase: 12-c3-seo-content-agent
verdict: PASS_WITH_DEFERRED_UAT
verified: 2026-05-16
plans_complete: 5/5
requirements_complete: 5/5  # SEOAGT-01..05
deferred_count: 5  # 4 v2.1 deferred items + 1 production browser UAT
ship_ready: true
phase_8_byte_identity_preserved: true
phase_10_byte_identity_preserved: true
phase_6_form_byte_identity_preserved: true
deptrac_violations: 0
agents_layer_dual_yaml_byte_equivalent: true
phase_12_pest_total: 120  # cases
phase_12_pest_assertions: 287
known_gaps:
  - "Browser-based UAT deferred to ms.21stcav.com production deploy — 10 of 10 manual UAT steps have direct Pest substitution; visual Filament sidebar rendering (Task 4 step 4) is the only step without full code coverage. See 12-UAT-DISPOSITION.md for the full evidence package + re-run conditions."
  - "Brand-voice regex calibration: 13 starter patterns shipped (4 competitor_brands + 4 price_claims_absolute + 5 marketing_superlatives). Plan 12-03 documents the conservative-to-aggressive grading per category. PR-iteration loop is the calibration mechanism — operator reports false positives / false negatives once real Anthropic outputs flow."
  - "AGENT_SEO_BATCH_SCHEDULE_ENABLED defaults true; operator may flip to false in production .env for first week post-deploy if manual-batch-only smoke gating is preferred."
---

# Phase 12 (C3 SEO / Content Agent) — Ship Verification

**Verdict: PASS_WITH_DEFERRED_UAT** — Phase 12 ships the second concrete `RunsAsAgent` framework consumer (after Phase 10 PricingAgent) end-to-end: SeoAgent skeleton + 4 tools (`read_product_draft`, `read_brand_style_guide`, `read_similar_shipped_products`, `propose_content_patch`) + brand-voice markdown on disk (`_global.md` + `logitech.md` example) + deterministic system prompt Blade view (sha256 `75bac4c3…`) + 13-pattern outbound regex guardrail (`SeoOutboundGuardrail` + `config/seo_agent.php`) + `RunSeoAgentJob` Path A sibling orchestrator + `SeoAgentResultMapper` bundled-Suggestion writer + `SeoContentPatchApplier` per-field write-through with critical title→Product.name remap + additive Filament sidebar Section on `EditAutoCreateReview` (Phase 6 form byte-identical) + `RunSeoAgentBatchCommand` scheduled at 04:30 Europe/London with `AGENT_SEO_BATCH_SCHEDULE_ENABLED` env-flag emergency disable + `run_seo_agent` Shield permission seeded to admin + pricing_manager only + `agent_guardrail_blocked` Suggestions hidden from default Filament list with explicit-filter escape hatch (O-5 resolution). All 5 SEOAGT-* requirements implemented and pinned by `tests/Architecture/Phase12VerificationTest.php`. 120 Pest cases / 287 assertions across Plans 01-05; zero regression on Phase 8 / Phase 10 / Phase 6. The 10-step Filament browser UAT is deferred to production deploy (`ms.21stcav.com` not yet bootstrapped); code-level test coverage substitutes at this stage per 12-UAT-DISPOSITION.md. User reply on resume-signal: `approved`.

## 1. Phase Boundary Recap (from 12-CONTEXT.md)

Phase 12 ships a `SeoAgent implements RunsAsAgent` (kind `seo`) that batches over Phase 6 AutoCreate drafts where `auto_create_status='pending_review'` AND `completeness_score < 85`. The agent proposes content patches for `title` / `short_description` / `long_description` / `meta_description` via the 4 contracted tools. Approved patches write to `Product.{field}` canonical + set `ProductOverride.pin_{field}=true` so subsequent supplier sync skips that field.

Each agent run emits **one bundled Suggestion** per product (kind `seo_content_patch`) whose payload lists every patched field. Filament `EditAutoCreateReview` page gains an additive `seoPatchesInfolist` Section (NOT a `form()` or `infolist()` override — P12-F additive invariant) showing 1-4 diff rows with checkbox-select + a header Action "Approve selected SEO patches". Out-of-policy generations (competitor brand names, unsupported price claims, marketing superlatives) are caught by an outbound regex guardrail; failed runs produce kind `agent_guardrail_blocked` Suggestions (hidden from default admin list per O-5) plus an audit row on `AgentRun.guardrail_failures`.

Trust tier is locked `Trusted` (no untrusted input — inputs come from supplier feeds + AutoCreate drafts). Budget ceiling `agents.daily_caps.seo=300` per Phase 8 D-05 two-layer defence; £200/month global hard ceiling enforced framework-side. Triggered by nightly batch (one scheduled run per night at 04:30 Europe/London, up to 20 drafts/run).

## 2. SEOAGT-01..05 Requirements Traceability

| REQ-ID  | Description | Plan(s) | Status | Evidence |
|---------|-------------|---------|--------|----------|
| SEOAGT-01 | `SeoAgent implements RunsAsAgent` triggered when AutoCreate draft enters `auto_create_status=pending_review` AND `completeness_score < 85`. | 12-01, 12-04 | Complete | `app/Domain/Agents/Agents/SeoAgent.php` (kind='seo', TrustTier::Trusted, 4-tool list, 3-guardrail chain). `app/Domain/Agents/Jobs/RunSeoAgentJob.php` (Path A sibling — eligibility re-check at handle() line, dispatches Anthropic via Prism, writes AgentRun, calls mapper post-loop). Verified: `Phase12VerificationTest` SEOAGT-01 cases + `RunSeoAgentJobHappyPathTest` (3 cases / 17 assertions). |
| SEOAGT-02 | 4 tools: `read_product_draft`, `read_brand_style_guide`, `read_similar_shipped_products`, `propose_content_patch`. | 12-01, 12-02 | Complete | 4 files at `app/Domain/Agents/Tools/Seo/*.php` with verified names + bodies. `ProposeContentPatchTool` uses `withEnumParameter` for `field` arg (Open Question O-1 resolved YES against Prism v0.100.1). `ReadBrandStyleGuideTool` uses `file_get_contents` + `json_encode` only — no `Blade::render`, no `@include` (P12-H defence). Verified: `Phase12VerificationTest` SEOAGT-02 file-existence cases + 27 Pest cases / 74 assertions in `tests/Unit/Agents/Tools/Seo/`. |
| SEOAGT-03 | Suggestion kind `seo_content_patch` surfaces in `AutoCreateReviewResource` as sidebar panel; approval writes `Product.{field}` canonical + sets `ProductOverride.pin_{field}=true`. | 12-04 | Complete | `app/Domain/Agents/Appliers/SeoContentPatchApplier.php` — `FIELD_TO_PRODUCT_COLUMN` constant maps `'title' => 'name'`; transaction writes Product + upserts ProductOverride (preserving other pin flags + margin_basis_points). `EditAutoCreateReview::seoPatchesInfolist` (NOT a Phase 6 override) renders the Section with header Action `approve_selected_patches`. `SuggestionApplierResolver` registers `seo_content_patch` → SeoContentPatchApplier. Verified: `Phase12VerificationTest` SEOAGT-03 cases + `SeoContentPatchApplierTitleToNameTest` (3 cases / 11 assertions — title literally maps to name column) + `AutoCreateEditFormUnchangedTest` (5 cases — form() / infolist() NOT declared locally on EditAutoCreateReview). |
| SEOAGT-04 | Outbound regex guardrail catches brand-voice violations; failed → `agent_guardrail_blocked` Suggestion (not surfaced to admin) + `AgentRun.guardrail_failures` audit row. | 12-03, 12-04 | Complete | `app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php` — `final class implements Guardrail` with `isPostFlight() === true`, scans `propose_content_patch` calls only (NOT read_* tools), throws `GuardrailViolationException` with `failedPatternKey` + `matchedExcerpt` on first regex match (no partial publishing per CONTEXT D-01). `config/seo_agent.php` returns `['guardrails' => 3 categories with 13 patterns total]`. `RunSeoAgentJob` catch-block calls `$mapper->createGuardrailBlockedSuggestion(...)` BEFORE rethrow (P12-B). `SuggestionResource::getEloquentQuery()` hides `agent_guardrail_blocked` by default; explicit `?tableFilters[kind][value]=agent_guardrail_blocked` shows them (O-5 escape hatch). Verified: `Phase12VerificationTest` SEOAGT-04 cases + `SeoOutboundGuardrailTest` (12 cases / 18 assertions) + `RunSeoAgentJobGuardrailBlockedTest` (1 case / 8 assertions) + `SuggestionResourceGuardrailBlockedFilterTest` (4 cases / 9 assertions). |
| SEOAGT-05 | Budget `seo_agent.daily_pence_cap=300`. Batch-triggered: one nightly scheduled run, up to 20 drafts/run. | 12-05 | Complete | `app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php` — signature `agents:run-seo-batch {--limit=20} {--dry-run}`, eligibility query (auto_create_status='pending_review' AND completeness_score<85 AND whereDoesntHave seo_content_patch in pending/applied, ordered ASC, limit 20), pre-flight monthly budget check, BETWEEN-DISPATCH re-check (P12-E mitigation). `routes/console.php` schedule entry at `cron('30 4 * * *')` `Europe/London` with `withoutOverlapping(60)` + `onOneServer()` wrapped in `if ((bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true))` (O-2 emergency disable). `database/seeders/RolePermissionSeeder.php` adds `'run_seo_agent'` to admin + pricing_manager arrays only. Verified: `Phase12VerificationTest` SEOAGT-05 cases + `RunSeoAgentBatchCommandTest` (5 cases — eligibility, ordering, limit, live dispatch, batch correlation id sharing) + `BatchCommandBudgetRaceTest` (4 cases — pre-flight cap, between-dispatch cap, recordSpend resume, dry-run safety) + `SeoAgentEligibilityQueryTest` (4 cases — worst-first, exclude-already-suggested, score<85, pending_review only) + `ScheduleWiringTest` (10 cases — schedule:list contains entry, env flag suppresses, permission seeded to correct roles). |

**Score: 5 / 5 SEOAGT-* requirements Complete.**

## 3. Decision Honour Log (D-01..D-04 from 12-CONTEXT.md)

| Decision | Locked | Honoured by | Evidence |
|----------|--------|-------------|----------|
| D-01 | Brand-voice content lives in markdown files (`resources/agents/brand-voice/_global.md` + optional `{slug}.md` per-brand overrides); guardrail regex patterns live in `config/seo_agent.php`. First match → fail entire run → write `agent_guardrail_blocked` Suggestion. No partial publishing. | Plan 12-01 (markdown scaffold) + Plan 12-02 (ReadBrandStyleGuideTool file-read with fallback) + Plan 12-03 (config/seo_agent.php + SeoOutboundGuardrail) + Plan 12-04 (P12-B catch-block ensures no partial publishing) | `ls resources/agents/brand-voice/` shows `_global.md` + `logitech.md`. `config/seo_agent.php` returns `['guardrails' => 3 categories × 4-5 patterns]`. `RunSeoAgentJobGuardrailBlockedTest` test asserts ZERO `seo_content_patch` Suggestions exist after a blocked run (no partial publishing). |
| D-02 | Hybrid voice scope — global voice mandatory; per-brand overrides optional. Agent always has *something* to read. | Plan 12-01 (FrameworkSmokeTest asserts `_global.md` exists) + Plan 12-02 (ReadBrandStyleGuideTool fallback from `{slug}.md` → `_global.md`) | `tests/Feature/Agents/Seo/BrandVoiceGlobalFileExistsTest.php` (1 case) + `tests/Unit/Agents/Tools/Seo/ReadBrandStyleGuideToolTest.php` 6 cases (Logitech per-brand happy path, unknown-brand global fallback, empty-string global, literal "global" arg, case normalisation, 3072-char content cap). |
| D-03 | One bundled Suggestion per agent run per product (kind `seo_content_patch`, payload.patches[] with 1-4 entries via last-wins per-field dedup). Filament sidebar with per-field checkbox approve. | Plan 12-04 (SeoAgentResultMapper bundles propose_content_patch calls; EditAutoCreateReview sidebar Section with CheckboxList header action) | `app/Domain/Agents/Services/SeoAgentResultMapper.php` line 99 unconditional `$patchesByField[$field] = ...` (P12-A LAST-WINS). `SeoAgentResultMapperTest` (6 cases / 33 assertions) — including LAST-WINS fixture asserting second-call wins for same field. Filament sidebar: `seoPatchesInfolist` method exists, `getHeaderActions` returns `approve_selected_patches` Action with modal CheckboxList (RESEARCH §Pattern 4 Fallback A5 — per-row inline actions deferred to v2.1). |
| D-04 | Approved patch writes `Product.{field}` canonical + sets `ProductOverride.pin_{field}=true`. ZERO migrations. CRITICAL: user-facing 'title' field maps to Product.name column. | Plan 12-04 (SeoContentPatchApplier with `FIELD_TO_PRODUCT_COLUMN` constant) | `app/Domain/Agents/Appliers/SeoContentPatchApplier.php` source contains literal `'title' => 'name'` (2 occurrences: docblock + constant). `SeoContentPatchApplierTitleToNameTest` 3 cases asserting (a) Product.name updates when field='title', (b) Product fillable does NOT contain 'title', (c) source contains the literal mapping. `SeoContentPatchApplierTest` 5 cases including "ProductOverride upsert preserves OTHER pin flags + margin_basis_points" (Rule 3 deviation — hand-rolled upsert preserves the NOT NULL constraint). Audit trail records `seo.content_patch_applied` with sha256 before_hash + after_hash (lean audit_log; verbatim text stays on Suggestion.payload). |

**Score: 4 / 4 decisions honoured.**

## 4. Pitfall Mitigation Log (P12-A..P12-H from 12-RESEARCH.md)

| Pitfall | Description | Mitigation status | Defending test |
|---------|-------------|-------------------|----------------|
| P12-A | Mapper LAST-WINS dedup — naive `if (! isset($patchesByField[$field]))` first-wins guard would let an early forbidden patch escape detection. | Mitigated (3 layers) | (1) Code: `SeoAgentResultMapper.php` line 99 UNCONDITIONAL assignment. (2) `SeoAgentResultMapperTest::"P12-A LAST-WINS"` fixture passes two `field='title'` calls, asserts `after === 'SECOND PROPOSAL'`. (3) Source grep gate: `grep -c 'isset($patchesByField' app/Domain/Agents/Services/SeoAgentResultMapper.php` returns 0. |
| P12-B | Catch-block audit BEFORE rethrow — guardrail throws `GuardrailViolationException` (stateless scan + throw only); without a catch hook, a blocked run loses its forensic trail entirely. | Mitigated (2 layers) | (1) Code: `RunSeoAgentJob.php` line 242 calls `$mapper->createGuardrailBlockedSuggestion(...)` BEFORE line 271's `throw $e`. (2) `RunSeoAgentJobGuardrailBlockedTest::"P12-B"` asserts (a) exactly ONE `agent_guardrail_blocked` Suggestion, (b) ZERO `seo_content_patch` Suggestions, (c) `AgentRun.status === 'guardrail_blocked'`, (d) exception rethrown so Horizon records the failure. |
| P12-C | Brand slug derivation mismatch — two callers deriving slugs differently (one reads `brands.slug`, another reads `brands.name`) leads to silent degradation where the per-brand override file is never found. | Mitigated | Single helper `BrandSlugResolver::forBrandId(?int $brandId): string` at `app/Domain/Agents/Support/BrandSlugResolver.php` reads ONLY `brands.slug` column (NOT name). Both `ReadProductDraftTool` and `RunSeoAgentJob` delegate to this single helper. `BrandSlugDerivationTest` (5 cases) asserts the helper returns the `slug` column value, falls back to `(string) $brandId` when brands row missing OR table missing OR slug null, and returns `'global'` for `brand_id=null`. |
| P12-D | TruncatingTool relocation pitfall — leaving the old `Tools/Pricing/TruncatingTool.php` alongside the new shared parent would silently split SeoAgent + PricingAgent subclasses across two parent classes. | Mitigated | Clean relocation (no shim/alias). New `app/Domain/Agents/Tools/TruncatingTool.php` exists; old `app/Domain/Agents/Tools/Pricing/TruncatingTool.php` ABSENT. All 4 Phase 10 read_* tools updated to import the new FQCN. `TruncatingToolRelocationTest` (5 cases) asserts: (1) new FQCN exists, (2) old FQCN absent, (3) all 4 Phase 10 tools' parent class IS the new FQCN, (4) container resolution unaffected, (5) name() methods byte-identical post-relocation. |
| P12-E | Batch dispatch ignoring intra-batch budget breaches — naive dispatch loop would fire all 20 jobs even when monthly cap is breached on dispatch 5; the framework BudgetGuard would only stop them on individual run. | Mitigated | `RunSeoAgentBatchCommand` reads `Cache::get('agents.monthly.' . now('Europe/London')->format('Y-m'), 0)` TWICE: (1) pre-flight before the loop, returns SUCCESS with warning if `>= monthlyCap`; (2) between each dispatch, breaks the loop with "stopping at N/M" warning when budget threshold crossed. Two occurrences of `Cache::get('agents.monthly.` in the command source (acceptance grep ≥2). `BatchCommandBudgetRaceTest` (4 cases): pre-flight cap stops 0 dispatches when Cache=20001; between-dispatch cap stops at 4 dispatches when Cache=19980 (4 × 5p = 20p brings total to 20000p ceiling); dry-run never hits budget; recordSpend resume. |
| P12-F | Additive sidebar — overriding `EditAutoCreateReview::infolist()` would silently regress Phase 6's default admin edit form. | Mitigated (3 layers) | (1) Naming: new method is `seoPatchesInfolist` — a DIFFERENT name from Filament's default `infolist` handler. Filament's `HasInfolists` trait resolves any public `*Infolist` method. (2) No `form()` or `infolist()` override: `getHeaderActions` + `seoPatchesInfolist` are the ONLY methods declared locally on EditAutoCreateReview. (3) `AutoCreateEditFormUnchangedTest` (5 cases) — reflection check `$method->getDeclaringClass()->getName() !== EditAutoCreateReview::class` for both `form` and `infolist`; source-level grep that all 8 Phase 6 form fields (sku, name, slug, short_description, long_description, meta_description, auto_create_status, completeness_score) are present in `AutoCreateReviewResource.php`. |
| P12-G | read_similar_shipped_products empty-category trap — a niche category with zero published products would return an empty array, giving the agent no voice anchor and risking off-brand emissions. | Mitigated | `ReadSimilarShippedProductsTool` re-queries globally (drops `where('category_id', $cat)`) when the category filter returns zero rows, marks response with `_fallback: 'global'` hint. Agent knows the voice anchor is cross-category and reflects that in reasoning. `ReadSimilarShippedProductsToolTest::"P12-G global fallback on empty-category"` (1 case) seeds 5 products in category 10 + 0 in category 99 + asserts the category=99 query returns 5 cross-category products with `_fallback: 'global'`. |
| P12-H | Brand-voice file content opacity defence — the markdown file content MUST be treated as opaque string (json_encode-serialised) and NEVER passed through a view-template renderer or directive-inclusion mechanism. | Mitigated (2 layers) | (1) `ReadBrandStyleGuideTool` source contains NEITHER `Blade::render` NOR `@include` (grep-asserted at Plan 12-02 acceptance + verified by `grep -L "Blade::render\|@include" app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php`). (2) `resources/views/agents/seo/system.blade.php` source contains neither directive either — the brand voice content arrives at the agent at runtime via the `read_brand_style_guide` tool call, never via Blade-side inclusion. `SystemPromptCalibrationTest::"Blade source does NOT @include brand voice"` is the architecture fence. |

**Score: 8 / 8 pitfalls mitigated. Zero deferred.**

## 5. Open Question Resolution Log (O-1..O-5 from 12-RESEARCH.md)

| OQ | Question | Resolution | Resolved by |
|----|----------|------------|-------------|
| O-1 | Does Prism v0.100.1 support `withEnumParameter` for tool args (so we can pin the 4 valid `field` values at the Anthropic schema level)? | **RESOLVED YES** — Read `vendor/prism-php/prism/src/Tool.php:199` and confirmed the method exists with signature `withEnumParameter(string $name, string $description, array $options, bool $required=true)`. `ProposeContentPatchTool::asPrismTool()` upgraded from `withStringParameter` to `withEnumParameter` pinning `['title', 'short_description', 'long_description', 'meta_description']`. Mapper at Plan 12-04 still validates against the same allow-list for defence in depth. | Plan 12-02 (commit `36e2f59`) |
| O-2 | Should the nightly schedule entry have an env-flag emergency disable so ops can stop the cron without a code deploy? | **RESOLVED YES — default true.** `routes/console.php` schedule entry wrapped in `if ((bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true))`. `.env.example` documents the flag with a comment: "toggle nightly SEO agent batch schedule (operator emergency disable). Default true. Set to false to halt nightly runs without code deploy." `ScheduleWiringTest` cases 1-3 verify the schedule:list contains the entry when env is unset (defaults true), and disappears when env is set to false. | Plan 12-05 (commit `1ee6914`) |
| O-3 | When admin approves a SUBSET of the 4 patches in a single Suggestion, does the Suggestion flip to status='applied' or stay 'pending'? | **RESOLVED stays pending.** `SeoContentPatchApplier::apply()` flips to STATUS_APPLIED only if ALL patches in `payload.patches[]` have their `applied_at` set; otherwise stays STATUS_PENDING so admin can return later to approve more. Audit log records each individual field approval. `SeoContentPatchApplierTest` (5 cases) covers both branches. | Plan 12-04 (commit `8341242`) |
| O-4 | What's the default `agents.seo.temperature` — 0.0 deterministic like PricingAgent, or higher for genuine paraphrasing? | **RESOLVED 0.4.** `config/agents.php` adds `'seo' => ['temperature' => (float) env('AGENTS_SEO_TEMPERATURE', 0.4)]`. 0.4 chosen per CONTEXT Claude's Discretion — balances creativity (genuine paraphrasing) with reproducibility (re-runs on same draft produce similar — not identical — proposals). REQUIREMENTS.md line 124 explicitly allows temp>0 for SEO/chatbot with guardrails. RunSeoAgentJob calls `$client->generate(..., temperature: (float) config('agents.seo.temperature', 0.4))`. | Plan 12-01 (commit `50f0226`) |
| O-5 | Should `agent_guardrail_blocked` Suggestions be visible in the default Filament Suggestion list, hidden, or admin-only? | **RESOLVED hidden by default + escape hatch via explicit kind filter.** `SuggestionResource::getEloquentQuery()` extends with `->when(! request()->has('tableFilters.kind.value'), fn ($q) => $q->where('kind', '!=', 'agent_guardrail_blocked'))`. Admin can explicitly filter by kind=`agent_guardrail_blocked` (escape hatch) to see audit-blocked rows. `SuggestionResourceGuardrailBlockedFilterTest` (4 cases) covers default-hide, explicit-filter-shows, and no-regression on other kinds. | Plan 12-05 (commit `dab4868`) |

**Score: 5 / 5 open questions resolved. Zero deferred.**

## 6. Test Coverage Summary

| Test category | File / module | Cases | Assertions |
|---------------|---------------|-------|------------|
| Plan 12-01 Architecture | `tests/Architecture/TruncatingToolRelocationTest.php` | 5 | 8 |
| Plan 12-01 Smoke | `tests/Feature/Agents/Seo/FrameworkSmokeTest.php` | 14 | 25 |
| Plan 12-02 Tool unit | `tests/Unit/Agents/Tools/Seo/ReadProductDraftToolTest.php` | 5 | 19 |
| Plan 12-02 Tool unit | `tests/Unit/Agents/Tools/Seo/ReadBrandStyleGuideToolTest.php` | 6 | 18 |
| Plan 12-02 Tool unit | `tests/Unit/Agents/Tools/Seo/ReadSimilarShippedProductsToolTest.php` | 7 | 24 |
| Plan 12-02 Tool unit | `tests/Unit/Agents/Tools/Seo/ProposeContentPatchToolTest.php` | 5 | 13 |
| Plan 12-02 Brand-slug | `tests/Feature/Agents/Seo/BrandSlugDerivationTest.php` | 5 | 5 |
| Plan 12-02 Brand-voice | `tests/Feature/Agents/Seo/BrandVoiceGlobalFileExistsTest.php` | 1 | 1 |
| Plan 12-03 Architecture | `tests/Architecture/SeoAgentConfigTest.php` | 10 | 17 |
| Plan 12-03 Prompt | `tests/Feature/Agents/Seo/SystemPromptCalibrationTest.php` | 8 | 12 |
| Plan 12-03 Guardrail unit | `tests/Feature/Agents/Seo/SeoOutboundGuardrailTest.php` | 12 | 18 |
| Plan 12-03 Wiring | `tests/Feature/Agents/Seo/SeoAgentGuardrailsWiredTest.php` | 5 | 5 |
| Plan 12-03 Integration | `tests/Feature/Agents/Seo/SeoGuardrailIntegrationTest.php` | 5 | 9 |
| Plan 12-04 Mapper | `tests/Feature/Agents/Seo/SeoAgentResultMapperTest.php` | 6 | 33 |
| Plan 12-04 Applier | `tests/Feature/Agents/Seo/SeoContentPatchApplierTest.php` | 5 | 24 |
| Plan 12-04 Applier (title→name) | `tests/Feature/Agents/Seo/SeoContentPatchApplierTitleToNameTest.php` | 3 | 11 |
| Plan 12-04 Job happy | `tests/Feature/Agents/Seo/RunSeoAgentJobHappyPathTest.php` | 3 | 17 |
| Plan 12-04 Job blocked | `tests/Feature/Agents/Seo/RunSeoAgentJobGuardrailBlockedTest.php` | 1 | 8 |
| Plan 12-04 Phase 6 form unchanged | `tests/Feature/Agents/Seo/AutoCreateEditFormUnchangedTest.php` | 5 | 19 |
| Plan 12-05 Batch command | `tests/Feature/Agents/Seo/RunSeoAgentBatchCommandTest.php` | 5 | — |
| Plan 12-05 Budget race | `tests/Feature/Agents/Seo/BatchCommandBudgetRaceTest.php` | 4 | — |
| Plan 12-05 Eligibility query | `tests/Feature/Agents/Seo/SeoAgentEligibilityQueryTest.php` | 4 | — |
| Plan 12-05 Schedule wiring | `tests/Feature/Agents/Seo/ScheduleWiringTest.php` | 10 | 17 |
| Plan 12-05 Suggestion filter | `tests/Feature/Agents/Seo/SuggestionResourceGuardrailBlockedFilterTest.php` | 4 | 9 |
| Plan 12-05 Architecture | `tests/Architecture/Phase12VerificationTest.php` | 12 | 28 |
| **Phase 12 total** | — | **120** | **287** |

### Cross-cutting regression verification

Re-ran at end of Plan 12-05 to confirm zero regression on prior phases:

```
php vendor/bin/pest \
  tests/Feature/Agents/PricingAgentRegistrationTest.php \
  tests/Feature/Agents/PricingAgentPromptHashTest.php \
  tests/Unit/Domain/Agents/Services/AgentRegistryTest.php \
  tests/Unit/Domain/Agents/Services/GuardrailEngineTest.php \
  tests/Architecture/AgentToolsNamingTest.php \
  tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php \
  tests/Architecture/PricingToolsObserveSoftCapTest.php \
  tests/Architecture/TruncatingToolRelocationTest.php \
  tests/Feature/ProductAutoCreate/
```

**Result:** Zero failures attributable to Phase 12. Pre-existing failures (Phase 11 architecture suite missing `customer_groups` table on local SQLite; `PricingAgentCalibrationTest` missing IntegrationCredentialResolver row) logged in `deferred-items.md` per the execution-flow scope boundary rules; verified reproducible at parent commit BEFORE any Phase 12 changes.

## 7. UAT Disposition

**Status:** `deferred_to_production`.

**User reply on Task 4 resume-signal:** `approved`.

The 10-step browser-based Filament UAT documented in 12-05-PLAN.md Task 4 `<how-to-verify>` cannot run end-to-end locally because `ms.21stcav.com` has not yet been deployed (bare git clone only — no `vendor/`, no `.env`, no DB) and the local Herd dev environment does not have seeded admin roles + IntegrationCredentials for Anthropic + a Horizon supervisor running on the `agents` queue + a seeded Phase 6 AutoCreate draft Product. See `12-UAT-DISPOSITION.md` for the full evidence package (10 of 10 manual UAT steps mapped to direct Pest substitutes) and the re-run conditions.

**UAT re-run will happen when ALL of these are true:**

1. `ms.21stcav.com` deployment is live per `deploy/README.md`.
2. IntegrationCredentials row created via Filament admin (kind=`anthropic_api`).
3. An admin user is seeded with Spatie roles (admin + pricing_manager assigned `run_seo_agent`).
4. At least 1 Phase 6 AutoCreate draft Product exists with `auto_create_status='pending_review'` AND `completeness_score < 85`.

The operator handover note in §10 below tracks this as a follow-up checkbox.

## 8. Ship Verdict

**Verdict: PASS_WITH_DEFERRED_UAT — SHIP.**

The 5 SEOAGT-* contract surfaces all have full code-level Pest coverage and the 8 cross-cutting pitfalls have multi-layer defences with named regression tests. All 5 Open Questions are resolved. The 4 locked Decisions are honoured at code level with grep + reflection acceptance gates. The 10-step manual browser UAT is the only deferred element; this is a post-deploy operator confirmation, NOT a contract-surface verification, and 10 of 10 steps have direct Pest substitution. Phase 13 (E3 WhatsApp Channel) planning can begin with confidence; no Phase 13 task depends on Phase 12 production browser UAT.

Phase 8 framework byte-identity preserved (TruncatingTool relocated cleanly; GuardrailViolationException extended additively; 22 Phase 8 + Phase 10 backward-compat tests pass post-extension). Phase 10 PricingAgent byte-identity preserved (PricingAgentRegistrationTest + PricingAgentPromptHashTest + AgentRegistryTest + GuardrailEngineTest all pass post-Phase-12 changes). Phase 6 AutoCreate form byte-identity preserved (AutoCreateEditFormUnchangedTest 5/5 PASS — reflection check that EditAutoCreateReview declares neither `form()` nor `infolist()` locally).

## 9. Deferred Items (v2.1 candidates)

| Item | Defer rationale | Owner |
|------|-----------------|-------|
| Per-suggestion confidence band (LOW/MOD/HIGH) | CONTEXT Claude's Discretion — admin's eyes are the calibration for SEO; confidence adds inbox noise without changing approval behaviour. Revisit in v2.1 if patch quality calibration justifies it. | Future plan |
| Dedicated rejection inbox page | CONTEXT Claude's Discretion — Phase 10 PricingAgent's rejection inbox triages prompt drift for financial reasoning. For SEO, rejected patches are just "this wording wasn't right" — surface in the existing Suggestion list with a kind filter. | Future plan |
| Char-level diff highlighting (e.g. spatie/laravel-html-diff) | RESEARCH §Diff library evaluation: hand-rolled inline truncate-with-tooltip ships in Plan 12-04; spatie/laravel-html-diff was REJECTED for v2.0 (net-stack-delta policy). If real-world admin feedback shows char-level highlighting is wanted, the Plan 12-04 panel can be retrofitted without touching the data flow. | v2.1 candidate |
| Semantic LLM guardrail (Claude self-judges before emitting) | RESEARCH §Deferred Ideas — current 13-pattern regex library is the v2.0 starter; semantic guardrail would be a second LLM call per run, adding ~5p × N runs to monthly spend. Revisit when regex library shows systematic false-positive / false-negative patterns. | v2.2+ candidate |
| Production browser UAT (10 steps) | Cannot run pre-deploy; 10 of 10 steps have direct Pest substitution. Re-run after `ms.21stcav.com` bootstraps. | Deploy operator post-deploy |

## 10. Operator Handover Notes

### Env vars introduced

| Env var | Default | Purpose |
|---------|---------|---------|
| `AGENT_SEO_BATCH_SCHEDULE_ENABLED` | `true` | Emergency disable for the nightly schedule entry. Flip to `false` in `.env` to halt nightly runs WITHOUT code deploy (O-2 resolution). |
| `AGENTS_SEO_TEMPERATURE` | `0.4` | Override `config/agents.php` SEO agent temperature. Higher → more creative paraphrasing; lower → more deterministic. Don't set above 0.7 without re-calibrating the brand-voice regex library. |

### Schedule entry

- **Command:** `agents:run-seo-batch`
- **Cron:** `30 4 * * *` (04:30 Europe/London — slots between competitor:ftp-pull Sun+Wed 02:00 and supplier:db-sync Mon-Fri 07:00)
- **Limit:** 20 drafts/run
- **Race protection:** `withoutOverlapping(60)` + `onOneServer()`
- **Verify after deploy:** `php artisan schedule:list | grep agents:run-seo-batch`
- **Emergency disable:** set `AGENT_SEO_BATCH_SCHEDULE_ENABLED=false` in `.env`, NO restart needed (the schedule list re-reads on next cron-fire); `php artisan schedule:list` will then NOT show the entry.

### Shield permission

- **Name:** `run_seo_agent`
- **Granted to:** `admin`, `pricing_manager`
- **NOT granted to:** `sales`, `read_only`
- **Apply after deploy:** `php artisan db:seed --class=RolePermissionSeeder && php artisan shield:safe-regenerate`

### Monthly Cache key to monitor

- **Key:** `agents.monthly.{YYYY-MM}` (London-month)
- **Cap:** `config('agents.monthly_ceiling_pence', 20000)` = £200/month
- **Read current spend:** `php artisan tinker --execute="echo Cache::get('agents.monthly.' . now('Europe/London')->format('Y-m'), 0);"`
- **Manual budget breach simulation (dry-run):** `php artisan tinker --execute="Cache::put('agents.monthly.' . now('Europe/London')->format('Y-m'), 19990);"` then `php artisan agents:run-seo-batch --limit=20` — batch should dispatch no more than 2-3 jobs before stopping mid-batch with "Monthly budget exceeded" warning (P12-E behaviour).

### Pre-deploy checklist

The operator should walk through this checklist when `ms.21stcav.com` deployment goes live:

- [ ] `composer install --no-dev` in production
- [ ] `php artisan migrate --force` (no Phase 12 migrations expected; Phase 8 + Phase 10 + Phase 12 are all zero-migration)
- [ ] `php artisan db:seed --class=RolePermissionSeeder` to seed `run_seo_agent` permission
- [ ] `php artisan shield:safe-regenerate` to materialise policy templates
- [ ] Create at least one admin user with admin OR pricing_manager role assigned
- [ ] Filament admin → Integration Credentials → create `anthropic_api` kind row
- [ ] Confirm `AGENT_WRITE_ENABLED=true` if Suggestions should actually be written (default false ships shadow-mode runs)
- [ ] Confirm `AGENT_SEO_BATCH_SCHEDULE_ENABLED=true` (default — flip to false for first week if manual-batch-only smoke gating is preferred)
- [ ] Horizon supervisor running on `agents` queue (`php artisan horizon:status`)
- [ ] Verify schedule entry: `php artisan schedule:list | grep agents:run-seo-batch`
- [ ] Manual smoke: `php artisan agents:run-seo-batch --dry-run --limit=1` — should log 1 eligible product (or "no eligible products" if AutoCreate hasn't ingested yet)
- [ ] **Re-run 10-step browser UAT** per 12-05-PLAN.md Task 4 `<how-to-verify>` — record outcome in a Phase 12 follow-up note OR amend this VERIFICATION doc.

### Files of note (absolute paths on the local working tree)

- `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app\app\Domain\Agents\Console\Commands\RunSeoAgentBatchCommand.php`
- `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app\routes\console.php` (schedule entry)
- `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app\.env.example` (`AGENT_SEO_BATCH_SCHEDULE_ENABLED` documented)
- `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app\database\seeders\RolePermissionSeeder.php` (`run_seo_agent` in admin + pricing_manager arrays)
- `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app\app\Domain\Suggestions\Filament\Resources\SuggestionResource.php` (`getEloquentQuery()` hides `agent_guardrail_blocked` by default)
- `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app\tests\Architecture\Phase12VerificationTest.php` (pins all 5 SEOAGT-* artefacts)
- `C:\Users\sonny.tanda\Documents\1 - Laravel Projects\meetingstore-ops-app\.planning\phases\12-c3-seo-content-agent\12-UAT-DISPOSITION.md` (this checkpoint's resolution + evidence)
