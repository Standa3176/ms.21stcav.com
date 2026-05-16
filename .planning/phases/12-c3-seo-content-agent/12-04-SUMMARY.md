---
phase: 12-c3-seo-content-agent
plan: 04
subsystem: agents
tags: [agents, seo-agent, run-job, mapper, applier, filament-sidebar, p12-a, p12-b, p12-f, seoagt-01, seoagt-03]

requires:
  - phase: 12-c3-seo-content-agent
    plan: 02
    provides: SeoAgent tool bodies + BrandSlugResolver helper (used by RunSeoAgentJob for slug derivation)
  - phase: 12-c3-seo-content-agent
    plan: 03
    provides: System prompt Blade view (sha256 75bac4c3...), 3-guardrail chain, GuardrailViolationException extended with failedPatternKey + matchedExcerpt
provides:
  - RunSeoAgentJob (Path A sibling job — final class implements ShouldQueue, 13-step orchestration mirroring RunPricingAgentJob with 3 structural diffs)
  - SeoAgentResultMapper (variable-cardinality bundled-Suggestion writer + P12-B guardrail-blocked Suggestion writer)
  - SeoContentPatchApplier (per-field write-through with CRITICAL title→name column mapping + ProductOverride pin upsert + audit via sha256 hashes)
  - EditAutoCreateReview SEO sidebar Section (additive — does NOT override form() or infolist(); new seoPatchesInfolist method + headerAction "Approve selected SEO patches")
  - AppServiceProvider registers kind='seo_content_patch' → SeoContentPatchApplier
  - AgentsWriteOnlyViaSuggestionsTest extended with 3 new exempt paths (Jobs/RunSeoAgentJob.php, Services/SeoAgentResultMapper.php, Appliers/SeoContentPatchApplier.php)
  - 23 Pest cases / 112 assertions across 6 test files locking the runtime path end-to-end
affects: [12-05-batch-command-shield-verification]

tech-stack:
  added: []  # zero composer changes — built entirely on Phase 8 + Phase 10 + Phase 12-01/02/03 primitives
  patterns:
    - "Path A sibling job — RunSeoAgentJob mirrors RunPricingAgentJob structurally (NOT a subclass per RESEARCH §A9). Three structural diffs: $productId required (not nullable), Step 12 invokes mapper->createBundledSuggestion (vs Phase 10's mergeIntoSuggestion update), triggering_suggestion_id=null (batch-driven not pull-driven)."
    - "P12-A LAST-WINS dedup — SeoAgentResultMapper performs UNCONDITIONAL `$patchesByField[$field] = ...` assignment with NO presence guard. If a future PR adds a first-wins guard, SeoAgentResultMapperTest::P12-A fixture fails first AND the source-level grep gate (returns 0 for `isset($patchesByField`) trips. This single-line defence prevents the FIRST forbidden patch from escaping detection."
    - "P12-B catch-block audit — RunSeoAgentJob's catch(GuardrailViolationException) calls $mapper->createGuardrailBlockedSuggestion($run, $product, $e->failedPatternKey, $e->matchedExcerpt) BEFORE rethrowing. Plan 12-03's exception extension carries the pattern key + excerpt; this plan converts that forensic data into a kind='agent_guardrail_blocked' Suggestion. NO partial publishing per CONTEXT D-01 — zero seo_content_patch Suggestions exist after a blocked run."
    - "CRITICAL title→name column mapping — SEOAGT-01 user-facing field 'title' translates to Product.name column at write time. FIELD_TO_PRODUCT_COLUMN constant locks the mapping; SeoContentPatchApplierTitleToNameTest fences with 3 assertions (Product.name updated, Product fillable does NOT list 'title', source contains literal `'title' => 'name'`). Audit log preserves the user-facing 'field' value ('title') — only the COLUMN write is remapped."
    - "P12-F additive sidebar — EditAutoCreateReview implements HasInfolists with a DIFFERENTLY-NAMED method seoPatchesInfolist (NOT a form() or infolist() override). Phase 6 admin edit form schema lives on AutoCreateReviewResource::form() and stays byte-identical; AutoCreateEditFormUnchangedTest fences with 5 assertions including a reflection-based check that the class declares neither form() nor infolist() locally."
    - "Approve-selected via modal CheckboxList — RESEARCH §Pattern 4 fallback A5 (Filament 3.3 RepeatableEntry doesn't natively support per-row Actions). Plan 12-04 ships the simpler header Action that opens a modal with a multi-select; on submit it flips selected patches' applied_at to now() on the Suggestion payload and invokes SeoContentPatchApplier::apply directly. Per-row actions deferred to v2.1 if admin feedback justifies."
    - "ProductOverride upsert preserves other pin flags — applier uses Eloquent fill+save on the existing row (NOT updateOrCreate's 2nd-arg overlay) so an existing pin_image=true or margin_basis_points=2500 are preserved across SEO approvals. When creating a fresh ProductOverride row, supplies margin_basis_points=0 to satisfy the NOT NULL constraint (Rule 3 deviation — see below)."

key-files:
  created:
    - app/Domain/Agents/Services/SeoAgentResultMapper.php
    - app/Domain/Agents/Appliers/SeoContentPatchApplier.php
    - app/Domain/Agents/Jobs/RunSeoAgentJob.php
    - tests/Feature/Agents/Seo/SeoAgentResultMapperTest.php
    - tests/Feature/Agents/Seo/SeoContentPatchApplierTest.php
    - tests/Feature/Agents/Seo/SeoContentPatchApplierTitleToNameTest.php
    - tests/Feature/Agents/Seo/RunSeoAgentJobHappyPathTest.php
    - tests/Feature/Agents/Seo/RunSeoAgentJobGuardrailBlockedTest.php
    - tests/Feature/Agents/Seo/AutoCreateEditFormUnchangedTest.php
  modified:
    - app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource/Pages/EditAutoCreateReview.php (extended additively per P12-F — HasInfolists + InteractsWithInfolists + seoPatchesInfolist method + getHeaderActions with approve_selected_patches Action)
    - app/Providers/AppServiceProvider.php (afterResolving SuggestionApplierResolver block: appended register('seo_content_patch', SeoContentPatchApplier::class) immediately after the existing quote_push_failed registration)
    - tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php (3 new exempt notPath entries — Jobs/RunSeoAgentJob.php, Services/SeoAgentResultMapper.php, Appliers/SeoContentPatchApplier.php)
  deleted: []

key-decisions:
  - "Path A sibling (not Phase 8 RunAgentJob subclass) — Phase 10 precedent locked at RESEARCH §A9. Mirroring Phase 8's catch-block lifecycle byte-identically is easier to reason about than fighting it via overrides. Two ~50-LOC orchestrators with parallel structure beat one conditional orchestrator."
  - "P12-A defended at THREE layers — (1) the unconditional `$patchesByField[$field] = ...` assignment, (2) the SeoAgentResultMapperTest LAST-WINS fixture asserting `after === 'SECOND PROPOSAL'`, (3) the source-level grep gate on `isset($patchesByField` returning 0. Three independent fences mean a regression requires bypassing ALL three to land."
  - "P12-B defended at TWO layers — (1) the catch(GuardrailViolationException) block calls createGuardrailBlockedSuggestion BEFORE rethrowing (file source line 242 BEFORE line 271), (2) RunSeoAgentJobGuardrailBlockedTest asserts ONE agent_guardrail_blocked Suggestion exists + ZERO seo_content_patch Suggestions exist after a blocked run."
  - "ProductOverride upsert hand-rolled (NOT Eloquent updateOrCreate) — the table's margin_basis_points column is NOT NULL with no DB default. updateOrCreate's 1st-arg match would generate an INSERT that omits margin_basis_points and trip a SQLite/MySQL NOT NULL violation. Hand-rolled if/else: existing row → fill+save (preserves margin_basis_points); fresh row → create with margin_basis_points=0 baseline (semantically: 'no margin override; only pin flags meaningful')."
  - "Audit records user-facing 'field' value ('title') NOT column name ('name') — preserves admin's mental model when browsing audit_log. The COLUMN-write remapping is an internal implementation detail; the AUDIT SEMANTICS remain SEOAGT-01 user-facing."
  - "Audit hashes (sha256) NOT verbatim text — keeps audit_log table lean across thousands of patches over the 5-year retention horizon. Verbatim before/after text stays on Suggestion.payload which has its own retention; the audit row's role is forensic linkage (suggestion_id + agent_run_id + field + hashes), not full-text replay."
  - "Approve-selected modal variant over per-row inline action — Filament 3.3 RepeatableEntry doesn't natively support per-row Actions. RESEARCH §Pattern 4 calls out this constraint with Assumption A5 — Plan 12-04 ships the simpler ship (admin tick multiple fields then 1 submit) rather than the engineering-heavier inline-Livewire-Action variant. v2.1 may retrofit if admin reports the multi-step UX is friction."
  - "EditAutoCreateReview seoPatchesInfolist named for P12-F invariant — Filament's HasInfolists trait resolves any public `*Infolist(Infolist)` method on the page, so naming the new SEO sidebar method `seoPatchesInfolist` keeps the parent EditRecord's default `infolist()` method untouched. AutoCreateEditFormUnchangedTest asserts `method_exists(EditAutoCreateReview::class, 'seoPatchesInfolist')` AND `! in_array('infolist', $methodsDeclaredOnThisClass)`."

metrics:
  duration_minutes: 17
  tasks_completed: 3
  files_created: 9
  files_modified: 3
  files_deleted: 0
  tests_added: 23
  test_assertions_added: 112
  composer_changes: 0
  migrations: 0
completed-date: 2026-05-16

commits:
  - hash: 8341242
    message: "feat(12-04): SeoAgentResultMapper + SeoContentPatchApplier (P12-A + title→name)"
  - hash: 07ba5ae
    message: "feat(12-04): RunSeoAgentJob Path A sibling — P12-B catch-block audit"
  - hash: 3e8fac9
    message: "feat(12-04): EditAutoCreateReview SEO sidebar Section (P12-F additive)"
---

# Phase 12 Plan 04: RunSeoAgentJob + SeoAgentResultMapper + SeoContentPatchApplier + Filament Sidebar Summary

Wired the SeoAgent end-to-end — Path A sibling job orchestrating Anthropic via Prism, bundled-Suggestion mapper extracting variable-cardinality propose_content_patch calls into ONE Suggestion of kind `seo_content_patch`, per-field write-through applier with the CRITICAL `title → Product.name` column mapping, and the additive Filament sidebar Section on `EditAutoCreateReview` (P12-F: zero Phase 6 form regression).

## Verified Runtime Path

End-to-end flow exercised by Pest fixtures:

```
RunSeoAgentJob::dispatch($productId, $batchCorrelationId)
    ↓
handle() → Product::findOrFail → eligibility re-check (auto_create_status === 'pending_review')
    ↓
AgentRegistry->resolve('seo') → PromptRenderer->render('seo', $context) → AgentRun::create(kind='seo', status='running', system_prompt_hash=75bac4c3..., triggering_suggestion_id=null)
    ↓
BudgetGuard->assertHasBudget('seo') → GuardrailEngine->runPreFlight
    ↓
ClaudeClient->generate(systemPrompt, messages, tools, temperature: 0.4)
    ↓
GuardrailEngine->runPostFlight (SeoOutboundGuardrail at index 2 — scans every propose_content_patch's before+after)
    ↓
[HAPPY PATH]                               [GUARDRAIL-BLOCKED PATH — P12-B]
  ↓                                          ↓
extractToolCallsFromSteps                  catch(GuardrailViolationException $e)
  ↓                                          ↓
$run->update(status='completed', ...)      mapper->createGuardrailBlockedSuggestion(
  ↓                                              $run, $product,
BudgetGuard->recordSpend('seo', $cost)         $e->failedPatternKey,
  ↓                                              $e->matchedExcerpt)  ← writes ONE
if (AGENT_WRITE_ENABLED):                     agent_guardrail_blocked Suggestion
  mapper->createBundledSuggestion             ↓
    → ONE Suggestion(kind='seo_content_patch') $run->update(status='guardrail_blocked',
      with payload.patches[] (1-4 entries)         guardrail_failures=[{...}])
      (P12-A LAST-WINS dedup)                  ↓
                                             throw $e (Horizon records failure)
```

Then the human-in-the-loop seam (Plan 12-04 Filament surface):

```
Admin opens EditAutoCreateReview/{record}/edit
    ↓
seoPatchesInfolist() Section renders 1-4 patches in a RepeatableEntry
    ↓
Admin clicks "Approve selected SEO patches" header action → modal opens with CheckboxList
    ↓
Admin ticks fields → submit → action flips selected patches' applied_at to now()
    ↓
SeoContentPatchApplier::apply($suggestion->fresh())
    ↓
DB::transaction:
  - Product.{name | short_description | long_description | meta_description} = $patch['after']
    (CRITICAL: 'title' → Product.name via FIELD_TO_PRODUCT_COLUMN constant)
  - ProductOverride: pin_{field} = true (upsert preserves pin_image / margin_basis_points / etc.)
  - Auditor::record('seo.content_patch_applied', { field: 'title' (user-facing),
                                                    before_hash, after_hash,
                                                    product_id, suggestion_id,
                                                    agent_run_id })
  - Suggestion.status flips: STATUS_APPLIED if all patches applied, STATUS_PENDING if subset
```

## Three Critical Defences Shipped

### P12-A — Mapper LAST-WINS dedup

**Threat:** The agent calls `propose_content_patch('title', ...)` twice. A naive `if (! isset($patchesByField[$field]))` guard would lock in the FIRST call's `after` text — a downstream regression where an early forbidden phrase escapes detection because a later on-brand phrase never overwrites it.

**Defence (3 layers):**
1. **Code:** Line 99 of `SeoAgentResultMapper.php` performs `$patchesByField[$field] = [...]` UNCONDITIONALLY. No `isset` guard.
2. **Test:** `SeoAgentResultMapperTest::"P12-A LAST-WINS"` fixture passes two calls for `field='title'` and asserts `$patches[0]['after'] === 'SECOND PROPOSAL'`.
3. **Grep gate:** `grep -c 'isset($patchesByField' app/Domain/Agents/Services/SeoAgentResultMapper.php` returns `0`. A regression PR adding such a guard trips CI on this gate.

### P12-B — Catch-block audit BEFORE rethrow

**Threat:** Plan 12-03's `SeoOutboundGuardrail::post()` throws `GuardrailViolationException` (stateless — scan + throw only). Without a catch-block audit hook, a blocked run loses its forensic trail entirely (CONTEXT D-01 mandates an `agent_guardrail_blocked` Suggestion per blocked run).

**Defence (2 layers):**
1. **Code:** `RunSeoAgentJob.php` line 242 calls `$mapper->createGuardrailBlockedSuggestion(...)` BEFORE line 271's `throw $e`. The mapper reads `$e->failedPatternKey` + `$e->matchedExcerpt` (Plan 12-03's exception extension) and writes ONE Suggestion of kind `agent_guardrail_blocked`. NO partial publishing — zero `seo_content_patch` Suggestions exist after a blocked run.
2. **Test:** `RunSeoAgentJobGuardrailBlockedTest::"P12-B"` fixture seeds a forbidden pattern ('revolutionary' — in `marketing_superlatives`) and asserts:
   - `Suggestion::where('kind', 'agent_guardrail_blocked')->count() === 1`
   - `Suggestion::where('kind', 'seo_content_patch')->count() === 0`
   - `AgentRun.status === 'guardrail_blocked'` + `guardrail_failures` JSON populated
   - The exception was rethrown after the audit Suggestion was written (Horizon records the failure)

### P12-F — Additive Filament extension (Phase 6 form unchanged)

**Threat:** A naive Filament 3 sidebar extension overrides `EditAutoCreateReview::infolist()` (Phase 6's default), silently regressing the admin edit form. P12-F mandates byte-identical Phase 6 form behaviour.

**Defence (3 layers):**
1. **Naming:** The new method is `seoPatchesInfolist(Infolist)` — a DIFFERENT name from Filament's default `infolist()` handler. Filament's HasInfolists trait resolves any public `*Infolist` method, so the parent EditRecord's default `infolist()` is left untouched.
2. **No form() override:** `getHeaderActions()` and `seoPatchesInfolist()` are the ONLY methods declared locally on `EditAutoCreateReview`. The Phase 6 form schema lives entirely on `AutoCreateReviewResource::form()` and stays byte-identical.
3. **Test:** `AutoCreateEditFormUnchangedTest` asserts:
   - All 8 Phase 6 form fields (`sku`, `name`, `slug`, `short_description`, `long_description`, `meta_description`, `auto_create_status`, `completeness_score`) are present in `AutoCreateReviewResource.php` source.
   - `method_exists(EditAutoCreateReview::class, 'seoPatchesInfolist') === true`.
   - `EditAutoCreateReview::class` implements `HasInfolists`.
   - The class declares NEITHER `form()` NOR `infolist()` locally (reflection check on `$method->getDeclaringClass()->getName()`).

## Plan 12-05 Dependency Contract

Plan 12-05's `RunSeoAgentBatchCommand` must call this exact dispatch signature:

```php
RunSeoAgentJob::dispatch(
    productId: $product->id,
    batchCorrelationId: $batchUuid,  // shared across the night's 20 products
);
```

Both constructor args are public readonly. The `batchCorrelationId` is optional (null fallback to Context, fallback to fresh UUID inside `handle()`). Plan 12-05's batch loop should generate ONE UUID per batch run and pass it to all 20 dispatches so the night's runs cluster correctly in Langfuse / Filament.

## Deviations from Plan

### Rule 3 (auto-fixed blocking issue) — ProductOverride.margin_basis_points NOT NULL constraint

- **Found during:** Task 1 GREEN phase, running SeoContentPatchApplierTest fixture 1.
- **Issue:** `product_overrides.margin_basis_points` is NOT NULL with no DB default (Phase 3 D-08 — the column was designed as the canonical margin % override). The plan's specified `ProductOverride::updateOrCreate(['product_id' => $product->id], $overrideUpdates)` would generate an INSERT lacking `margin_basis_points` on first call and trip a SQLite/MySQL `NOT NULL constraint violation`.
- **Fix:** Replaced `updateOrCreate` with hand-rolled if/else:
  - If a ProductOverride row exists for the product → `$existing->fill($overrideUpdates)->save()` (preserves the existing `margin_basis_points` AND other pin flags).
  - If no row exists → `ProductOverride::create(['product_id' => ..., 'margin_basis_points' => 0, ...$overrideUpdates])` (seeds `margin_basis_points=0` semantically meaning "no margin override; only SEO pin flags meaningful here").
- **Files modified:** `app/Domain/Agents/Appliers/SeoContentPatchApplier.php`
- **Test:** `SeoContentPatchApplierTest::"ProductOverride upsert preserves OTHER pin flags"` seeds a row with `pin_image=true` + `margin_basis_points=2500` BEFORE the applier runs, then asserts both are preserved AFTER. Passes.
- **Commit:** rolled into `8341242` (Task 1's commit).

### Rule 3 (auto-fixed blocking issue) — Anthropic API key + Event::fake missing in Pest setup

- **Found during:** Task 2 GREEN phase, first Pest run of RunSeoAgentJobHappyPathTest.
- **Issue 1:** RunSeoAgentJob fires `AgentRunStarted` / `AgentRunCompleted` / `AgentRunFailed` events. Some listener in the project subscribes with a closure-bearing `ShouldQueue` listener that crashes `serialize(clone $job)` under `QUEUE_CONNECTION=sync` because closures aren't serializable. Same pre-existing issue affecting Phase 10 RunPricingAgentJobTest fixtures 2-4 (verified — they fail with identical error at parent commit).
- **Issue 2:** Even with Prism::fake() intercepting the actual HTTP request, ClaudeClient still calls `IntegrationCredentialResolver::for(IntegrationCredentialKind::AnthropicApi)` to forward an `api_key` into Prism's provider config. With no DB row + no env fallback in the Pest environment, the resolver throws `IntegrationCredentialMissingException`.
- **Fix:** `beforeEach` block in both `RunSeoAgentJobHappyPathTest.php` + `RunSeoAgentJobGuardrailBlockedTest.php` now:
  - Calls `Event::fake([AgentRunStarted::class, AgentRunCompleted::class, AgentRunFailed::class])` to neutralise the queued-listener serialisation.
  - Calls `config()->set('prism.providers.anthropic.api_key', 'sk-test-fake-key')` + `Cache::flush()` to satisfy the env-fallback path of IntegrationCredentialResolver. Value is unused because Prism::fake intercepts the HTTP request; only its non-emptiness matters.
- **Files modified:** `tests/Feature/Agents/Seo/RunSeoAgentJobHappyPathTest.php`, `tests/Feature/Agents/Seo/RunSeoAgentJobGuardrailBlockedTest.php`
- **Commit:** rolled into `07ba5ae` (Task 2's commit).

### Rule 3 (auto-fixed blocking issue) — Filament 3.3 Form::make() requires HasForms livewire double

- **Found during:** Task 3 GREEN phase, running AutoCreateEditFormUnchangedTest fixture 1.
- **Issue:** The plan's specified approach (`AutoCreateReviewResource::form(Form::make(new HasFormsDouble))->getComponents()`) calls `Form::make()` which requires a real `HasForms` livewire component. Spinning up a Livewire test double is more brittle than the regression contract we're guarding (which is: "no Phase 6 form field was removed").
- **Fix:** Replaced the reflection-style field enumeration with a source-level grep on `AutoCreateReviewResource.php`. For each expected Phase 6 field, scan the source for `TextInput::make('{field}')` OR `Textarea::make('{field}')` OR `Select::make('{field}')`. Equivalent regression-detection coverage (a removed field disappears from the source); zero Filament-internal coupling.
- **Files modified:** `tests/Feature/Agents/Seo/AutoCreateEditFormUnchangedTest.php`
- **Commit:** rolled into `3e8fac9` (Task 3's commit).

No other deviations — plan executed as written. No Rule 4 (architectural change) invoked; no auth gates encountered.

## Authentication Gates

None encountered. All work is local SQLite in-memory + Prism::fake (zero real Anthropic API calls) + Filament reflection. No third-party services exercised.

## Known Stubs

None. The applier writes through to real `Product` + `ProductOverride` columns; the mapper writes real `Suggestion` rows; the sidebar action runs the real `SeoContentPatchApplier::apply()` flow. The kind `agent_guardrail_blocked` is intentionally NOT registered with `SuggestionApplierResolver` per the design — admin cannot approve those audit-only Suggestions; Plan 12-05 filters them from the default Filament Suggestions list.

## Test Coverage

```
tests/Feature/Agents/Seo/SeoAgentResultMapperTest.php             6 cases / 33 assertions
tests/Feature/Agents/Seo/SeoContentPatchApplierTest.php           5 cases / 24 assertions
tests/Feature/Agents/Seo/SeoContentPatchApplierTitleToNameTest.php 3 cases / 11 assertions
tests/Feature/Agents/Seo/RunSeoAgentJobHappyPathTest.php          3 cases / 17 assertions
tests/Feature/Agents/Seo/RunSeoAgentJobGuardrailBlockedTest.php   1 case / 8 assertions
tests/Feature/Agents/Seo/AutoCreateEditFormUnchangedTest.php      5 cases / 19 assertions
                                                          ---
                                                          TOTAL: 23 cases / 112 assertions
```

## Verification

```bash
"C:/Users/sonny.tanda/.config/herd/bin/php.bat" vendor/bin/pest tests/Feature/Agents/Seo/ tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php
```

**Result:** 73 passed (198 assertions) in 34.84s. Plan 12-04 scope green; Plan 12-01/02/03 zero regression; AgentsWriteOnlyViaSuggestionsTest architecture invariant honoured (3 new exempt paths added).

```bash
"C:/Users/sonny.tanda/.config/herd/bin/php.bat" vendor/bin/pest tests/Architecture/AgentToolsNamingTest.php tests/Architecture/TruncatingToolRelocationTest.php tests/Feature/Agents/PricingAgentRegistrationTest.php tests/Feature/Agents/PricingAgentPromptHashTest.php
```

**Result:** 17 passed (40 assertions). Phase 8 + Phase 10 framework invariants byte-identical post-Plan-12-04.

## Threat Flags

None new beyond the plan's `<threat_model>` register (T-12-04-01 through T-12-04-06). All six dispositions honoured by code + tests:

- **T-12-04-01** (Mapper writes wrong field due to last-wins flip) → mitigate: UNCONDITIONAL `$patchesByField[$field] = ...` + Pest fixture asserts second-call-wins + grep gate.
- **T-12-04-02** (Applier writes to Product.title silently failing) → mitigate: FIELD_TO_PRODUCT_COLUMN literal `'title' => 'name'` + dedicated SeoContentPatchApplierTitleToNameTest (3 assertions).
- **T-12-04-03** (Guardrail-blocked run loses audit trail — P12-B) → mitigate: catch-block calls createGuardrailBlockedSuggestion BEFORE throw $e; Pest fixture asserts ONE agent_guardrail_blocked Suggestion + ZERO seo_content_patch Suggestions.
- **T-12-04-04** (Sidebar override breaks Phase 6 edit form — P12-F) → mitigate: new method named seoPatchesInfolist (NOT infolist); AutoCreateEditFormUnchangedTest fences with reflection-based check that the class declares neither form() nor infolist() locally.
- **T-12-04-05** (Auditor records full before/after bloating audit_log) → mitigate: Applier records sha256 before_hash + after_hash ONLY; verbatim text stays on Suggestion.payload.
- **T-12-04-06** (Admin approves guardrail-blocked Suggestion they shouldn't see) → accept: kind has no applier registered; Plan 12-05 filters from default list. If approved manually via tinker, ApplySuggestionJob → resolver throws "No SuggestionApplier registered for kind: agent_guardrail_blocked" (verified by SuggestionApplierResolver::resolve at line 30).

## Outstanding Manual Verification

Filament sidebar rendering UX (visual confirmation that the Section + RepeatableEntry + headerAction render correctly in the admin browser) is NOT covered by the 23 Pest cases — those exercise the data flow + class contract + source-level grep gates. Plan 12-05's verification doc should include a `checkpoint:human-verify` task asking the admin to:

1. Run `php artisan agents:run-seo-batch --live --limit=1` (Plan 12-05 ships this command).
2. Open the resulting Product's `/admin/auto-create-reviews/{id}/edit` page.
3. Confirm the "SEO content patches" Section renders below the form with 1-4 RepeatableEntry rows.
4. Click "Approve selected SEO patches", tick one field, confirm the modal submits and the Notification appears.
5. Re-load the page and confirm Product.{name|description} reflects the approved patch.

This is the load-bearing UX that Pest cannot exercise. Plan 12-05 inherits the responsibility.

## Self-Check: PASSED

- File `app/Domain/Agents/Services/SeoAgentResultMapper.php` — FOUND, contains `createBundledSuggestion`, `createGuardrailBlockedSuggestion`, `'seo_content_patch'`, `'agent_guardrail_blocked'`; grep `isset($patchesByField` returns 0
- File `app/Domain/Agents/Appliers/SeoContentPatchApplier.php` — FOUND, contains `'title' => 'name'` (2 occurrences — docblock + constant), `FIELD_TO_PRODUCT_COLUMN`, `FIELD_TO_PIN_COLUMN`, `DB::transaction`
- File `app/Domain/Agents/Jobs/RunSeoAgentJob.php` — FOUND, `final class implements ShouldQueue`, `public int $tries = 1;`, `public int $timeout = 180;`, `onQueue('agents')`, `auto_create_status !== 'pending_review'`, `temperature: (float) config('agents.seo.temperature', 0.4)`, `BrandSlugResolver::forBrandId`; `createGuardrailBlockedSuggestion` (line 242) BEFORE `throw $e` (line 271)
- File `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource/Pages/EditAutoCreateReview.php` — FOUND, contains `seoPatchesInfolist`, `Section::make`, `RepeatableEntry`, `latestSeoSuggestion`, 2× `Action::make('approve_selected_patches'`; implements `HasInfolists`; declares neither `form()` nor `infolist()` locally
- File `app/Providers/AppServiceProvider.php` — FOUND, registers `'seo_content_patch'` → `SeoContentPatchApplier` (multi-line registration block)
- File `tests/Architecture/AgentsWriteOnlyViaSuggestionsTest.php` — FOUND, 3 new exempt notPath entries (Jobs/RunSeoAgentJob.php, Services/SeoAgentResultMapper.php, Appliers/SeoContentPatchApplier.php)
- Test files (6) — ALL FOUND
- Commit `8341242` (Task 1) — FOUND
- Commit `07ba5ae` (Task 2) — FOUND
- Commit `3e8fac9` (Task 3) — FOUND
- Pest plan-scope suite — 23 passed (112 assertions), 0 failed
- Pest cross-cutting regression — 50 additional passes (Phase 12-01/02/03 + Phase 8/10 framework + Architecture invariants); 0 regressions
- PHP -l on every modified .php file — clean (6 files)
