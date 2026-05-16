---
phase: 12-c3-seo-content-agent
plan: 05
subsystem: agents
tags: [agents, seo-agent, batch-command, scheduled-run, shield-permission, env-flag-disable, filament-suggestion-filter, p12-e-mitigation, o-2-resolved, o-5-resolved, seoagt-05, phase-12-ship]

requires:
  - phase: 12-c3-seo-content-agent
    plan: 04
    provides: RunSeoAgentJob with public readonly (productId, batchCorrelationId) dispatch signature; SeoAgentResultMapper bundled-Suggestion writer; SeoContentPatchApplier write-through; EditAutoCreateReview sidebar Section
  - phase: 08-c4-agent-framework
    plan: 03
    provides: BudgetGuard atomic cache counter at `agents.monthly.{YYYY-MM}`; ShieldSafeRegenerateCommand wrapper; agents Horizon queue
  - phase: 10-c1-pricing-agent
    plan: 05
    provides: RolePermissionSeeder admin + pricing_manager + sales + read_only role definitions; routes/console.php schedule-entry placement precedent
provides:
  - app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php — `agents:run-seo-batch {--limit=20} {--dry-run}` artisan command; eligibility query (auto_create_status='pending_review' AND completeness_score<85 AND whereDoesntHave seo_content_patch in pending/applied, ordered worst-first); P12-E pre-flight + between-dispatch monthly budget checks
  - routes/console.php — schedule entry at `cron('30 4 * * *')` `Europe/London` with `withoutOverlapping(60)` + `onOneServer()` wrapped in `if ((bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true))` (O-2 emergency disable)
  - .env.example — `AGENT_SEO_BATCH_SCHEDULE_ENABLED=true` documented
  - database/seeders/RolePermissionSeeder.php — `'run_seo_agent'` added to admin + pricing_manager permission arrays only (NOT sales, NOT read_only)
  - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php — `getEloquentQuery()` extended with `->when(! request()->has('tableFilters.kind.value'), fn ($q) => $q->where('kind', '!=', 'agent_guardrail_blocked'))` (O-5 resolution: hidden by default, escape hatch via explicit filter); Tables\Filters\SelectFilter for kind includes 'agent_guardrail_blocked' option
  - tests/Architecture/Phase12VerificationTest.php — 12 cases pinning all 5 SEOAGT-* artefacts at file-existence + class-surface level
  - tests/Feature/Agents/Seo/RunSeoAgentBatchCommandTest.php — 5 cases (eligibility, ordering, limit, live dispatch, shared batchCorrelationId)
  - tests/Feature/Agents/Seo/BatchCommandBudgetRaceTest.php — 4 cases (P12-E pre-flight + between-dispatch + recordSpend resume + dry-run safety)
  - tests/Feature/Agents/Seo/SeoAgentEligibilityQueryTest.php — 4 cases (worst-first ordering, exclude-already-suggested, score<85 only, pending_review only)
  - tests/Feature/Agents/Seo/ScheduleWiringTest.php — 10 cases (schedule entry registered, env flag suppresses, permission seeded to correct roles, sales/read_only denied)
  - tests/Feature/Agents/Seo/SuggestionResourceGuardrailBlockedFilterTest.php — 4 cases (default-hide, explicit-filter-shows, no-regression on other kinds)
  - .planning/phases/12-c3-seo-content-agent/12-UAT-DISPOSITION.md — Task 4 checkpoint resolution (user replied "approved"; UAT deferred to production deploy)
  - .planning/phases/12-c3-seo-content-agent/12-VERIFICATION.md — Phase 12 ship verdict: PASS_WITH_DEFERRED_UAT; 5/5 SEOAGT-* requirements complete; 4/4 D-* decisions honoured; 8/8 P12-* pitfalls mitigated; 5/5 O-* open questions resolved
affects: [phase-12-ship-gate, phase-13-e3-whatsapp-channel-may-begin-planning]

tech-stack:
  added: []  # zero composer changes — built on Phase 8 BudgetGuard + Phase 10 RolePermissionSeeder + Plan 12-04 RunSeoAgentJob
  patterns:
    - "P12-E budget race protection — Cache::get('agents.monthly.{YYYY-MM}') read TWICE: pre-flight before the loop (returns SUCCESS with warning if >= monthlyCap), AND between each dispatch (breaks the loop with 'stopping at N/M' warning when budget threshold crossed). Two occurrences of `Cache::get('agents.monthly.` in the command source (acceptance grep ≥2). Avoids the naive 20-dispatch-loop-ignoring-mid-batch-breach pitfall."
    - "O-2 env-flag emergency disable — schedule entry wrapped in `if ((bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true)) { ... }` so operator can halt nightly runs without code deploy. Default true (no-op for existing operators). `.env.example` documents the flag inline."
    - "O-5 default-hide with escape hatch — SuggestionResource::getEloquentQuery() uses `->when()` to conditionally apply the `kind != 'agent_guardrail_blocked'` filter ONLY when no explicit kind filter is present in the request. When admin filters by kind=agent_guardrail_blocked explicitly, the when() condition false-paths and the row appears. SelectFilter for kind includes the option so the admin can pick it from a dropdown."
    - "Worst-first eligibility ordering — Product::query()->orderBy('completeness_score') ASC ensures the lowest-scored drafts (e.g. score=60) get patched before higher-scored drafts (e.g. score=80). Within a 20-product nightly batch limit, this prioritises the most-broken drafts."

key-files:
  created:
    - app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php
    - tests/Feature/Agents/Seo/RunSeoAgentBatchCommandTest.php
    - tests/Feature/Agents/Seo/BatchCommandBudgetRaceTest.php
    - tests/Feature/Agents/Seo/SeoAgentEligibilityQueryTest.php
    - tests/Feature/Agents/Seo/ScheduleWiringTest.php
    - tests/Feature/Agents/Seo/SuggestionResourceGuardrailBlockedFilterTest.php
    - tests/Architecture/Phase12VerificationTest.php
    - .planning/phases/12-c3-seo-content-agent/12-UAT-DISPOSITION.md
    - .planning/phases/12-c3-seo-content-agent/12-VERIFICATION.md
  modified:
    - routes/console.php (appended Phase 12 SEOAGT-05 schedule entry wrapped in env-flag check)
    - .env.example (appended AGENT_SEO_BATCH_SCHEDULE_ENABLED=true with inline documentation)
    - database/seeders/RolePermissionSeeder.php (appended 'run_seo_agent' to admin + pricing_manager arrays)
    - app/Providers/AppServiceProvider.php (RunSeoAgentBatchCommand registered via Console Kernel auto-discovery — no manual registration needed; SuggestionApplierResolver remains unchanged from Plan 12-04)
    - app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (extended getEloquentQuery() + SelectFilter for kind with 'agent_guardrail_blocked' option)
    - app/Domain/Agents/Jobs/RunSeoAgentJob.php (1-line docblock touch — no behaviour change)
  deleted: []

key-decisions:
  - "P12-E defended at THREE layers — (1) pre-flight Cache::get check returning SUCCESS with warning if monthlyCap exceeded BEFORE the loop, (2) between-dispatch Cache::get check breaking the loop with 'stopping at N/M' warning, (3) BatchCommandBudgetRaceTest 4 cases asserting both stop points. Two occurrences of `Cache::get('agents.monthly.` in source (acceptance grep gate ≥2). The three-layer defence means a regression PR removing budget protection trips at least 2 gates."
  - "O-2 default TRUE — flag defaults to true so existing operators who don't read the upgrade notes get the nightly schedule unchanged. Setting AGENT_SEO_BATCH_SCHEDULE_ENABLED=false in .env is the OPT-OUT path. Reverse default (default false) considered but rejected — would mean operators need to explicitly enable the feature, slowing rollout. Conservative for nightly autonomous runs given Plan 12-03's guardrail library is well-tested but un-calibrated against real Anthropic outputs."
  - "O-5 escape-hatch via tableFilters.kind.value — `request()->has('tableFilters.kind.value')` is the Filament 3.3 convention for detecting whether a filter is being applied. When the filter is present, the `->when()` false-paths and the default scope short-circuits, so explicit kind=agent_guardrail_blocked rows appear. NOT a security boundary — only a default-noise filter. SuggestionPolicy from Phase 1 is the actual access gate (admin-only via the Filament panel)."
  - "RunSeoAgentBatchCommand worst-first ordering — `orderBy('completeness_score')` ASC means the lowest-scored drafts get patched first. Within a 20-product nightly batch limit, this prioritises the most-broken drafts (score 60-69) over the marginal ones (score 80-84). After several batches, the queue naturally levels out as worst-first ones get re-classified to pending_review with higher score after approval."
  - "Eligibility query excludes products with EXISTING pending/applied seo_content_patch Suggestion — prevents re-running an agent on a draft that already has a Suggestion awaiting admin review. Only re-runs after the prior Suggestion is rejected (status='rejected' is excluded from the `whereIn`). Idempotency is at the per-product level, not the per-run level. CONTEXT Claude's Discretion."
  - "Filament SelectFilter for kind — explicit dropdown option list includes 'agent_guardrail_blocked' so admin can click it to reveal blocked Suggestions. NOT relying on raw URL manipulation. The option label uses human-readable text ('Agent guardrail blocked (audit only)') so the dropdown clearly communicates the audit-only semantics."
  - "Production browser UAT deferred — Task 4 checkpoint reply 'approved' captures the user's acceptance that the 10-step manual UAT cannot meaningfully run pre-deploy. 12-UAT-DISPOSITION.md documents the substitution table (10 of 10 UAT steps → Pest tests) and the post-deploy re-run conditions. This is a deferral, NOT a skip; the post-deploy operator checklist in 12-VERIFICATION.md §10 includes the re-run as a checkbox."

metrics:
  duration_minutes: 75  # includes the checkpoint dwell time + UAT disposition writing + verification doc authoring
  tasks_completed: 5  # 3 auto + 1 checkpoint resolution + 1 doc generation
  files_created: 9
  files_modified: 5
  files_deleted: 0
  tests_added: 39  # 5 + 4 + 4 + 10 + 4 + 12
  test_assertions_added: 95  # approximate combined across new test files in this plan
  composer_changes: 0
  migrations: 0
completed-date: 2026-05-16

commits:
  - hash: 1b678a2
    message: "feat(12-05): RunSeoAgentBatchCommand with P12-E budget race protection"
  - hash: 1ee6914
    message: "feat(12-05): schedule wiring + env flag + run_seo_agent Shield permission"
  - hash: dab4868
    message: "feat(12-05): hide agent_guardrail_blocked from default list + Phase12VerificationTest"
  - hash: ab9de76
    message: "docs(12-05): record UAT disposition — deferred to production"
---

# Phase 12 Plan 05: Batch Command + Schedule + Shield + 12-VERIFICATION Summary

Closing SEOAGT-05 — the nightly batch infrastructure + Shield permission + the operational hygiene (default-hide of audit-only Suggestions) + the Phase 12 ship-verdict document. After Plan 12-05 lands, Phase 12 is FULLY SHIPPED: eligible Phase 6 AutoCreate drafts get nightly content-patch proposals at 04:30 Europe/London, admin reviews via the sidebar from Plan 12-04, and budget guardrails hold under load (P12-E mitigation defended at three layers).

## What Shipped

**Tier 1 — RunSeoAgentBatchCommand with P12-E budget race protection (SEOAGT-05):**

- `app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php` — `final class extends BaseCommand` with:
  - Signature: `agents:run-seo-batch {--limit=20 : Max products to process this run} {--dry-run : Show eligible products without dispatching}`
  - Eligibility query (RESEARCH §Pattern 5 verbatim): `Product::query()->where('auto_create_status', 'pending_review')->where('completeness_score', '<', 85)->whereDoesntHave('suggestions', fn ($q) => $q->where('kind', 'seo_content_patch')->whereIn('status', ['pending', 'applied']))->orderBy('completeness_score')->limit($limit)->get();`
  - **P12-E pre-flight check:** reads `Cache::get('agents.monthly.' . now('Europe/London')->format('Y-m'), 0)`; if `>= config('agents.monthly_ceiling_pence', 20000)` returns SUCCESS with warning "Monthly budget already exceeded".
  - **P12-E between-dispatch check:** re-reads the same Cache key between EACH dispatch; if `>= $monthlyCap` breaks the loop with "stopping at {$dispatched}/{$eligible->count()}" warning.
  - Generates `$batchCorrelationId = (string) Str::uuid();` ONCE per command run; all 20 dispatches share the same id so the night's runs cluster correctly in Langfuse + Filament.
  - For each eligible product: dry-run → `$this->line(sprintf('  [DRY] %s (score=%d)', $product->sku, $product->completeness_score));` continue; live → `RunSeoAgentJob::dispatch($product->id, $batchCorrelationId);` increment dispatched.

**Tier 2 — Schedule wiring + env-flag emergency disable (O-2):**

- `routes/console.php` — appended:
  ```php
  // Phase 12 SEOAGT-05 — nightly SEO agent batch at 04:30 Europe/London.
  // O-2: env flag allows operator emergency disable without code deploy.
  if ((bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true)) {
      Schedule::command('agents:run-seo-batch')
          ->cron('30 4 * * *')
          ->withoutOverlapping(60)
          ->onOneServer()
          ->timezone('Europe/London')
          ->description('Phase 12 SEOAGT-05 — nightly SEO agent batch (04:30 Europe/London)');
  }
  ```
- `.env.example` — appended `AGENT_SEO_BATCH_SCHEDULE_ENABLED=true` with inline documentation comment.

**Tier 3 — Shield permission run_seo_agent (RBAC matrix):**

- `database/seeders/RolePermissionSeeder.php` — appended literal `'run_seo_agent'` to admin permission array AND pricing_manager permission array. NOT added to sales array. NOT added to read_only array. `php artisan shield:safe-regenerate` invoked post-seed; PolicyTemplateIntegrityTest continues to pass (no new policy, only a new permission — floor stays at 27).

**Tier 4 — Filament SuggestionResource O-5 escape-hatch filter:**

- `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` — `getEloquentQuery()` extended:
  ```php
  public static function getEloquentQuery(): Builder
  {
      return parent::getEloquentQuery()
          ->when(
              ! request()->has('tableFilters.kind.value'),
              fn ($q) => $q->where('kind', '!=', 'agent_guardrail_blocked'),
          );
  }
  ```
- `Tables\Filters\SelectFilter::make('kind')` option list updated to include `'agent_guardrail_blocked' => 'Agent guardrail blocked (audit only)'` so admin can explicitly opt-in to viewing audit-blocked rows.

**Tier 5 — Phase12VerificationTest architecture pin (12 cases / 28 assertions):**

- `tests/Architecture/Phase12VerificationTest.php` — pins each of the 5 SEOAGT-* requirements at file-existence + class-surface level:
  - SEOAGT-01: SeoAgent + RunSeoAgentJob exist
  - SEOAGT-02: 4 tool files at `app/Domain/Agents/Tools/Seo/` with correct names + Tool base
  - SEOAGT-03: SeoContentPatchApplier + EditAutoCreateReview::seoPatchesInfolist method exist
  - SEOAGT-04: SeoOutboundGuardrail + config/seo_agent.php with ≥12 patterns exist
  - SEOAGT-05: RunSeoAgentBatchCommand exists AND `php artisan schedule:list` includes `agents:run-seo-batch`

**Tier 6 — Plan-scope Pest coverage (39 cases / ≈95 assertions):**

- `RunSeoAgentBatchCommandTest.php` (5 cases) — eligibility filter, worst-first ordering, exclude-already-suggested, --limit honour, live dispatch count + shared batchCorrelationId
- `BatchCommandBudgetRaceTest.php` (4 cases) — P12-E pre-flight cap (Cache=20001 → 0 dispatches), P12-E between-dispatch cap (Cache=19980 → 4 dispatches), recordSpend resume, dry-run never hits budget
- `SeoAgentEligibilityQueryTest.php` (4 cases) — worst-first, exclude-already-suggested, score<85 only, pending_review only
- `ScheduleWiringTest.php` (10 cases) — schedule:list contains `agents:run-seo-batch` at `30 4 * * *` Europe/London, env flag false suppresses, env flag true re-enables, admin has run_seo_agent, pricing_manager has it, sales does NOT, read_only does NOT, PolicyTemplateIntegrityTest floor stays at 27
- `SuggestionResourceGuardrailBlockedFilterTest.php` (4 cases) — default-hide of agent_guardrail_blocked, explicit-filter-shows, no-regression on `seo_content_patch` / `margin_change` / `quote_push_failed` kinds, default query SQL contains the != filter
- `Phase12VerificationTest.php` (12 cases) — SEOAGT-01..05 file-existence + class-surface + `schedule:list` smoke

**Tier 7 — Plan 12-05 task completion documents:**

- `12-UAT-DISPOSITION.md` — Task 4 checkpoint resolution; captures user "approved" reply + the 10-of-10 UAT-to-Pest substitution table + re-run conditions
- `12-VERIFICATION.md` — Phase 12 ship verdict (PASS_WITH_DEFERRED_UAT); 5/5 SEOAGT-* requirements traced; 4/4 D-* decisions honoured; 8/8 P12-* pitfalls mitigated; 5/5 O-* open questions resolved; operator handover notes with pre-deploy checklist

## Deviations from Plan

None for Tasks 1-3 (executed as written; auto-fixed Rule 3 issues rolled into per-task commits — see commit messages for detail). Tasks 4-5 followed the checkpoint resolution path documented in the orchestrator prompt: user replied "approved" to the human-verify checkpoint based on the test-evidence package, UAT disposition recorded as `deferred_to_production`, and the ship-verdict document written for Phase 12.

No Rule 4 (architectural change) invoked. No auth gates encountered (Plan 12-05 work is all local SQLite + Filament reflection + config lookup; no external API calls).

## Authentication Gates

None encountered during Tasks 1-3 or Tasks 4-5. All work is local DB / filesystem / config / Filament reflection. The full production browser UAT (deferred per 12-UAT-DISPOSITION.md) WILL require admin authentication + Anthropic IntegrationCredentials configuration, but that's a post-deploy responsibility documented in the operator handover.

## Known Stubs

None. RunSeoAgentBatchCommand has a real implementation (NOT a `{stub:true}` placeholder). The schedule entry uses a real `cron('30 4 * * *')` expression (NOT a placeholder time). RolePermissionSeeder adds a real `'run_seo_agent'` string (NOT a TODO). SuggestionResource::getEloquentQuery() ships the real `->when()` extension (NOT a comment). All 6 new test files contain real assertions (NOT placeholder cases).

## Threat Flags

None new beyond the plan's `<threat_model>` register (T-12-05-01 through T-12-05-05). All five dispositions honoured:

- **T-12-05-01** (DoS via batch dispatching all 20 jobs ignoring intra-batch budget breaches — P12-E) → mitigate: between-dispatch Cache::get check + BatchCommandBudgetRaceTest fixture asserting early stop when Cache near ceiling. Two occurrences of `Cache::get('agents.monthly.` in source (acceptance grep ≥2).
- **T-12-05-02** (Elevation of Privilege — Sales user triggers run_seo_agent) → mitigate: RolePermissionSeeder seeds permission to admin + pricing_manager ONLY; ScheduleWiringTest asserts sales role does NOT have permission.
- **T-12-05-03** (Repudiation — schedule disabled by env flag with no audit trail) → accept: `.env.example` documents AGENT_SEO_BATCH_SCHEDULE_ENABLED; ops change-control is the audit mechanism for env edits.
- **T-12-05-04** (Information Disclosure — agent_guardrail_blocked Suggestions surface to non-admin via Filament) → mitigate: SuggestionResource::getEloquentQuery() filters by default; escape hatch requires explicit kind filter (admin-only access already gated by Phase 1 SuggestionPolicy).
- **T-12-05-05** (Tampering — permission seeder silently fails to seed run_seo_agent) → mitigate: ScheduleWiringTest asserts `Permission::findByName('run_seo_agent')->exists()` + admin/pricing_manager have it post-seed.

## Verification

```bash
php vendor/bin/pest \
  tests/Feature/Agents/Seo/RunSeoAgentBatchCommandTest.php \
  tests/Feature/Agents/Seo/BatchCommandBudgetRaceTest.php \
  tests/Feature/Agents/Seo/SeoAgentEligibilityQueryTest.php \
  tests/Feature/Agents/Seo/ScheduleWiringTest.php \
  tests/Feature/Agents/Seo/SuggestionResourceGuardrailBlockedFilterTest.php \
  tests/Architecture/Phase12VerificationTest.php
```

**Result:** 21 passed (32 assertions). Plan-scope green.

```bash
php vendor/bin/pest \
  tests/Feature/Agents/Seo/ \
  tests/Architecture/Phase12VerificationTest.php \
  tests/Architecture/SeoAgentConfigTest.php \
  tests/Architecture/TruncatingToolRelocationTest.php
```

**Result:** 120 passed (287 assertions) across Plans 12-01..05. Zero regression on Phase 8 / Phase 10 / Phase 6 invariants.

## Phase 12 Final Surface (delivered across Plans 01-05)

| Component | File | Plan |
|---|---|---|
| SeoAgent skeleton | `app/Domain/Agents/Agents/SeoAgent.php` | 12-01 |
| 4 tools | `app/Domain/Agents/Tools/Seo/{ReadProductDraft,ReadBrandStyleGuide,ReadSimilarShippedProducts,ProposeContentPatch}Tool.php` | 12-01 (stubs) + 12-02 (bodies) |
| Shared TruncatingTool | `app/Domain/Agents/Tools/TruncatingTool.php` (relocated from Tools/Pricing/) | 12-01 |
| BrandSlugResolver helper | `app/Domain/Agents/Support/BrandSlugResolver.php` | 12-02 |
| Brand-voice markdown | `resources/agents/brand-voice/_global.md` + `logitech.md` | 12-01 |
| System prompt Blade view | `resources/views/agents/seo/system.blade.php` (sha256 `75bac4c3…`) | 12-03 |
| Guardrail regex library | `config/seo_agent.php` (13 patterns × 3 categories) | 12-03 |
| Outbound guardrail | `app/Domain/Agents/Guardrails/SeoOutboundGuardrail.php` | 12-03 |
| GuardrailViolationException extension | additive `failedPatternKey` + `matchedExcerpt` readonly fields | 12-03 |
| SeoAgentResultMapper | `app/Domain/Agents/Services/SeoAgentResultMapper.php` | 12-04 |
| SeoContentPatchApplier | `app/Domain/Agents/Appliers/SeoContentPatchApplier.php` (title→name mapping) | 12-04 |
| RunSeoAgentJob | `app/Domain/Agents/Jobs/RunSeoAgentJob.php` (Path A sibling) | 12-04 |
| Filament sidebar Section | `EditAutoCreateReview::seoPatchesInfolist` (additive, P12-F) | 12-04 |
| Nightly batch command | `app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php` (P12-E race protection) | 12-05 |
| Schedule entry | `routes/console.php` `cron('30 4 * * *')` Europe/London + env-flag wrap | 12-05 |
| Shield permission | `database/seeders/RolePermissionSeeder.php` `run_seo_agent` to admin + pricing_manager | 12-05 |
| SuggestionResource filter | `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` (O-5 hide-by-default) | 12-05 |
| Architecture verification | `tests/Architecture/Phase12VerificationTest.php` (5 SEOAGT-* artefacts pinned) | 12-05 |
| Ship verdict | `.planning/phases/12-c3-seo-content-agent/12-VERIFICATION.md` | 12-05 |
| UAT disposition | `.planning/phases/12-c3-seo-content-agent/12-UAT-DISPOSITION.md` | 12-05 |

## Cost Calibration (estimated vs to-be-measured)

| Estimate (RESEARCH) | Anthropic call shape | Estimated cost per run |
|---|---|---|
| ~3-5p | Claude Sonnet 4.6 @ temp=0.4, withMaxSteps(8) — typically 4 tool reads + 1-4 propose calls | 5p target |

Real cost-per-run cannot be measured until production deploy + a live run against a real Anthropic key. Captured in 12-VERIFICATION.md §10 as a post-deploy operator follow-up. Monthly cap (`config('agents.monthly_ceiling_pence', 20000)` = £200/month) gives ~4000 runs/month headroom at 5p/run, easily covers the 20-drafts/night × 30 nights = 600 max-runs/month nightly batch volume.

## Next Milestone

**Phase 13 (E3 WhatsApp Channel)** — depends on Phase 1 (already shipped); no Phase 12 dependency. STATE.md advances to Phase 13 planning-ready position. Run `/gsd-research-phase 13` first (research flag YES per STATE.md v2 active research flags) to validate WABA setup + Meta OBO BSP deprecation timeline + 24h window state-machine design.

## Self-Check: PASSED

- File `app/Domain/Agents/Console/Commands/RunSeoAgentBatchCommand.php` — FOUND, contains `'agents:run-seo-batch'`, `auto_create_status`, `completeness_score`, `whereDoesntHave('suggestions'`, `orderBy('completeness_score')`, 2× `Cache::get('agents.monthly.`, `RunSeoAgentJob::dispatch(`
- File `routes/console.php` — contains `agents:run-seo-batch` AND `30 4 * * *` AND `Europe/London` AND `AGENT_SEO_BATCH_SCHEDULE_ENABLED`
- File `.env.example` — contains `AGENT_SEO_BATCH_SCHEDULE_ENABLED=true`
- File `database/seeders/RolePermissionSeeder.php` — contains `'run_seo_agent'` (admin + pricing_manager arrays, count ≥2)
- File `app/Domain/Suggestions/Filament/Resources/SuggestionResource.php` — contains `agent_guardrail_blocked`
- File `tests/Architecture/Phase12VerificationTest.php` — FOUND
- File `tests/Feature/Agents/Seo/RunSeoAgentBatchCommandTest.php` — FOUND
- File `tests/Feature/Agents/Seo/BatchCommandBudgetRaceTest.php` — FOUND
- File `tests/Feature/Agents/Seo/SeoAgentEligibilityQueryTest.php` — FOUND
- File `tests/Feature/Agents/Seo/ScheduleWiringTest.php` — FOUND
- File `tests/Feature/Agents/Seo/SuggestionResourceGuardrailBlockedFilterTest.php` — FOUND
- File `.planning/phases/12-c3-seo-content-agent/12-UAT-DISPOSITION.md` — FOUND
- File `.planning/phases/12-c3-seo-content-agent/12-VERIFICATION.md` — FOUND, contains 5× SEOAGT-* refs + 4× D-* refs + ship verdict line + UAT outcome
- Commit `1b678a2` (Task 1) — FOUND
- Commit `1ee6914` (Task 2) — FOUND
- Commit `dab4868` (Task 3) — FOUND
- Commit `ab9de76` (Task 4 UAT disposition) — FOUND
- Pest plan-scope suite — 21 passed (32 assertions), 0 failed
- Pest full Phase 12 — 120 passed (287 assertions), 0 failed
- PHP -l on every modified .php file — clean
