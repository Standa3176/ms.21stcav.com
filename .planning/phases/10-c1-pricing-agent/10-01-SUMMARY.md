---
phase: 10-c1-pricing-agent
plan: 01
subsystem: agents
tags: [agents, pricing-agent, tool-stubs, agent-registry, echo-deletion, framework-smoke, prcagt-01, prcagt-02, prcagt-05]

requires:
  - phase: 08-c4-agent-framework
    plan: 01
    provides: AgentRun model, TrustTier enum, AgentRunPolicy, config/agents.php (daily_caps.pricing=500), AgentToolsNamingTest, AgentsWriteOnlyViaSuggestionsTest, DeptracAgentsLayerTest, PolicyTemplateIntegrityTest
  - phase: 08-c4-agent-framework
    plan: 03
    provides: RunsAsAgent + Guardrail contracts, AgentRegistry, ToolBus, GuardrailEngine, abstract Tool base, SensitiveFieldsStripGuardrail, OutboundRegexFilterGuardrail, PromptInjectionXmlFenceGuardrail, PromptRenderer
  - phase: 08-c4-agent-framework
    plan: 04
    provides: RunAgentJob orchestrator, AgentRegistry registration pattern (afterResolving), AppServiceProvider registration block to replace
provides:
  - PricingAgent skeleton (kind='pricing', TrustTier::Trusted, 5-tool list, 2 guardrails, LogicException stub on execute() per RESEARCH §Pattern 2)
  - 5 PricingAgent tool stubs at app/Domain/Agents/Tools/Pricing/ (PRCAGT-02 contract surface — Plan 10-02 swaps stub bodies for real impl)
  - AgentRegistry binding for kind='pricing' → PricingAgent in AppServiceProvider::boot()
  - tests/Feature/Agents/FrameworkSmokeTest.php (inline fixture stub agent — replaces deleted EchoAgentRunTest framework-integrity contract)
  - tests/Unit/Domain/Agents/Tools/Pricing/PricingToolStubsContractTest.php (5 tests, 40 assertions — pins tool contract surface)
  - tests/Unit/Domain/Agents/Agents/PricingAgentContractTest.php (6 tests, 7 assertions — pins agent contract surface, runs without MySQL)
  - tests/Feature/Agents/PricingAgentRegistrationTest.php (Feature mirror of contract test — full-stack with RefreshDatabase)
  - resources/views/agents/pricing/.gitkeep (placeholder for Plan 10-03's system.blade.php)
affects: [10-02-tool-implementations, 10-03-system-prompt-blade, 10-04-run-job-mapper-filament, 10-05-rejection-feedback-shield-perm]

tech-stack:
  added: []  # zero composer changes — Phase 8 already shipped Prism + framework primitives
  patterns:
    - "RunsAsAgent contract reuse — PricingAgent implements the same 6-method surface as the deleted EchoAgent (kind/trustTier static + tools/systemPrompt/guardrails/execute instance) — execute() throws LogicException per RESEARCH §Pattern 2 forward-compat seam"
    - "Tool stub-then-replace cadence — Plan 10-01 ships compile-time stubs returning {_stub:true} placeholder; Plan 10-02 swaps using() callable bodies for real DB queries without re-touching the agent class or registration"
    - "Inline fixture stub agent in test file — FrameworkSmokeTest declares StubFrameworkAgent inside the test (Pest single-file convention, mirrors AlwaysRejectGuardrail precedent in deleted EchoAgentRunTest test 9) so the framework-integrity contract survives EchoAgent deletion without an in-repo demo agent"
    - "Dual contract test (Unit + Feature) for DB-free pinning — PricingAgentContractTest (Unit) pins static methods + DI binding without RefreshDatabase; PricingAgentRegistrationTest (Feature) provides the full-stack mirror once MySQL is online"
    - "Multi-segment Tools/{Domain}/ path — Plan 10-01 ships tools at app/Domain/Agents/Tools/Pricing/ (NOT app/Domain/Agents/Services/Tools/) so per-agent tool collections are namespace-clustered. AgentToolsNamingTest scans only Services/Tools/ which now stays vacuous; PricingToolStubsContractTest covers naming for Pricing tools instead"

key-files:
  created:
    - app/Domain/Agents/Agents/PricingAgent.php
    - app/Domain/Agents/Tools/Pricing/ReadMarginHistoryTool.php
    - app/Domain/Agents/Tools/Pricing/ReadCompetitorPricesTool.php
    - app/Domain/Agents/Tools/Pricing/ReadSupplierPriceTrendTool.php
    - app/Domain/Agents/Tools/Pricing/ReadSalesVolume90dTool.php
    - app/Domain/Agents/Tools/Pricing/ProposeMarginBandTool.php
    - resources/views/agents/pricing/.gitkeep
    - tests/Feature/Agents/FrameworkSmokeTest.php
    - tests/Feature/Agents/PricingAgentRegistrationTest.php
    - tests/Unit/Domain/Agents/Agents/PricingAgentContractTest.php
    - tests/Unit/Domain/Agents/Tools/Pricing/PricingToolStubsContractTest.php
  modified:
    - app/Providers/AppServiceProvider.php (afterResolving AgentRegistry block: 'echo' => EchoAgent::class swapped for 'pricing' => PricingAgent::class; SuggestionApplierResolver block trimmed — 'echo_health' applier registration deleted alongside EchoApplier class deletion)
  deleted:
    - app/Domain/Agents/Agents/EchoAgent.php
    - app/Domain/Agents/Appliers/EchoApplier.php
    - app/Domain/Agents/Services/Tools/ReadHealthCheckTool.php
    - resources/views/agents/echo/system.blade.php
    - tests/Feature/Agents/EchoAgentRunTest.php

key-decisions:
  - "PricingAgent::execute() throws LogicException — RunPricingAgentJob (Plan 10-04) owns orchestration. Same pattern as deleted EchoAgent::execute(). Per RESEARCH §Pattern 2 — RunsAsAgent::execute() is a forward-compat seam, NOT invoked by the framework's RunAgentJob."
  - "Tool path is app/Domain/Agents/Tools/Pricing/ (NOT Services/Tools/) — per-agent namespace clustering. AgentToolsNamingTest stays scoped to Services/Tools/ (vacuous after EchoAgent deletion); PricingToolStubsContractTest enforces naming for Pricing tools."
  - "Dual contract test (Unit + Feature) — PricingAgentContractTest (Unit, 6 tests) verifies static methods + DI binding without RefreshDatabase, runs regardless of MySQL state. PricingAgentRegistrationTest (Feature, 6 tests) is the full-stack mirror — runs when MySQL is online (Phase 6/7/8 deferral precedent applies until then)."
  - "Inline StubFrameworkAgent in FrameworkSmokeTest — replaces deleted EchoAgentRunTest contract via inline fixture (Pest single-file convention). No production agent class needed; the framework-integrity assertions (registry register/resolve + RuntimeException on unknown kind) survive the P10-H sweep."
  - "ProposeMarginBandTool ships full 6-parameter Prism schema (sku, proposed_bps, reasoning, confidence_0_to_100, band_min_bps, band_max_bps) in Plan 10-01 — using() callable returns {acknowledged:true} no-op (D-06 mapper-as-writer). Plan 10-02 does NOT need to touch this tool; Plan 10-04's PricingAgentResultMapper extracts final invocation args from agent_run.tool_calls[]."
  - "AppServiceProvider EchoApplier registration ALSO deleted — kind='echo_health' applier removed alongside EchoAgent. PricingAgent reuses the existing 'margin_change' MarginChangeApplier (Phase 5) for approve workflow; Plan 10-04 ships PricingAgentResultMapper for the agent-enrichment side seam."

requirements-completed: [PRCAGT-01, PRCAGT-02, PRCAGT-05]
# Note: PRCAGT-02 contract surface is pinned in Plan 10-01 (compile-time stubs);
# real tool implementations ship in Plan 10-02. PRCAGT-03 + PRCAGT-04 ship in
# Plan 10-04 (Filament UI + out-of-band chip + approve-with-reason modal).

duration: 16min
completed: 2026-04-30
---

# Phase 10 Plan 01: PricingAgent Skeleton + 5 Tool Stubs + EchoAgent Deletion Summary

**PricingAgent (kind='pricing', TrustTier::Trusted) registered + 5 Tool stubs at `app/Domain/Agents/Tools/Pricing/` + EchoAgent atomic deletion sweep + FrameworkSmokeTest replaces EchoAgentRunTest contract — first REAL Phase 8 framework consumer; Plan 10-02 ready to swap stub bodies for real DB queries.**

## Performance

- **Duration:** 16 min
- **Started:** 2026-04-30T09:34:47Z
- **Completed:** 2026-04-30T09:50:32Z
- **Tasks:** 3 (all atomic-committed)
- **Files created:** 11 (7 production + 3 tests + 1 .gitkeep)
- **Files modified:** 1 (AppServiceProvider.php)
- **Files deleted:** 5 (EchoAgent + 4 sibling fixtures)

## Accomplishments

- **EchoAgent + 4 sibling files atomically deleted (P10-H sweep)** — `EchoAgent.php`, `EchoApplier.php`, `ReadHealthCheckTool.php`, `resources/views/agents/echo/system.blade.php`, `tests/Feature/Agents/EchoAgentRunTest.php`. AppServiceProvider's two registration blocks (`'echo' => EchoAgent::class` on AgentRegistry; `'echo_health' => EchoApplier::class` on SuggestionApplierResolver) trimmed in the same commit. Empty parent dirs (`resources/views/agents/echo/`) auto-pruned by git rm. The framework-integrity contract preserved by `tests/Feature/Agents/FrameworkSmokeTest.php` using an inline `StubFrameworkAgent` fixture stub class (Pest single-file convention — mirrors the AlwaysRejectGuardrail precedent inside the deleted EchoAgentRunTest test 9).

- **5 PricingAgent tool stubs (PRCAGT-02 contract surface)** — `ReadMarginHistoryTool`, `ReadCompetitorPricesTool`, `ReadSupplierPriceTrendTool`, `ReadSalesVolume90dTool`, `ProposeMarginBandTool` shipped at `app/Domain/Agents/Tools/Pricing/`. Each extends Phase 8 `Tool` abstract base; each has `name()` (AGNT-05 prefix), `description()` (LLM-disambiguating ≥40 chars), `asPrismTool()` (Prism `Tool::as()->for()->withStringParameter()->using()` chain). The 4 read_* tools' `using()` callables return `{"_stub":true,"_phase":"10-01-skeleton","_note":"Plan 10-02 ships the real implementation","sku":...}` so any premature integration test fires loudly rather than silently looking like "no data". `ProposeMarginBandTool` ships the FULL 6-parameter Prism schema (sku/proposed_bps/reasoning/confidence_0_to_100/band_min_bps/band_max_bps) per CONTEXT D-06 — `using()` callable returns `{"acknowledged":true}` no-op writer; Plan 10-04's `PricingAgentResultMapper` extracts final invocation args from `agent_run.tool_calls[]` post-loop. Plan 10-02 does NOT need to touch ProposeMarginBandTool.

- **PricingAgent skeleton + AgentRegistry registration (PRCAGT-01 + PRCAGT-05)** — `app/Domain/Agents/Agents/PricingAgent.php` implements RunsAsAgent's 6-method surface verbatim per Phase 8 contract: `kind() === 'pricing'`, `trustTier() === TrustTier::Trusted`, `tools()` returns 5 Tool instances via container resolution, `systemPrompt()` delegates to PromptRenderer (loads `resources/views/agents/pricing/system.blade.php` — Plan 10-03 ships the real view; Plan 10-01 ships only `.gitkeep`), `guardrails()` returns 2 (SensitiveFieldsStripGuardrail + OutboundRegexFilterGuardrail; PromptInjectionXmlFence skipped per Trusted tier via `shouldRun()`), `execute()` throws LogicException per RESEARCH §Pattern 2 forward-compat seam. Registered in `AppServiceProvider::boot()` via `afterResolving(AgentRegistry::class, ...)` — replaces the deleted EchoAgent block in the same hook structure. Tinker check confirms: `app(AgentRegistry::class)->resolve('pricing')` returns `App\Domain\Agents\Agents\PricingAgent`.

- **PRCAGT-05 daily cap value locked at 500p** — `PricingAgentContractTest::it config/agents.php pricing daily cap is 500p` and `PricingAgentRegistrationTest::it config/agents.php pricing daily cap is 500p` both assert `(int) config('agents.daily_caps.pricing') === 500`. Locks RESEARCH Open Question 2 RESOLVED — future config drift fails build immediately. Daily cap value already shipped in Phase 8 Plan 01's `config/agents.php`; Plan 10-01 does not touch the config file.

## Task Commits

Each task was committed atomically:

1. **Task 1 — Atomically delete EchoAgent + 4 sibling fixtures (P10-H sweep)** — `badef6b` (refactor)
2. **Task 2 — Create 5 PricingAgent tool stubs at app/Domain/Agents/Tools/Pricing/** — `0393b31` (feat — TDD: PricingToolStubsContractTest written first as RED, then 5 stub classes turned it GREEN)
3. **Task 3 — Create PricingAgent class + AgentRegistry registration + 6 contract tests** — `339de00` (feat — TDD: PricingAgentContractTest written first as RED, then PricingAgent class + AppServiceProvider registration turned it GREEN)

**Plan metadata commit:** [pending — final commit at end of execution]

## Files Created/Modified

### Created (11)

- `app/Domain/Agents/Agents/PricingAgent.php` — `final class PricingAgent implements RunsAsAgent` (kind='pricing', TrustTier::Trusted, 5 tools, 2 guardrails, LogicException stub on execute)
- `app/Domain/Agents/Tools/Pricing/ReadMarginHistoryTool.php` — Stub with `name()='read_margin_history'`, Prism single-string-parameter schema, `_stub:true` placeholder body
- `app/Domain/Agents/Tools/Pricing/ReadCompetitorPricesTool.php` — Stub with `name()='read_competitor_prices'`, single-string-parameter schema, `_stub:true` placeholder body
- `app/Domain/Agents/Tools/Pricing/ReadSupplierPriceTrendTool.php` — Stub with `name()='read_supplier_price_trend'`, single-string-parameter schema, `_stub:true` placeholder body
- `app/Domain/Agents/Tools/Pricing/ReadSalesVolume90dTool.php` — Stub with `name()='read_sales_volume_90d'`, single-string-parameter schema, `_stub:true` placeholder body (note in docblock: real impl reads `products.last_sales_count_90d` + `products.last_sales_count_computed_at` per RESEARCH schema correction)
- `app/Domain/Agents/Tools/Pricing/ProposeMarginBandTool.php` — No-op writer with `name()='propose_margin_band'`, FULL 6-parameter Prism schema (sku/proposed_bps/reasoning/confidence_0_to_100/band_min_bps/band_max_bps), `using()` callable returns `{"acknowledged":true}` per D-06 mapper-as-writer
- `resources/views/agents/pricing/.gitkeep` — Placeholder for Plan 10-03's `system.blade.php` (PromptRenderer reads `agents.pricing.system` view)
- `tests/Feature/Agents/FrameworkSmokeTest.php` — Inline `StubFrameworkAgent` fixture + 2 tests asserting AgentRegistry register/resolve contract + RuntimeException on unknown kind (replaces deleted EchoAgentRunTest's framework-integrity contract)
- `tests/Feature/Agents/PricingAgentRegistrationTest.php` — Full-stack contract mirror (6 tests) — RefreshDatabase auto-applied per Pest convention; deferred until MySQL online (Phase 6/7/8 precedent)
- `tests/Unit/Domain/Agents/Agents/PricingAgentContractTest.php` — DB-free contract test (6 tests, 7 assertions) — runs regardless of MySQL state; pins static methods + DI binding + daily cap value
- `tests/Unit/Domain/Agents/Tools/Pricing/PricingToolStubsContractTest.php` — DB-free tool stub contract (5 tests, 40 assertions) — pins all 5 tool names, descriptions, asPrismTool() return type, Prism Tool name parity

### Modified (1)

- `app/Providers/AppServiceProvider.php` — boot(): SuggestionApplierResolver afterResolving block trimmed (deleted `'echo_health' => EchoApplier::class` registration); AgentRegistry afterResolving block updated (deleted `'echo' => EchoAgent::class`; added `'pricing' => PricingAgent::class`). Both inline comment blocks updated to reflect Phase 10 / P10-H provenance.

### Deleted (5)

- `app/Domain/Agents/Agents/EchoAgent.php` (Phase 8 framework smoke fixture)
- `app/Domain/Agents/Appliers/EchoApplier.php` (kind='echo_health' applier — no business value)
- `app/Domain/Agents/Services/Tools/ReadHealthCheckTool.php` (EchoAgent's only tool)
- `resources/views/agents/echo/system.blade.php` (EchoAgent's content-free system prompt)
- `tests/Feature/Agents/EchoAgentRunTest.php` (Phase 8 EchoAgent E2E test — contract migrated to FrameworkSmokeTest)

## Decisions Made

- **Tool path is `app/Domain/Agents/Tools/Pricing/` (NOT `Services/Tools/`)** — per-agent namespace clustering aligns with the future Phase 12 (`Tools/Seo/`), Phase 14 (`Tools/Chatbot/`), Phase 15 (`Tools/AdOptimisation/`) layout. AgentToolsNamingTest's directory scan stays scoped to `Services/Tools/` (vacuous after Phase 10-01's EchoAgent deletion); `PricingToolStubsContractTest` enforces naming for Pricing tools instead. Plan 10-02 ships `PricingToolsObserveSoftCapTest` for additional Pricing-specific architecture coverage.
- **PricingAgent::execute() throws LogicException — same pattern as deleted EchoAgent::execute()** — RESEARCH §Pattern 2 — RunsAsAgent::execute() is a forward-compatibility seam reserved for future agents that need to wrap orchestration; Phase 10's RunPricingAgentJob (Plan 10-04) owns orchestration via the framework's RunAgentJob pattern. The literal LogicException makes the architectural choice impossible to forget.
- **Dual Unit/Feature contract test for MySQL-deferral resilience** — PricingAgentContractTest (Unit, 6 tests, 7 assertions) verifies static methods + DI binding + `config('agents.daily_caps.pricing')` value WITHOUT RefreshDatabase; runs in CI regardless of MySQL state. PricingAgentRegistrationTest (Feature, 6 tests) is the full-stack mirror that adds RefreshDatabase boot; deferred per Phase 6/7/8 precedent until MySQL online. Both tests share the same 6-assertion shape so the contract is verified at two levels.
- **Inline `StubFrameworkAgent` in FrameworkSmokeTest** — replaces deleted EchoAgentRunTest contract via Pest single-file fixture stub (mirrors AlwaysRejectGuardrail precedent in the deleted file). No production agent class needed; the framework-integrity contract (`AgentRegistry::register()` then `resolve()` returns the registered instance, `resolve()` throws RuntimeException on unknown kind) survives the P10-H sweep.
- **EchoApplier registration deleted alongside EchoApplier class** — kind='echo_health' is no longer a registered applier in v2. PricingAgent reuses Phase 5's existing `'margin_change' => MarginChangeApplier::class` registration for the approve workflow; Plan 10-04 will ship `PricingAgentResultMapper` as the agent-enrichment side seam (NOT a new applier — it's a mapper that writes to `Suggestion.evidence.agent_*` keys).
- **ProposeMarginBandTool ships full 6-parameter Prism schema in Plan 10-01** — Plan 10-02 does NOT need to touch this tool. The schema (sku/proposed_bps/reasoning/confidence_0_to_100/band_min_bps/band_max_bps) is part of the LLM-facing contract surface; `using()` callable's `{"acknowledged":true}` no-op stays through to ship per CONTEXT D-06 mapper-as-writer.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Correctness] Added DB-free Unit contract test (PricingAgentContractTest) alongside the Feature test**

- **Found during:** Task 3 (running PricingAgentRegistrationTest)
- **Issue:** Plan 10-01 specified only `tests/Feature/Agents/PricingAgentRegistrationTest.php`. Pest's `tests/Pest.php` auto-applies `RefreshDatabase` to all `tests/Feature/`, which requires MySQL on `127.0.0.1:3306`. Local MySQL is offline (Phase 6/7/8 precedent — see 08-01-SUMMARY §Issues Encountered + 08-04-SUMMARY §Issue 4). Without an alternative, the contract is verified ONLY by `php -l` syntax check until MySQL is back — a critical-correctness regression in static methods or AppServiceProvider binding could land undetected.
- **Fix:** Added `tests/Unit/Domain/Agents/Agents/PricingAgentContractTest.php` mirroring all 6 assertions of the Feature variant. Unit tests use `tests/Pest.php`'s `uses(TestCase::class)->in('Unit')` (NOT RefreshDatabase) so they run regardless of MySQL state. Feature variant retained as-is — when MySQL is online, both variants assert the same contract at two levels (full-stack vs DI-only).
- **Files modified:** `tests/Unit/Domain/Agents/Agents/PricingAgentContractTest.php` (CREATED)
- **Verification:** `php artisan test --filter=PricingAgentContractTest --testsuite=Unit` → 6 passed (7 assertions), 4.31s. Tinker check: `app(AgentRegistry::class)->resolve('pricing')` returns `App\Domain\Agents\Agents\PricingAgent`.
- **Committed in:** `339de00` (Task 3 commit)

---

**Total deviations:** 1 (Rule 2 — security/correctness; added DB-free contract test for MySQL-deferral resilience)
**Impact on plan:** Strictly additive — the original Feature test ships unchanged; the new Unit test extends contract verification to CI's MySQL-offline path. No scope, success criteria, or downstream contract changes.

## Auth Gates

None — Plan 10-01 didn't trigger any auth gate. Anthropic API auth is not exercised by this plan (no Prism calls; PricingAgent's stubs return `_stub:true` placeholders synchronously; framework's `ClaudeClient` is not invoked from the contract test).

## Issues Encountered

- **MySQL deferral (precedent: Phase 6/7/8 Plans 01-05):** local MySQL service offline during execution (port 3306 refused). The 6 PricingAgentRegistrationTest cases + 2 FrameworkSmokeTest cases cannot run via the Feature suite until MySQL is back online. Mitigated by:
  - 6 PricingAgentContractTest cases (Unit suite) verify the same contract WITHOUT RefreshDatabase — all pass (7 assertions, 4.31s).
  - 5 PricingToolStubsContractTest cases (Unit suite) verify all 5 tool stubs — all pass (40 assertions, 3.68s).
  - `php -l` clean on all 11 created PHP files + 1 modified file.
  - Tinker spot-check: `app(AgentRegistry::class)->resolve('pricing')` returns `App\Domain\Agents\Agents\PricingAgent` — confirms AppServiceProvider binding lands at runtime.
  - `vendor/bin/deptrac analyse` exits 0 on BOTH `depfile.yaml` AND `deptrac.yaml` — Agents allow-list NOT widened (P10-H invariant honoured).
- **Pre-existing PolicyTemplateIntegrityTest failure on `app/Policies/RolePolicy.php` (out of scope):** test 1 of PolicyTemplateIntegrityTest fails with "Shield placeholder literal leaked into: app/Policies/RolePolicy.php" because RolePolicy.php was already in `M` state at the start of Plan 10-01 execution (pre-existing un-staged modification — see initial git status capture). RolePolicy.php is unrelated to Phase 10 scope. Documented in `.planning/phases/10-c1-pricing-agent/deferred-items.md` per execution scope-boundary policy. Tests 2 + 3 of PolicyTemplateIntegrityTest still pass; the floor-of-9-Policy-files assertion passes; Gate::policy bindings test passes. The failure is a **pre-existing** condition, NOT a Plan 10-01 regression.
- **Phase 5 + Phase 8 code byte-identity preserved:** `git diff badef6b~1 339de00 -- app/Domain/Agents/Models/ Services/ Jobs/ Contracts/ Enums/ Guardrails/ Policies/ Clients/ Console/ Filament/ app/Domain/Competitor/ app/Domain/Pricing/` returns ONLY the authorized `ReadHealthCheckTool.php` deletion (P10-H sweep). No other Phase 5 / Phase 8 byte changes.

## Verification Status

| Success criterion | Status |
| --- | --- |
| All 3 tasks committed atomically | DONE — badef6b, 0393b31, 339de00 |
| EchoAgent + 4 sibling files DELETED (5 paths via `git status` + filesystem check) | VERIFIED — `git ls-files app/Domain/Agents/Agents/EchoAgent.php` etc all return empty; `resources/views/agents/echo/` dir auto-pruned |
| PricingAgent + 5 tool stubs CREATED at correct paths | VERIFIED — all 6 files exist on disk; `php -l` clean |
| FrameworkSmokeTest.php replaces EchoAgentRunTest.php in same dir | VERIFIED — `tests/Feature/Agents/FrameworkSmokeTest.php` exists; `EchoAgentRunTest.php` deleted |
| AgentRegistry + ToolBus registrations updated in AppServiceProvider | VERIFIED — `'echo' => EchoAgent` deleted; `'pricing' => PricingAgent` added; `'echo_health' => EchoApplier` deleted |
| `php artisan tinker --execute='dump(app(...AgentRegistry::class)->resolve("pricing"))'` returns PricingAgent instance | VERIFIED — output: `App\Domain\Agents\Agents\PricingAgent` |
| PricingAgentBudgetCapTest passes (config('agents.daily_caps.pricing') === 500) | VERIFIED — embedded as test 6 of PricingAgentContractTest (Unit suite) AND test 6 of PricingAgentRegistrationTest (Feature suite, deferred MySQL); both assert `(int) config('agents.daily_caps.pricing') === 500` |
| FrameworkSmokeTest passes | DEFERRED — Feature suite needs MySQL; tests written, php -l clean, contract verified by Unit-suite mirrors |
| Phase 5 + Phase 8 code unchanged (git diff returns empty for those paths) | VERIFIED — only authorized P10-H deletions show up |
| Full Pest suite stays green (or MySQL deferral documented per Phase 6/7 precedent) | DOCUMENTED — Phase 6/7/8 precedent applies; 11 Unit-suite tests pass; pre-existing RolePolicy.php failure logged to deferred-items.md (out of Phase 10 scope) |
| Deptrac 0 violations | VERIFIED — `vendor/bin/deptrac analyse` exits 0 on BOTH `depfile.yaml` AND `deptrac.yaml` (474 allowed, 0 violations / 0 errors / 0 warnings) |
| 10-01-SUMMARY.md created | IN PROGRESS — this commit closes the loop |
| STATE.md + ROADMAP.md updated (plan 10-01 → completed) | IN PROGRESS — gsd-tools advance-plan + update-progress + roadmap update-plan-progress next |

## Known Stubs

The 5 PricingAgent tool stubs at `app/Domain/Agents/Tools/Pricing/` are INTENTIONAL stubs documented in their own classdoc. Each is replaced by Plan 10-02:

| File | Stub marker | Resolved by |
|------|-------------|-------------|
| ReadMarginHistoryTool.php | `using()` returns `{"_stub":true,"_phase":"10-01-skeleton","sku":...}` | Plan 10-02 — real `activity_log` + `Suggestion` query, 90d window, ≤30 entries downsample, 3 KB soft cap |
| ReadCompetitorPricesTool.php | `using()` returns `{"_stub":true,...}` | Plan 10-02 — real `competitor_prices` query, 90d window, group-by competitor, ≤50 most-recent across competitors, 3 KB soft cap |
| ReadSupplierPriceTrendTool.php | `using()` returns `{"_stub":true,...}` | Plan 10-02 — real `audit_log` probe + degraded fallback to current `buy_price`, 90d window, ≤30 entries, 3 KB soft cap (RESEARCH §Tool 3 Option A) |
| ReadSalesVolume90dTool.php | `using()` returns `{"_stub":true,...}` | Plan 10-02 — real `products.last_sales_count_90d` + `last_sales_count_computed_at` read, `_cache_age_hours` hint when stale (>24h) |
| ProposeMarginBandTool.php | `using()` returns `{"acknowledged":true}` | NOT replaced — D-06 mapper-as-writer; Plan 10-04's PricingAgentResultMapper extracts final invocation args from `agent_run.tool_calls[]` |

`resources/views/agents/pricing/.gitkeep` is also a stub — Plan 10-03 ships the real `system.blade.php` (persona + workflow + confidence rubric + output contract + 2 few-shot examples per CONTEXT D-07 + Claude's Discretion §System Prompt Design); the `.gitkeep` will be deleted by Plan 10-03.

All stubs are necessary for the plan's purpose — Plan 10-01 ships the contract surface so downstream plans (10-02 tools, 10-03 prompt, 10-04 job + mapper + Filament) can ship against a stable interface without re-touching the agent class or registration.

## Next Phase Readiness

- **Plan 10-02 (5 tool implementations + TruncatingTool base + 5 unit tests + PricingToolsObserveSoftCapTest)** — has all the contract-surface scaffolding it needs. Each stub class can be replaced in-place: only the `using()` callable body changes; class name, namespace, `name()`, `description()`, Prism schema, and AGNT-05 prefix all stay byte-identical. PricingToolStubsContractTest stays green throughout (it asserts contract surface, not implementation). Plan 10-02 will:
  - Add `app/Domain/Agents/Tools/Pricing/TruncatingTool.php` abstract base with `capJson()` helper enforcing 3 KB soft cap + `_truncated`/`_total_available` hints
  - Replace 4 read_* tool `using()` callables with real DB queries (90d window + soft-cap)
  - ProposeMarginBandTool stays as-is (D-06 no-op writer)
  - Ship 5 per-tool Pest unit tests (90d window + cap behaviour + `_cache_age_hours` hint)
  - Ship `tests/Architecture/PricingToolsObserveSoftCapTest.php` reflection gate
- **Plan 10-03 (system prompt Blade + PromptRenderer integration + Prism::fake E2E + temp=0 calibration)** — `resources/views/agents/pricing/.gitkeep` is the placeholder; Plan 10-03 adds `system.blade.php` here. PromptRenderer integration is already wired in PricingAgent::systemPrompt() — no changes needed.
- **Plan 10-04 (RunPricingAgentJob + PricingAgentResultMapper + Filament UI + out-of-band chip)** — all framework primitives ready: AgentRegistry resolves 'pricing'; PricingAgent::tools() returns 5 Tool instances; ProposeMarginBandTool's 6-parameter Prism schema is the LLM-facing contract Plan 10-04's mapper extracts. RunAgentJob (Phase 8) is the framework orchestrator — Plan 10-04's RunPricingAgentJob is a sibling job (RESEARCH §Pattern 1 / Path A) that wraps RunAgentJob plus the mapper invocation.
- **Plan 10-05 (`agent_rejection_feedback` migration + AgentRunRejectionInboxPage Filament + Shield perm)** — independent of Plan 10-01 scope; ships against the existing `Suggestion.evidence` JSON column.

**Outstanding (operator-side):**

- Bring up MySQL on `127.0.0.1:3306` and run:
  - `php artisan test --filter=PricingAgentRegistrationTest` — expects 6 tests green
  - `php artisan test --filter=FrameworkSmokeTest` — expects 2 tests green

## Self-Check: PASSED

- All 11 created PHP files exist on disk; `php -l` clean across all of them
- 5 deleted files: `git ls-files app/Domain/Agents/Agents/EchoAgent.php` returns empty (and same for EchoApplier, ReadHealthCheckTool, agents/echo/system.blade.php, EchoAgentRunTest.php)
- All 3 task commits exist in `git log`: badef6b, 0393b31, 339de00
- 11 Unit-suite tests pass: PricingAgentContractTest 6/6 (7 assertions) + PricingToolStubsContractTest 5/5 (40 assertions)
- Architecture suite: AgentsWriteOnlyViaSuggestionsTest 1/1, AgentToolsNamingTest 1/1, DeptracAgentsLayerTest 3/3 — all pass
- Tinker check: `app(AgentRegistry::class)->resolve('pricing')` returns `App\Domain\Agents\Agents\PricingAgent`
- `vendor/bin/deptrac analyse` exits 0 on BOTH `depfile.yaml` AND `deptrac.yaml` (474 allowed, 0 violations)
- Phase 5 + Phase 8 code byte-identity preserved: `git diff badef6b~1 339de00 -- app/Domain/Agents/Models/ Services/ Jobs/ Contracts/ Enums/ Guardrails/ Policies/ Clients/ Console/ Filament/ app/Domain/Competitor/ app/Domain/Pricing/` shows ONLY the authorized `ReadHealthCheckTool.php` deletion
- Pricing daily cap value of 500p locked by 2 contract tests (Unit + Feature)
- Pre-existing PolicyTemplateIntegrityTest failure on `app/Policies/RolePolicy.php` documented in `.planning/phases/10-c1-pricing-agent/deferred-items.md` (out of Phase 10 scope)

---
*Phase: 10-c1-pricing-agent*
*Completed: 2026-04-30*
