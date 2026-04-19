---
phase: 04-bitrix24-crm-sync
plan: 05
subsystem: crm
tags: [bitrix24, crm, backfill, gdpr, pitfall-5, deptrac, wp-snippets, retention, cutover-guardrails, d-11, verification]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: BaseCommand (correlation_id threading), Auditor, IntegrationLogger, activity_log retention model, SuggestionPolicy pattern, PolicyTemplateIntegrityTest guardrail
  - phase: 02-supplier-sync
    provides: SyncSupplierCommand dry-run-default + --live opt-in pattern (Phase 2 D-04); DeptracSyncLayerTest negative-test template + exit-code-only assertion; WooClient for Woo orders REST read path
  - phase: 03-pricing-engine
    provides: PricingRecomputeCommand dry-run-default scoped-flags pattern (Phase 3 D-12); DeptracPricingLayerTest positive+negative template; 03-VERIFICATION.md ship-verdict shape
  - phase: 04-bitrix24-crm-sync
    provides: Plan 04-01 ships BitrixBackfillRun model + bitrix_entity_map + BitrixClient skeleton; Plan 04-02 ships BitrixClient full SDK wrapper + shadow-mode + D-11 exception classifier; Plan 04-03 ships PushOrderToBitrixJob + EntityDeduper + CrmPushRetryApplier; Plan 04-04 ships CrmPushLogResource for the GDPR Filament action target
provides:
  - BitrixBackfillOrdersCommand (3 modes: dry-run / live / adopt-legacy-deal-ids) with --since REQUIRED + 50-chunk pagination + 600ms inter-page sleep + T-04-05-02 concurrent-run guard + >10% failure-rate exit-1
  - BackfillOrdersChunkJob on sync-bulk queue (Pitfall 7) with per-order failure handling + live-mode idempotency short-circuit + dry-run config override
  - BackfillProgressTracker — atomic ->increment() writes to bitrix_backfill_runs
  - GdprEraseBitrixCustomerCommand with typed ERASE confirmation + --dry-run lookup + --no-confirm automation escape hatch
  - EraseBitrixContactJob on default queue with $tries=1 (no silent retry on GDPR scrubs)
  - GdprEraser service — scrub-in-place for 18 Contact PII fields + 4 Deal PII fields preserving OPPORTUNITY / STAGE_ID / UF_CRM_WOO_ORDER_ID / financial record fields
  - EraseCustomerAction Filament header action on CrmPushLogResource — admin-only + typed ERASE confirmation
  - gdpr_erasure_log table with indefinite retention (NOT touched by any prune command) + GdprErasureLogEntryPolicy (admin read-only; create/update/delete DENIED)
  - DeptracCrmLayerTest (positive + negative with cleanup-before-assertion) pinning CRM allow-list [Foundation, Sync, Alerting, Webhooks, Suggestions]
  - docs/wordpress-snippets/ — ms-utm-capture.js + ms-utm-persist.php + README with 3 deploy options (mu-plugin / theme footer / GTM) for WP team
  - 04-VERIFICATION.md ship verdict — PASS for all 6 ROADMAP criteria + 13 CRM-* requirements + 12 D-* decisions
  - PolicyTemplateIntegrityTest floor raised 14 → 16 (+GdprErasureLogEntryPolicy +CrmPushLogPolicy coverage)
  - Phase 2 round-trip rollback step count bumped 19 → 20 to absorb new migration
affects:
  - 05-competitor-module — no direct dependency; Phase 5 can start immediately. BitrixEntityMap, CRM_WRITE_ENABLED env gate, and sync_diffs.provider='bitrix' shadow rows are all in place for any cross-module needs.
  - 07-dashboard-polish-cutover — cutover runbook consumes --adopt-legacy-deal-ids as the pre-cutover runway clearer (Pitfall 5); gdpr_erasure_log is the indefinite-retention regulator-query surface; 04-VERIFICATION.md is the ship gate

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "3-mode backfill CLI — --since REQUIRED (no default; prevents 2019-scoped accidents); dry-run default + --live opt-in + --adopt-legacy-deal-ids as highest-priority flag. Mirrors Phase 2 D-04 + Phase 3 D-12 dry-run-default pattern."
    - "Chunk job on sync-bulk queue (Pitfall 7) — per-order failures count locally but don't abort the chunk; BitrixTransientException propagates to Horizon retry (tries=2); BitrixPermanentException counts as failed + continues."
    - "Atomic counter updates via Eloquent ->increment() on BitrixBackfillRun — survives multi-worker races without a database lock, since the SQL is UPDATE … SET col = col + n."
    - "GDPR scrub-in-place hardcoded field lists (18 Contact + 4 Deal) — explicit allow-list, NOT reflection on the schema. Protects against future Bitrix UF_CRM_* field additions accidentally leaking PII."
    - "gdpr_erasure_log indefinite retention — deliberately separate from activity_log so the retention policy difference is unambiguous. No prune command lists it; GdprErasureRetentionTest guards this forever."
    - "EraseCustomerAction dual-entry-point — CLI + Filament bulk action converge on the same EraseBitrixContactJob + GdprEraser + audit trail. Regulator never asks 'did the CLI path scrub the same fields as the UI path?'"
    - "WP snippets as repo-shipped docs (NOT auto-deployed Laravel artefacts) — `docs/wordpress-snippets/` carries ms-utm-capture.js + ms-utm-persist.php + README with 3 deploy options. The WP team takes ownership at Phase 7 cutover."
    - "Deptrac negative test cleanup-before-assertion — @unlink($violatorFile) runs BEFORE expect() so a failed assertion never leaves the planted violator on disk (Phase 2 Plan 05 lesson)."

key-files:
  created:
    - app/Domain/CRM/Console/Commands/BitrixBackfillOrdersCommand.php
    - app/Domain/CRM/Jobs/BackfillOrdersChunkJob.php
    - app/Domain/CRM/Services/BackfillProgressTracker.php
    - app/Domain/CRM/Console/Commands/GdprEraseBitrixCustomerCommand.php
    - app/Domain/CRM/Jobs/EraseBitrixContactJob.php
    - app/Domain/CRM/Services/GdprEraser.php
    - app/Domain/CRM/Filament/Actions/EraseCustomerAction.php
    - app/Domain/CRM/Models/GdprErasureLogEntry.php
    - app/Domain/CRM/Policies/GdprErasureLogEntryPolicy.php
    - database/migrations/2026_04_20_100000_create_gdpr_erasure_log_table.php
    - database/factories/Domain/CRM/GdprErasureLogEntryFactory.php
    - tests/Architecture/DeptracCrmLayerTest.php
    - tests/Feature/CRM/BitrixBackfillOrdersCommandTest.php (9 tests)
    - tests/Feature/CRM/AdoptLegacyDealIdsTest.php (4 tests)
    - tests/Feature/CRM/BackfillOrdersChunkJobTest.php (4 tests)
    - tests/Feature/CRM/GdprEraserTest.php (6 tests)
    - tests/Feature/CRM/GdprEraseBitrixCustomerCommandTest.php (5 tests)
    - tests/Feature/CRM/EraseCustomerActionTest.php (3 tests)
    - tests/Feature/CRM/GdprErasureRetentionTest.php (1 test)
    - docs/wordpress-snippets/ms-utm-capture.js
    - docs/wordpress-snippets/ms-utm-persist.php
    - docs/wordpress-snippets/README.md
    - .planning/phases/04-bitrix24-crm-sync/04-VERIFICATION.md
  modified:
    - app/Domain/CRM/Filament/Resources/CrmPushLogResource.php (added EraseCustomerAction::make() header action)
    - app/Providers/AppServiceProvider.php (registered BitrixBackfillOrdersCommand + GdprEraseBitrixCustomerCommand + Gate::policy for GdprErasureLogEntry)
    - tests/Architecture/PolicyTemplateIntegrityTest.php (positive-control floor 14 → 16; added GdprErasureLogEntry → GdprErasureLogEntryPolicy pair)
    - tests/Feature/Phase02DataModelTest.php (rollback step count 19 → 20 to absorb new gdpr_erasure_log migration)

key-decisions:
  - "Config override for dry-run mode (NOT a new $forceShadow job param): BackfillOrdersChunkJob::handle() calls config(['services.bitrix.write_enabled' => false]) at the top when mode='dry-run'. Scoped to the worker process running the chunk — no PushOrderToBitrixJob signature change. Simpler than the plan's original $forceShadow parameter proposal; BitrixClient's existing shadowIfDisabled() first-statement check is the canonical interception point."
  - "Dispatch PushOrderToBitrixJob synchronously (dispatchSync) from BackfillOrdersChunkJob: the chunk already runs on sync-bulk with a 300s timeout — no reason to push another layer of queue indirection onto crm-bitrix and contend with live webhook traffic."
  - "--since REQUIRED (no default): prevents 'backfill everything since 2019' accidents. The guard is at command-level, not chunk-job-level, so even --only-order-id=42 with no --since works (surgical retry path)."
  - "Live-mode idempotency short-circuit: BackfillOrdersChunkJob checks BitrixEntityMap existence BEFORE manufacturing a WebhookReceipt + dispatching PushOrderToBitrixJob. A previously-pushed order never even creates a synthetic receipt. Both faster AND cleaner than delegating to the order.updated path."
  - "GDPR confirmation uses Filament's built-in `rule('in:ERASE')` (not a custom closure): first attempt used a Closure returning `function ($attribute, $value, $fail)` but Filament's evaluator tried to DI-resolve $attribute and threw. Switched to the stock `in:ERASE` validation rule + `validationMessages(['in' => 'ERASE literal required'])`. Simpler and explicitly Laravel-native."
  - "GdprEraser's dealList(CONTACT_ID) covers adopted + manually-attached Deals without BitrixEntityMap rows. An erasure must scrub EVERY Deal linked to the Contact on the Bitrix side — the map is the fast path, not the source of truth for erasure."
  - "18 Contact PII fields NOT 17: the plan's interfaces block literally listed 18 keys (NAME, LAST_NAME, SECOND_NAME, PHONE, EMAIL, WEB, IM, ADDRESS, ADDRESS_2, ADDRESS_CITY, ADDRESS_POSTAL_CODE, ADDRESS_REGION, ADDRESS_PROVINCE, POST, BIRTHDATE, COMMENTS, SOURCE_DESCRIPTION, PHOTO). 18 is the count; docblock + test assertions updated to 18 (was drafted as 17 during my first pass)."
  - "GDPR subject_email in activity_log PLAINTEXT (accept disposition T-04-05-04): explicitly permitted per UK ICO regulator-query guidance — the audit row is admin-only; the broader PII surface on Contact + Deal is already scrubbed."
  - "Filament header action vs page-level action: EraseCustomerAction is a TABLE header action (via ->headerActions on Table), not a Page-level action. Test uses callTableAction, not callAction."
  - "Deptrac negative test mirror of Phase 2/3 — plants `__CrmDeptracViolator.php` importing `App\\Domain\\Pricing\\Services\\PriceCalculator` (real Phase 3 class NOT in CRM's allow-list). @unlink BEFORE the expect() call, per Phase 2 Plan 05 lesson about Symfony\\Process stdout unreliability on Windows PHP."

patterns-established:
  - "3-mode operator CLI via flag-priority: --adopt-legacy-deal-ids > --live > dry-run (default). Extends Phase 2/3 2-mode pattern. Useful template for future pre-cutover runway-clearing commands."
  - "Pre-cutover legacy-adoption via post_meta scan: the --adopt-legacy-deal-ids walk uses the WooClient REST read path (not direct DB); reads each order's meta_data[] for a specific key; calls BitrixClient::dealUpdate to set the new dedup key; records BitrixEntityMap with created_via='adopted_legacy'. Phase 7 cutover runbook template."
  - "Indefinite-retention audit tables: separate table with NO prune entry + dedicated policy denying mutations + Pest test that plants an ancient row and runs ALL prune commands. Pattern survives future prune-command additions (any new prune breaks the retention test → developer must add exclusion or explicit indefinite-retention docblock)."
  - "Dual-entry-point CLI + Filament action: both dispatch the same queued job + service + audit trail. Regulator auditability never asks 'which path was used?'."
  - "WP-side artefacts as `docs/wordpress-snippets/`: repo-shipped but NOT auto-deployed. The README documents 3 deploy options (mu-plugin / theme footer / GTM) so the WP team picks per their ops preference. Bitrix-side UF_CRM_* custom fields cross-referenced in the README so deploy order is unambiguous (run bitrix:bootstrap BEFORE enabling the JS)."

requirements-completed: [CRM-10, CRM-12, CRM-13]

# Metrics
duration: ~40min
completed: 2026-04-19
---

# Phase 4 Plan 05: Backfill + GDPR Guardrails Summary

**`bitrix:backfill-orders` (3 modes including Pitfall-5 `--adopt-legacy-deal-ids`) + `gdpr:erase-bitrix-customer` with 18-field Contact scrub-in-place + HMRC-preserving Deal scrub + indefinite-retention `gdpr_erasure_log` + Filament EraseCustomerAction + DeptracCrmLayerTest + `docs/wordpress-snippets/` for the WP team + 04-VERIFICATION.md ship verdict PASS for Phase 4.**

## Performance

- **Duration:** ~40 min (3 tasks — Task 1 backfill, Task 2 GDPR, Task 3 Deptrac + WP + verification)
- **Started:** 2026-04-19T17:04:40Z
- **Completed:** 2026-04-19T17:44:59Z
- **Tasks:** 3 (all TDD-adjacent — tests written alongside implementation)
- **Files created:** 23 (13 production + 8 tests + 3 WP-deploy doc files + migration + factory + 04-VERIFICATION)
- **Files modified:** 4 (CrmPushLogResource, AppServiceProvider, PolicyTemplateIntegrityTest, Phase02DataModelTest)
- **Tests added:** 34 (across 7 new Feature test files + 1 Architecture test file)
- **Full suite:** **596 passed, 2 skipped, 0 failed (5406 assertions)**

## Accomplishments

- **Pitfall 5 runway is clear** — `bitrix:backfill-orders --since=2026-01-01 --adopt-legacy-deal-ids` reads Woo orders with `_wc_bitrix24_deal_id` post_meta, writes `UF_CRM_WOO_ORDER_ID` onto the legacy Bitrix Deal, records a `BitrixEntityMap` row with `created_via='adopted_legacy'`. Idempotent across re-runs. Phase 7 cutover can now safely switch Bitrix ownership from the itgalaxy plugin to Laravel without creating duplicate Deals.
- **3-mode backfill CLI shipped** — dry-run (CRM_WRITE_ENABLED forced false, writes sync_diffs.provider='bitrix' rows) / live (real pushes, BitrixEntityMap UNIQUE stops duplicates) / adopt-legacy. `--since` REQUIRED; 50-chunk pagination; 600ms inter-page sleep; T-04-05-02 concurrent-run guard blocks a fresh run while another is in progress (< 1h old); >10% failure-rate triggers exit 1.
- **GDPR erasure satisfies CRM-13** — `gdpr:erase-bitrix-customer --email=addr` requires typing `ERASE` to confirm; dispatches `EraseBitrixContactJob` on `default` queue; `GdprEraser` scrubs 18 Contact PII fields + 4 Deal PII fields (`TITLE` / `COMMENTS` / `SOURCE_DESCRIPTION` / `ADDITIONAL_INFO`); preserves `OPPORTUNITY` / `UF_CRM_WOO_ORDER_ID` / `STAGE_ID` / `CATEGORY_ID` / `BEGINDATE` / `CLOSEDATE` / `CURRENCY_ID` / `COMPANY_ID` / `CONTACT_ID` for HMRC retention. Writes ONE `gdpr_erasure_log` row (indefinite retention) + ONE `activity_log` entry via `Auditor::record('gdpr_erasure', …)` with plaintext `subject_email` for regulator queries.
- **Dual-entry-point erasure locked in** — CLI + Filament `EraseCustomerAction` (header action on `CrmPushLogResource`) both dispatch the same queued job. Admin-only via `->authorize()`; typed `ERASE` via Filament `in:ERASE` validation rule. Non-admins see no button AND get 403 at the POST level (Warning 9 defence-in-depth).
- **Indefinite-retention `gdpr_erasure_log` pattern shipped** — separate table, admin read-only policy, append-only from code (no create/update/delete via UI). `GdprErasureRetentionTest` plants a 5-year-old row + runs ALL 4 prune commands + asserts the row survives. Future prune-command additions that fail this test force the developer to add an explicit exclusion or document the change.
- **Deptrac CRM layer pinned** — `DeptracCrmLayerTest` 2 tests (positive + negative with cleanup-before-assertion). CRM allow-list `[Foundation, Sync, Alerting, Webhooks, Suggestions]` verified; planting `App\Domain\Pricing\Services\PriceCalculator` inside `app/Domain/CRM/Services/__CrmDeptracViolator.php` trips deptrac exit-non-zero.
- **WP team deliverables shipped** — `docs/wordpress-snippets/ms-utm-capture.js` (30 lines, first-touch UTM cookie + hidden checkout inputs) + `ms-utm-persist.php` (`woocommerce_checkout_create_order` hook copies POST values to order meta) + `README.md` documenting 3 deploy options (mu-plugin / theme footer / GTM) + the Bitrix-side UF_CRM_* field cross-reference.
- **04-VERIFICATION.md ship verdict PASS** — per-criterion evidence for all 6 ROADMAP success criteria, 13 CRM-* requirement IDs, 12 D-* decisions, 11 threat-model mitigations (T-04-02-01 through T-04-05-06), deferred-items exclusion check confirming 12 out-of-scope items absent from code.
- **Phase 4 total tests:** 596 passed (+34 over 562 end-of-plan-04-04) / 2 skipped / 0 failed / 5406 assertions. Deptrac: 0 violations, 0 warnings, 0 errors.

## Task Commits

1. **Task 1: bitrix:backfill-orders command + chunk job + progress tracker** — `9ebe762` (feat)
2. **Task 2: GDPR erasure CLI + Filament action + GdprEraser + indefinite-retention log** — `db90e52` (feat)
3. **Task 3: DeptracCrmLayerTest + WP snippets + 04-VERIFICATION.md** — `bde76e9` (test)

## Files Created/Modified

See frontmatter `key-files`. Highlights:

- `app/Domain/CRM/Console/Commands/BitrixBackfillOrdersCommand.php` — 250+ lines. 3 modes via flag-priority, --since required, T-04-05-02 concurrent-run guard, surgical --only-order-id path, per-page sleep-ms pacing, >10% failure-rate exit-1.
- `app/Domain/CRM/Jobs/BackfillOrdersChunkJob.php` — 200+ lines. sync-bulk queue (Pitfall 7), config override for dry-run, per-order failure handling, live-mode idempotency short-circuit via BitrixEntityMap check, adopt-legacy path via meta_data[] scan + dealUpdate + map row with created_via='adopted_legacy'.
- `app/Domain/CRM/Services/GdprEraser.php` — 180+ lines. 18 Contact PII fields + 4 Deal fields scrub constants; token substitution `REDACTED-{hash12}`; dealList(CONTACT_ID) covers Deals without BitrixEntityMap rows; writes gdpr_erasure_log + activity_log with retention_note.
- `app/Domain/CRM/Filament/Actions/EraseCustomerAction.php` — 80 lines. Admin-gated via ->authorize(); typed `ERASE` via `rule('in:ERASE')` + `validationMessages`; dispatches EraseBitrixContactJob with auth()->id() + correlation_id.
- `database/migrations/2026_04_20_100000_create_gdpr_erasure_log_table.php` — email_hash indexed for reverse lookup; status enum ('applied'/'no_match'/'failed'); deal_bitrix_ids JSON; never pruned.
- `tests/Architecture/DeptracCrmLayerTest.php` — 120 lines. Positive + negative with cleanup-before-assertion. Pattern lifted from DeptracSyncLayerTest + DeptracPricingLayerTest.

## Decisions Made

See frontmatter `key-decisions`. Most consequential:

- **Config override, not job param, for dry-run mode:** rather than add a `$forceShadow` bool to PushOrderToBitrixJob's constructor (plan's original proposal), the chunk job calls `config(['services.bitrix.write_enabled' => false])` at the top of handle() when mode='dry-run'. Scoped to the sync-bulk worker process; BitrixClient's existing shadowIfDisabled() first-statement check intercepts. Simpler and preserves PushOrderToBitrixJob's public signature.
- **Synchronous dispatch via dispatchSync** from BackfillOrdersChunkJob: the chunk already runs on sync-bulk with a 300s timeout — no reason to push another queue indirection onto crm-bitrix and contend with live webhook traffic.
- **Filament confirmation uses `rule('in:ERASE')`:** first attempt used a custom `function ($attribute, $value, $fail)` Closure — Filament's evaluator tried to DI-resolve `$attribute` and threw BindingResolutionException. Switched to stock Laravel `in:ERASE` rule + `validationMessages(['in' => 'ERASE literal required'])`.
- **18 Contact PII fields (not 17):** the plan's `<interfaces>` block literally defines 18 keys including PHOTO. My first-pass docblock + test assertions said 17; both corrected to 18.
- **EraseCustomerAction is a Table header action** (not a Page-level action), so its test uses `callTableAction` not `callAction`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] GdprEraseBitrixCustomerCommand registered in AppServiceProvider before Task 2 created the class**

- **Found during:** Task 1, first test run of `BitrixBackfillOrdersCommandTest`.
- **Issue:** I pre-registered both Task 1 (BitrixBackfillOrdersCommand) AND Task 2 (GdprEraseBitrixCustomerCommand) in AppServiceProvider during Task 1 setup. Task 2's class didn't yet exist, so every Task 1 test call that bootstrapped artisan threw `BindingResolutionException: Target class [App\Domain\CRM\Console\Commands\GdprEraseBitrixCustomerCommand] does not exist`.
- **Fix:** Temporarily commented out the Task 2 registration line; restored it in Task 2 once the command class was created.
- **Files modified:** `app/Providers/AppServiceProvider.php` (both Task 1 + Task 2 commits reflect this)
- **Verification:** Task 1's 9 tests all pass; Task 2's registration restoration was included in the Task 2 commit.
- **Committed in:** `9ebe762` (Task 1) + `db90e52` (Task 2).

**2. [Rule 1 - Bug] First-pass GdprEraser docblock + test asserted 17 Contact PII fields (correct count is 18)**

- **Found during:** Task 2, first run of `GdprEraserTest`.
- **Issue:** The plan `<interfaces>` block's `CONTACT_SCRUB_FIELDS` constant literally lists 18 keys (NAME, LAST_NAME, SECOND_NAME, PHONE, EMAIL, WEB, IM, ADDRESS, ADDRESS_2, ADDRESS_CITY, ADDRESS_POSTAL_CODE, ADDRESS_REGION, ADDRESS_PROVINCE, POST, BIRTHDATE, COMMENTS, SOURCE_DESCRIPTION, PHOTO). My first-pass docblock said "17 PII fields" and the assertion used `->toHaveCount(17)`. Both failed.
- **Fix:** Updated GdprEraser.php docblock + const comment + the test assertion to 18. Test count `18 + 4 = 22` for 1 linked Deal.
- **Files modified:** `app/Domain/CRM/Services/GdprEraser.php`, `tests/Feature/CRM/GdprEraserTest.php`
- **Verification:** 6/6 GdprEraserTest tests pass after fix.
- **Committed in:** `db90e52` (Task 2 commit).

**3. [Rule 3 - Blocking] Filament form `rule()` signature does not accept a custom message string**

- **Found during:** Task 2, first run of `EraseCustomerActionTest::it dispatches EraseBitrixContactJob on successful submit`.
- **Issue:** Initial `EraseCustomerAction` used `->rule('in:ERASE', 'The confirmation must be exactly ERASE (all caps).')`. Filament's `Field::rule()` second arg is a `Closure|bool` conditional, NOT a message string. Runtime error: `Argument #2 ($condition) must be of type Closure|bool, string given`.
- **Fix:** Switched to `->rule('in:ERASE')->validationMessages(['in' => '...'])`.
- **Files modified:** `app/Domain/CRM/Filament/Actions/EraseCustomerAction.php`
- **Verification:** 3/3 EraseCustomerActionTest tests pass.
- **Committed in:** `db90e52` (Task 2 commit).

**4. [Rule 3 - Blocking] Filament custom-rule Closure failed DI resolution**

- **Found during:** Task 2, first run of `EraseCustomerActionTest::it dispatches EraseBitrixContactJob on successful submit`.
- **Issue:** First-pass confirmationRule() returned `function (string $attribute, mixed $value, Closure $fail)`. Filament's closure evaluator tried to resolve `$attribute` via the DI container and threw `BindingResolutionException: An attempt was made to evaluate a closure for [Filament\Forms\Components\TextInput], but [$attribute] was unresolvable.`
- **Fix:** Replaced with the stock `rule('in:ERASE')` Laravel validation rule (see deviation 3). The private `confirmationRule()` helper is retained for the unit-level rule test (test 2 of EraseCustomerActionTest verifies the contract via Reflection).
- **Files modified:** `app/Domain/CRM/Filament/Actions/EraseCustomerAction.php`
- **Verification:** Test 3 of EraseCustomerActionTest passes.
- **Committed in:** `db90e52` (Task 2 commit, combined with deviation 3).

**5. [Rule 3 - Blocking] sync-diffs:prune does not accept --days flag**

- **Found during:** Task 2, first run of `GdprErasureRetentionTest`.
- **Issue:** My retention test called `artisan('sync-diffs:prune', ['--days' => 30])`. Phase 1 D-08 shipped `PruneSyncDiffsCommand` WITHOUT a --days flag (30-day applied retention is hardcoded post-cutover; command is a no-op while WOO_WRITE_ENABLED=false). Command threw `InvalidOptionException: The "--days" option does not exist.`
- **Fix:** Dropped `--days` arg for `sync-diffs:prune` only; other 3 prune commands keep `--days=30`.
- **Files modified:** `tests/Feature/CRM/GdprErasureRetentionTest.php`
- **Verification:** Retention test passes; 4 prune commands run cleanly and the 5-year-old row survives.
- **Committed in:** `db90e52` (Task 2 commit).

**6. [Rule 2 - Missing Critical] Phase 2 rollback step count drift 19 → 20**

- **Found during:** Task 2, regression check of Phase02DataModelTest.
- **Issue:** Task 2 added `2026_04_20_100000_create_gdpr_erasure_log_table.php`. Phase02DataModelTest's hardcoded rollback step 19 no longer reached the `products` table.
- **Fix:** Bumped to 20 + documented the new migration in the inline comment chain.
- **Files modified:** `tests/Feature/Phase02DataModelTest.php`
- **Verification:** 18/18 Phase02DataModelTest tests pass.
- **Committed in:** `db90e52` (Task 2 commit).

**7. [Rule 2 - Missing Critical] PolicyTemplateIntegrityTest positive-control floor 14 → 16**

- **Found during:** Task 2 regression check.
- **Issue:** Plan 04-04 already raised the floor 9 → 14 for the 5 CRM policies; my Task 2 added `GdprErasureLogEntryPolicy` AND Plan 04-04 added `CrmPushLogPolicy`. Total policy files is now 16 not 14.
- **Fix:** Raised floor to 16; added GdprErasureLogEntry → GdprErasureLogEntryPolicy pair to the Gate-binding test.
- **Files modified:** `tests/Architecture/PolicyTemplateIntegrityTest.php`
- **Verification:** PolicyTemplateIntegrityTest 3/3 tests pass.
- **Committed in:** `db90e52` (Task 2 commit).

---

**Total deviations:** 7 auto-fixed (5× Rule 3 blocking, 2× Rule 2 missing critical, 1× Rule 1 bug). All necessary for correctness + guardrail coverage. No scope creep.

**Impact on plan:** Every auto-fix is either a Filament/Laravel API signature mismatch I discovered at first test run, or a documented Phase 2/4 regression-absorption (migration step count + policy floor). Scope boundary held — zero work drifted into Phase 5 or other plans.

## Issues Encountered

- **Filament + Pest callTableAction vs callAction ambiguity** — table-level header actions (added via `->headerActions()` on a Table builder) require `callTableAction`; page-level actions require `callAction`. First-pass test used `callAction` and got "No action named [...] exists on the page". Documented in deviation — pattern recorded for future Filament action tests.
- **WooClient mock matcher syntax** — WooClient::get signature is `get(string $endpoint, array $query = [])`. The Backfill chunk job calls `$woo->get('orders/'.$orderId)` (single arg) but my test mock used `Mockery::any()` for the second arg. Resolved by shipping two `shouldReceive` overloads per order (with + without second arg).

No Bitrix sandbox calls — all Plan 04-05 tests use Mockery-faked BitrixClient + WooClient.

## Legacy Deal Adoption Stats (Sandbox — Not Run)

**Status: NOT YET RUN against a real Woo/Bitrix tenant.**

Plan 04-05 delivers the `--adopt-legacy-deal-ids` mode + 4 dedicated Pest tests covering:

1. **Successful adoption** — order with `_wc_bitrix24_deal_id` post_meta, bitrix::dealUpdate called once with UF_CRM_WOO_ORDER_ID, BitrixEntityMap row created with created_via='adopted_legacy'.
2. **Skip on missing meta** — order lacks `_wc_bitrix24_deal_id` → skipped_orders incremented, no SDK call, no map row.
3. **Idempotency** — second run over same order with pre-existing map row short-circuits.
4. **Never runs push path in adopt-legacy mode** — PushOrderToBitrixJob never dispatched; Queue::assertNotPushed holds.

Actual runtime stats against the meetingstore.co.uk production Woo database are a deliberate Phase 7 cutover runbook step:

```bash
# 1. Count legacy-tagged orders on the WP side (operator runs on WP shell)
mysql -u root meetingstore -e "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_wc_bitrix24_deal_id';"

# 2. Dry-run pre-check on Laravel side
php artisan bitrix:backfill-orders --since=2023-01-01 --adopt-legacy-deal-ids --dry-run

# 3. Verify the planned count matches then run live
php artisan bitrix:backfill-orders --since=2023-01-01 --adopt-legacy-deal-ids
```

## GDPR Erasure Tested Path

Tested against Mockery-faked BitrixClient:

1. **Happy path:** 1 Contact map row seeded → `eraseByEmail('jane@acme.com')` → contactUpdate(C999, 18-field payload) + dealList(CONTACT_ID=C999) → dealUpdate for each linked Deal → gdpr_erasure_log row + activity_log entry + return `['contact_id' => 'C999', 'deal_ids' => [...], 'fields_scrubbed_count' => 22]`.
2. **No-match path:** unknown email → gdpr_erasure_log row with status='no_match' + activity_log entry + no SDK calls + return `['contact_id' => null, ...]`.
3. **Token substitution:** `REDACTED` literals in scrub constants become `REDACTED-{hash12}` where hash12 = sha256(email)[0:12].
4. **Deal TITLE placeholder:** `'Order #{UF_CRM_WOO_ORDER_ID}'` substituted per-Deal at call time.

Field scrub semantics match the constants verbatim (verified via Mockery payload capture + key-set comparison). Business fields (OPPORTUNITY, STAGE_ID, UF_CRM_WOO_ORDER_ID, CATEGORY_ID, BEGINDATE, CLOSEDATE, CURRENCY_ID, COMPANY_ID, CONTACT_ID) are NOT in any scrub payload — preserved per HMRC retention.

## Deptrac Negative Test Reliability on Windows

The Phase 2 Plan 05 lesson about Symfony\Process + deptrac-shim stdout unreliability on Windows PHP held. The negative test relies on exit code only — violator file `__CrmDeptracViolator.php` is `@unlink`'d before the assertion so a failed test never leaves the planted violator on disk. Test completes in ~2 seconds, exit-non-zero observed. Cleanup worked reliably across all re-runs during development.

## Full-Suite Test Count Delta

- Before Plan 04-05: 562 passed (end of Plan 04-04)
- After Plan 04-05: **596 passed, 2 skipped, 0 failed (5406 assertions)**
- Delta: **+34 tests** across:
  - Task 1 (17 new): BitrixBackfillOrdersCommandTest (9) + AdoptLegacyDealIdsTest (4) + BackfillOrdersChunkJobTest (4)
  - Task 2 (15 new): GdprEraserTest (6) + GdprEraseBitrixCustomerCommandTest (5) + EraseCustomerActionTest (3) + GdprErasureRetentionTest (1)
  - Task 3 (2 new): DeptracCrmLayerTest (2 — positive + negative)

## User Setup Required

None for Plan 04-05 itself. Pre-existing Phase 4 env vars (`BITRIX_WEBHOOK_URL`, `CRM_WRITE_ENABLED`, `BITRIX_SMOKE_TEST_ALLOWED`, `BITRIX_CACHE_TTL_HOURS`, `BITRIX_PUSH_RETRY_ATTEMPTS`) still govern operator setup for live Bitrix writes.

Phase 7 cutover-time operator actions (documented in 04-VERIFICATION.md):

1. Walk Plan 04-04's 7-point visual checkpoint against a sandbox Bitrix tenant.
2. Run `bitrix:bootstrap` (creates UF_CRM_WOO_* fields if missing).
3. Run `bitrix:smoke-test` with `BITRIX_SMOKE_TEST_ALLOWED=true` — verifies SDK surface.
4. Run `bitrix:backfill-orders --since={date} --adopt-legacy-deal-ids --dry-run` — verifies count of legacy-tagged Woo orders.
5. Run `bitrix:backfill-orders --since={date} --adopt-legacy-deal-ids` (live) — one-shot adopt pass.
6. Deploy `docs/wordpress-snippets/` files to meetingstore.co.uk via the WP team's preferred method.
7. Flip `CRM_WRITE_ENABLED=true`.

## Next Phase Readiness

- **Phase 5 (Competitor module) is unblocked** — no dependency on Phase 4 code. Can start immediately. The Competitor module will produce pricing-suggestion rows; the Phase 1 suggestions seam is already producing real `crm_push_failed` rows from Plan 04-03 so the applier/replay path has been exercised in anger.
- **Phase 7 cutover tooling is complete** — the 3 cutover-critical CLI commands (`bitrix:bootstrap`, `bitrix:smoke-test`, `bitrix:backfill-orders --adopt-legacy-deal-ids`) + the GDPR dual-entry-point + the Filament admin surfaces (field-mapping, status-mapping, pipeline-settings, push-log, suggestions-replay) are all shipped. 04-VERIFICATION.md's Operator Handover Notes section is the definitive runbook reference.
- **Architectural boundaries pinned** — Deptrac CRM layer + SYNC-04 WpDirectDb ban + Pricing + CRM allow-lists all defended by positive+negative architecture tests. Future plans that accidentally weaken these rulesets trip the negative test.

**Blockers:** None. Phase 4 ships complete.

## Self-Check: PASSED

All 13 newly-created production + test files exist on disk (confirmed via `git log --stat bde76e9 db90e52 9ebe762`).

All 3 task commits resolvable in git log:
- `9ebe762 feat(04-05): bitrix:backfill-orders command + chunk job + progress tracker (Task 1)`
- `db90e52 feat(04-05): GDPR erasure CLI + Filament action + GdprEraser + indefinite-retention log (Task 2)`
- `bde76e9 test(04-05): DeptracCrmLayerTest + WP snippets + 04-VERIFICATION.md ship verdict (Task 3)`

`php artisan list bitrix` lists all 4 expected commands:
- bitrix:backfill-orders (NEW — this plan)
- bitrix:bootstrap
- bitrix:schema:refresh
- bitrix:smoke-test

`php artisan list gdpr` lists:
- gdpr:erase-bitrix-customer (NEW — this plan)

`ls docs/wordpress-snippets/`:
- README.md
- ms-utm-capture.js
- ms-utm-persist.php

`ls .planning/phases/04-bitrix24-crm-sync/04-VERIFICATION.md` — EXISTS.

Final test suite: **596 passed, 2 skipped, 0 failed (5406 assertions)**. Deptrac: **0 violations, 0 warnings, 0 errors**. PolicyTemplateIntegrityTest 3/3 green (no placeholder leaks).

Phase 4 status: **COMPLETE — ship verdict PASS.**

---
*Phase: 04-bitrix24-crm-sync*
*Plan: 05*
*Completed: 2026-04-19*
