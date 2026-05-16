---
phase: 12-c3-seo-content-agent
plan: 01
subsystem: agents
tags: [agents, seo-agent, tool-stubs, agent-registry, truncating-tool-relocation, brand-voice-markdown, framework-smoke, seoagt-01, seoagt-02]

requires:
  - phase: 08-c4-agent-framework
    plan: 01
    provides: AgentRun model, TrustTier enum, AgentRunPolicy, config/agents.php (daily_caps.seo=300 already provisioned by Phase 8 D-05)
  - phase: 08-c4-agent-framework
    plan: 03
    provides: RunsAsAgent + Guardrail contracts, AgentRegistry, ToolBus, GuardrailEngine, abstract Tool base, PromptRenderer
  - phase: 10-c1-pricing-agent
    plan: 01
    provides: PricingAgent skeleton (mirror precedent), AppServiceProvider afterResolving block to extend, FrameworkSmokeTest fixture pattern
  - phase: 10-c1-pricing-agent
    plan: 02
    provides: TruncatingTool 3-KB cap helper (relocated by this plan to shared Tools/ parent), per-tool reduceLargestArray contract, ProposeMarginBandTool no-op writer pattern (mirrored verbatim by ProposeContentPatchTool)
provides:
  - SeoAgent skeleton (kind='seo', TrustTier::Trusted, 4-tool list, guardrails()=[] until Plan 12-03, LogicException stub on execute() per RESEARCH §Pattern 1)
  - 4 SeoAgent tool stubs at app/Domain/Agents/Tools/Seo/ — read_product_draft, read_brand_style_guide, read_similar_shipped_products (extends shared TruncatingTool), propose_content_patch (no-op writer mirroring Phase 10 D-06)
  - Shared TruncatingTool at app/Domain/Agents/Tools/TruncatingTool.php (relocated from Tools/Pricing/; ALL 4 Phase 10 read_* tools updated; 0 Phase 10 regression)
  - AgentRegistry binding for kind='seo' → SeoAgent in AppServiceProvider::boot()
  - agents.seo.temperature config slot (default 0.4, env: AGENTS_SEO_TEMPERATURE)
  - resources/agents/brand-voice/_global.md (mandatory MeetingStore tone-of-voice doc — Tone/Words to use/Words to avoid/Structural conventions/Forbidden)
  - resources/agents/brand-voice/logitech.md (per-brand example showing the on-disk override pattern)
  - tests/Architecture/TruncatingToolRelocationTest.php (5 cases — relocation invariant: new FQCN exists, old absent, all 4 Phase 10 tools' parent class set correctly, container resolution unaffected, name() unchanged)
  - tests/Feature/Agents/Seo/FrameworkSmokeTest.php (14 cases — pins SeoAgent contract surface + AgentRegistry/config wiring + Task 1 cross-check on Seo namespace)
affects: [12-02-tool-implementations, 12-03-prompt-blade-guardrail-config, 12-04-run-job-mapper-filament-sidebar, 12-05-batch-command-shield-verification]

tech-stack:
  added: []  # zero composer changes — Phase 8 + Phase 10 already shipped Prism + TruncatingTool primitives
  patterns:
    - "Shared TruncatingTool — relocated from per-agent namespace (Tools/Pricing/) to shared parent (Tools/) so Phase 12 SeoAgent + Phase 14 ChatbotAgent can extend the 3-KB cap helper without copy/paste. RESEARCH P12-D mitigation."
    - "Compile-time tool stubs returning {\"stub\":true} — Plan 12-02 will swap the using() callable bodies for real impl (file_get_contents for brand-voice, Product::query for draft/similar). Same stub-then-replace cadence as Phase 10 Plan 01→02."
    - "ProposeContentPatchTool no-op writer — body returns {\"acknowledged\":true} verbatim; Plan 12-04 SeoAgentResultMapper extracts the 1-4 propose_content_patch calls from agent_run.tool_calls[] post-loop, deduplicates by `field` (last-wins per-field), and bundles into ONE Suggestion of kind 'seo_content_patch'. Variable cardinality is the key divergence from Phase 10's single-propose pattern."
    - "Brand-voice as markdown-on-disk — per-brand overrides at resources/agents/brand-voice/{slug}.md, mandatory global fallback at _global.md. Files are git-tracked, edited via PR. Plan 12-02 ReadBrandStyleGuideTool MUST use file_get_contents + json_encode only — NEVER Blade::render (T-12-01-02 mitigation)."
    - "Forward-compat execute() — SeoAgent::execute() throws LogicException so any future caller invoking it directly trips immediately. Plan 12-04 RunSeoAgentJob owns orchestration via the framework's RunAgentJob-style sibling-job pattern (RESEARCH §Pattern 1)."

key-files:
  created:
    - app/Domain/Agents/Agents/SeoAgent.php
    - app/Domain/Agents/Tools/Seo/ReadProductDraftTool.php
    - app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php
    - app/Domain/Agents/Tools/Seo/ReadSimilarShippedProductsTool.php
    - app/Domain/Agents/Tools/Seo/ProposeContentPatchTool.php
    - app/Domain/Agents/Tools/TruncatingTool.php (relocated from Tools/Pricing/)
    - resources/agents/brand-voice/_global.md
    - resources/agents/brand-voice/logitech.md
    - tests/Architecture/TruncatingToolRelocationTest.php
    - tests/Feature/Agents/Seo/FrameworkSmokeTest.php
  modified:
    - app/Domain/Agents/Tools/Pricing/ReadMarginHistoryTool.php (use stmt: Tools\Pricing\TruncatingTool → Tools\TruncatingTool)
    - app/Domain/Agents/Tools/Pricing/ReadCompetitorPricesTool.php (use stmt updated)
    - app/Domain/Agents/Tools/Pricing/ReadSupplierPriceTrendTool.php (use stmt updated)
    - app/Domain/Agents/Tools/Pricing/ReadSalesVolume90dTool.php (use stmt updated)
    - app/Providers/AppServiceProvider.php (afterResolving AgentRegistry block: appended `$registry->register('seo', SeoAgent::class)` after the existing pricing line)
    - config/agents.php (appended top-level `'seo' => ['temperature' => 0.4]` section, env: AGENTS_SEO_TEMPERATURE)
    - tests/Architecture/PricingToolsObserveSoftCapTest.php (use stmt updated — Rule 3 blocking-issue fix; would break compile after relocation)
    - tests/Unit/Domain/Agents/Tools/Pricing/ProposeMarginBandToolTest.php (use stmt updated — Rule 3 blocking-issue fix)
  deleted:
    - app/Domain/Agents/Tools/Pricing/TruncatingTool.php (relocated to shared parent)

key-decisions:
  - "TruncatingTool relocated (NOT shimmed) — single class at the shared App\\Domain\\Agents\\Tools\\TruncatingTool FQCN. No alias/shim left at the old Tools\\Pricing\\TruncatingTool FQCN. Avoids subclasses being silently split across two parent classes. Architecture test asserts the old FQCN absent."
  - "SeoAgent::guardrails() returns [] in Plan 12-01 — Plan 12-03 fills with [SensitiveFieldsStripGuardrail, OutboundRegexFilterGuardrail, SeoOutboundGuardrail]. Empty array bypasses cleanly through GuardrailEngine in the meantime. Cleaner than shipping a half-wired chain before SeoOutboundGuardrail exists."
  - "ReadProductDraftTool extends plain Tool (NOT TruncatingTool) — single Product row payload stays small after Plan 12-02 applies per-field mb_substr(4096) caps. RESEARCH §Tool 1: total worst case ~16 KB → ~12 KB after capping = no cap helper needed."
  - "ReadSimilarShippedProductsTool extends TruncatingTool — 5 products' worth of full text fields can exceed 3 KB. Plan 12-02 implements the real reduceLargestArray (halve per-product long_description_first_500_chars on cap pressure)."
  - "ProposeContentPatchTool uses withStringParameter for 'field' — Prism v0.100.1's documented surface. Open Question O-1 deferred (see Open Questions section); enum-typed tool params arrive in Prism v0.110+ which we don't ship in v2.0."
  - "Test file deliberately split across Task 2 + Task 3 commits — Task 2 ships 9 contract+file cases (commit 7d98f64); Task 3 appends 5 registry+config cases (commit 50f0226). Both commits PHP-l clean + Pest-green. Atomic-per-task commit cadence honored without temporarily-broken intermediate state."

metrics:
  duration_minutes: 23
  tasks_completed: 3
  files_created: 10
  files_modified: 8
  files_deleted: 1
  tests_added: 19  # 5 Architecture + 14 Seo Feature
  test_assertions_added: 33
  composer_changes: 0
  migrations: 0
completed-date: 2026-05-16

commits:
  - hash: fe52e59
    message: "refactor(12-01): relocate TruncatingTool to shared Tools/ namespace"
  - hash: 7d98f64
    message: "feat(12-01): SeoAgent skeleton + 4 tool stubs + brand-voice scaffold"
  - hash: 50f0226
    message: "feat(12-01): register seo kind in AgentRegistry + add agents.seo.temperature"
---

# Phase 12 Plan 01: SeoAgent Skeleton + Tool Stubs + Brand-Voice Scaffold + TruncatingTool Relocation Summary

Standing up the compile-time contract surface for the second REAL Phase 8 framework consumer (SeoAgent, after Phase 10 PricingAgent) — kind='seo', 4 tool stubs, mandatory brand-voice markdown, AgentRegistry wiring, agents.seo.temperature=0.4 config slot, and TruncatingTool relocated from Tools/Pricing/ to the shared Tools/ parent so Phase 12 (and later Phase 14) can extend it without copy/paste.

## What Shipped

**Tier 1 — SeoAgent framework surface (SEOAGT-01 / SEOAGT-02 contract pin):**

- `app/Domain/Agents/Agents/SeoAgent.php` — `final class SeoAgent implements RunsAsAgent` with the 6-method contract surface: `kind() === 'seo'`, `trustTier() === TrustTier::Trusted`, `tools()` returns 4 instances via `app()`, `systemPrompt()` delegates to PromptRenderer (Plan 12-03 ships the Blade view), `guardrails()` returns `[]` (Plan 12-03 fills with the 3 guardrails), `execute()` throws LogicException because Plan 12-04 RunSeoAgentJob owns orchestration.
- 4 tool stubs at `app/Domain/Agents/Tools/Seo/`:
  - `ReadProductDraftTool` (extends `Tool`) — `read_product_draft`, single `sku` string param, stub returns `{"stub":true}`. Plan 12-02 swaps body for real `Product::query()->where('sku', ...)` + per-field 4096-char caps.
  - `ReadBrandStyleGuideTool` (extends `Tool`) — `read_brand_style_guide`, single `brand` string param, stub returns `{"stub":true}`. Plan 12-02 swaps body for the per-brand-file-with-global-fallback file-read logic. Docblock T-12-01-02 warning: `file_get_contents` + `json_encode` ONLY — never `Blade::render`.
  - `ReadSimilarShippedProductsTool` (extends shared `TruncatingTool`) — `read_similar_shipped_products`, `category` + `limit` number params, stub returns `{"stub":true}` with empty reduceLargestArray. Plan 12-02 swaps body for Option B query (`status='publish' AND (completeness_score>=85 OR NULL)`) + per-product trim reducer.
  - `ProposeContentPatchTool` (extends `Tool`) — `propose_content_patch`, 5 string params (`sku`, `field`, `before`, `after`, `reasoning`), body returns `{"acknowledged":true}` VERBATIM. Plan 12-04 SeoAgentResultMapper extracts the 1-4 calls per run from `agent_run.tool_calls[]` and bundles into one Suggestion of kind `seo_content_patch`.

**Tier 2 — TruncatingTool relocation (RESEARCH §P12-D mitigation):**

- New: `app/Domain/Agents/Tools/TruncatingTool.php` with `namespace App\Domain\Agents\Tools;`. Body is byte-identical to the old `Tools/Pricing/TruncatingTool.php` (`capJson()` + abstract `reduceLargestArray()` + iterative cap-down loop with 5-iteration ceiling).
- Deleted: `app/Domain/Agents/Tools/Pricing/TruncatingTool.php`. No shim/alias — clean relocation.
- All 4 Phase 10 read_* Pricing tools updated to import from the new namespace:
  - `ReadMarginHistoryTool` use stmt → `App\Domain\Agents\Tools\TruncatingTool`
  - `ReadCompetitorPricesTool` use stmt → updated
  - `ReadSupplierPriceTrendTool` use stmt → updated
  - `ReadSalesVolume90dTool` use stmt → updated
- `ProposeMarginBandTool` unchanged — it extends the plain `Tool` base class (no TruncatingTool reference).

**Tier 3 — AgentRegistry + agents.seo.temperature wiring:**

- `app/Providers/AppServiceProvider.php` — appended `$registry->register('seo', \App\Domain\Agents\Agents\SeoAgent::class)` immediately after the existing PricingAgent line inside the `afterResolving(AgentRegistry::class, ...)` block. Phase 14 ChatbotAgent will drop in one line below.
- `config/agents.php` — appended top-level `'seo' => ['temperature' => (float) env('AGENTS_SEO_TEMPERATURE', 0.4)]` section. 0.4 chosen per CONTEXT Claude's Discretion + RESEARCH §Standard Stack. `php artisan tinker echo config('agents.seo.temperature')` outputs `0.4`.
- `agents.daily_caps.seo` already exists at 300p (Phase 8 D-05 default fail-safe) — this plan only adds the temperature key.

**Tier 4 — Brand-voice markdown scaffold (CONTEXT D-01 + D-02):**

- `resources/agents/brand-voice/_global.md` — mandatory MeetingStore tone-of-voice doc (~2 KB). 5 sections: Tone & voice / Words to use / Words to avoid / Structural conventions / Forbidden. First draft based on existing MeetingStore frontend product copy; Plan 12-03 may refine after calibration.
- `resources/agents/brand-voice/logitech.md` — per-brand override example. Logitech-specific terminology (RightSense / Logi Sync / Logi Tune / CollabOS), model families (MeetUp / Rally Bar / Sight / Tap), voice quirks (strip "magical" language, always-list-all-platforms).

**Tier 5 — Test coverage (no MySQL required):**

- `tests/Architecture/TruncatingToolRelocationTest.php` — 5 cases / 8 assertions: new FQCN exists, old FQCN absent (no shim), all 4 Phase 10 tools' parent class IS the new FQCN, container resolution unaffected, name() methods byte-identical post-relocation.
- `tests/Feature/Agents/Seo/FrameworkSmokeTest.php` — 14 cases / 25 assertions covering 6 Task 2 behaviours + 5 Task 3 behaviours + 3 cross-checks (tool container resolution, ProposeContentPatchTool acknowledged payload, ReadSimilarShipped extends shared TruncatingTool).

## Deviations from Plan

### Rule 3 (Auto-fixed blocking issue) — Stale TruncatingTool FQCN in test files

- **Found during:** Task 1, immediately after deleting `app/Domain/Agents/Tools/Pricing/TruncatingTool.php`.
- **Issue:** Two test files still imported `App\Domain\Agents\Tools\Pricing\TruncatingTool`:
  - `tests/Architecture/PricingToolsObserveSoftCapTest.php` (`use App\Domain\Agents\Tools\Pricing\TruncatingTool;`)
  - `tests/Unit/Domain/Agents/Tools/Pricing/ProposeMarginBandToolTest.php` (`use App\Domain\Agents\Tools\Pricing\TruncatingTool;`)
- **Fix:** Updated both `use` statements to the new shared FQCN `App\Domain\Agents\Tools\TruncatingTool`. Tests still assert the same architectural invariants (the cap-exemption on ProposeMarginBandTool + the every-read_*-tool-extends-cap invariant), they just point at the relocated parent class.
- **Files modified:** `tests/Architecture/PricingToolsObserveSoftCapTest.php`, `tests/Unit/Domain/Agents/Tools/Pricing/ProposeMarginBandToolTest.php`
- **Commit:** `fe52e59` (rolled into Task 1's relocation commit since they're the same atomic change — the old class doesn't exist therefore the use stmts MUST update).

No other deviations — plan executed as written.

## Authentication Gates

None encountered. No external API calls, no live MySQL writes, no third-party services exercised. Container resolution + file-read + config lookup tests only.

## Open Questions (carried forward to Plan 12-02)

**O-1 from RESEARCH §Open Questions: Does Prism v0.100.1 support `withEnumParameter`?**

- **Status:** Deferred to Plan 12-02.
- **Action taken in this plan:** Plan 12-01 ships `ProposeContentPatchTool` with `withStringParameter` for the `field` arg, matching Phase 10's exclusive use of string parameters. Defence-in-depth: field validation against the 4 allowed values (`title`, `short_description`, `long_description`, `meta_description`) lives in the Plan 12-04 SeoAgentResultMapper (last-wins per-field dedup also handles invalid field names by skipping silently).
- **Investigation needed in Plan 12-02:** Read `vendor/prism-php/prism/src/Tool.php` to verify whether v0.100.1 supports `->withEnumParameter('field', [...])` syntax. If yes, prefer enum-typed param for tighter Anthropic schema. If no, defer to v2.1 alongside the Prism upgrade.

## Pinned Tool Names (for Plan 12-02 — fill bodies without re-deriving)

The 4 SeoAgent tool names are LOCKED at the framework-architecture level (AgentToolsNamingTest enforces the `propose_/read_/search_` prefix; SEOAGT-02 pins the literal names):

1. `read_product_draft` — Plan 12-02 swaps body for `Product::findOrFail(sku)` + per-field 4096-char cap
2. `read_brand_style_guide` — Plan 12-02 swaps body for per-brand-file-with-global-fallback file-read (see RESEARCH §Tool 2 verbatim implementation)
3. `read_similar_shipped_products` — Plan 12-02 swaps body for Option B `status='publish' AND (completeness_score>=85 OR NULL)` query + real reduceLargestArray
4. `propose_content_patch` — Plan 12-04 SeoAgentResultMapper extracts; tool body itself does NOT change (no-op writer pattern verbatim)

## Decisions Deferred to Future Plans

| Decision | Owner | Defer rationale |
|----------|-------|-----------------|
| Real `read_product_draft` body (Product::query + cap) | Plan 12-02 | Stub-then-replace cadence — Plan 12-01 pins contract surface; 12-02 ships the DB query |
| Real `read_brand_style_guide` body (file-read with fallback) | Plan 12-02 | Same — stub now, real impl next |
| Real `read_similar_shipped_products` body (Option B query + reducer) | Plan 12-02 | Same |
| System prompt Blade view (`resources/views/agents/seo/system.blade.php`) | Plan 12-03 | PromptRenderer fires RuntimeException on missing view — intentional; only invoked by Plan 12-04 RunSeoAgentJob, not by Plan 12-01 smoke tests |
| `SeoOutboundGuardrail` + `config/seo_agent.php` regex pattern library | Plan 12-03 | SEOAGT-04 contract surface; Plan 12-03 ships the 3-category starter regex set + the post-flight guardrail |
| `SeoAgent::guardrails()` populated with `[SensitiveFieldsStrip, OutboundRegex, SeoOutbound]` | Plan 12-03 | Returns `[]` here so the framework doesn't half-wire a missing-guardrail chain |
| `RunSeoAgentJob` + `SeoAgentResultMapper` + `SeoContentPatchApplier` | Plan 12-04 | The bundled-Suggestion mapper writes one Suggestion of kind `seo_content_patch` per run with `payload.patches[]` containing 1-4 entries |
| `AutoCreateReviewResource` sidebar Section (Filament 3 Infolist) | Plan 12-04 | Per-field diff render + per-field approve action |
| `RunSeoAgentBatchCommand` (nightly 04:30 London scheduled) + Shield `run_seo_agent` permission + `shield:safe-regenerate` | Plan 12-05 | Closes Phase 12; verification doc ships there |

## Threat Flags

None new. The plan's `<threat_model>` register (T-12-01-01 through T-12-01-04) was fully honored by this plan's mitigations:

- **T-12-01-01** (TruncatingTool relocation breaks Phase 10 tools) — `TruncatingToolRelocationTest` ships with 5 assertions; Phase 10's full read_* tool suite re-runs in `bzsvuvnif` and remains green (33 tests pass).
- **T-12-01-02** (Brand-voice markdown XSS via downstream Blade rendering) — `ReadBrandStyleGuideTool` docblock warns Plan 12-02 against `Blade::render($content)`; this stub already uses `json_encode` only.
- **T-12-01-03** (SeoAgent not auditable) — Accepted per the register; AgentRegistry registration is git-tracked (commit `50f0226`).
- **T-12-01-04** (Missing `_global.md` causes silent agent voice degradation) — `_global.md` shipped (commit `7d98f64`); FrameworkSmokeTest asserts the file exists.

## Verification

```
php vendor/bin/pest tests/Unit/Domain/Agents/Tools/Pricing/ \
                    tests/Feature/Agents/PricingAgentRegistrationTest.php \
                    tests/Feature/Agents/Seo/ \
                    tests/Architecture/TruncatingToolRelocationTest.php \
                    tests/Architecture/PricingToolsObserveSoftCapTest.php \
                    tests/Architecture/AgentToolsNamingTest.php \
                    tests/Feature/Agents/FrameworkSmokeTest.php
```

**Result:** 56 passed (164 assertions) in 26.12s. Phase 10 zero regression; Phase 8 framework smoke green; Phase 12-01 14 new cases all green; architecture invariants all green.

## Self-Check: PASSED

- File `app/Domain/Agents/Agents/SeoAgent.php` — FOUND
- File `app/Domain/Agents/Tools/Seo/ReadProductDraftTool.php` — FOUND
- File `app/Domain/Agents/Tools/Seo/ReadBrandStyleGuideTool.php` — FOUND
- File `app/Domain/Agents/Tools/Seo/ReadSimilarShippedProductsTool.php` — FOUND
- File `app/Domain/Agents/Tools/Seo/ProposeContentPatchTool.php` — FOUND
- File `app/Domain/Agents/Tools/TruncatingTool.php` — FOUND
- File `app/Domain/Agents/Tools/Pricing/TruncatingTool.php` — ABSENT (correctly deleted)
- File `resources/agents/brand-voice/_global.md` — FOUND
- File `resources/agents/brand-voice/logitech.md` — FOUND
- File `tests/Architecture/TruncatingToolRelocationTest.php` — FOUND
- File `tests/Feature/Agents/Seo/FrameworkSmokeTest.php` — FOUND
- Commit `fe52e59` — FOUND (Task 1)
- Commit `7d98f64` — FOUND (Task 2)
- Commit `50f0226` — FOUND (Task 3)
- Pest suite — 56 passed, 0 failed
- PHP -l on every modified .php file — no syntax errors
