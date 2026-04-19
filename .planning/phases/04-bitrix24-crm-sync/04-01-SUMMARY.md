---
phase: 04-bitrix24-crm-sync
plan: 01
subsystem: crm
tags: [bitrix24, crm, sdk, migrations, eloquent, policies, pitfall-6, dedup-ledger, shadow-mode, sandbox-validation]

# Dependency graph
requires:
  - phase: 01-foundation
    provides: IntegrationLogger (channel='bitrix' routing), Auditor (bitrix.bootstrap audit events), BaseCommand (correlation_id thread), SuggestionPolicy pattern (admin-only hardcoded hasRole), PolicyTemplateIntegrityTest guardrail, Context::hydrated queue bridge
  - phase: 01-foundation
    provides: sync_diffs table (Phase 4 Plan 01 extends with 'provider' column — Shadow-Mode Option A)
  - phase: 02-supplier-sync
    provides: PolicyTemplateIntegrityTest (tests/Architecture) — Plan 04-01 policies automatically covered
  - phase: 03-pricing-engine
    provides: Domain-layer pattern (app/Domain/CRM/Models|Policies|Services|Console mirrors Pricing layout)
provides:
  - 6 migrations: bitrix_entity_map (Pitfall 6 dedup ledger), crm_field_mappings, crm_status_mappings, crm_pipeline_settings (singleton + auto-seed), bitrix_backfill_runs, sync_diffs.provider column
  - 5 Eloquent models under App\Domain\CRM\Models with HasFactory + appropriate LogsActivity traits
  - 5 admin-only hand-written policies (SuggestionPolicy pattern; hardcoded hasRole('admin'))
  - 5 factories with domain-specific state helpers (BitrixEntityMapFactory::dealFor/contactFor/companyFor)
  - BitrixClient skeleton with 18 method signatures (14 throw LogicException awaiting Plan 04-02; 4 userfield CRUD methods fully implemented for bitrix:bootstrap)
  - BitrixTransientException / BitrixPermanentException for D-11 retry-classification (Plan 04-02 populates)
  - bitrix:bootstrap command — idempotent creator of 14 Bitrix UF_CRM_WOO_* custom fields
  - bitrix:smoke-test command — 7-probe sandbox validator with two-layer gate (BITRIX_SMOKE_TEST_ALLOWED + BITRIX_WEBHOOK_URL)
  - CrmStatusMappingSeeder — 7 standard Woo statuses (NEW/PREPAYMENT_INVOICE/EXECUTING/WON + 3× LOSE terminal)
  - config/services.php `bitrix` block + 5 .env.example keys (CRM_WRITE_ENABLED, BITRIX_SMOKE_TEST_ALLOWED, BITRIX_CACHE_TTL_HOURS, BITRIX_PUSH_RETRY_ATTEMPTS, BITRIX_WEBHOOK_URL)
  - bitrix24/b24phpsdk ^1.10.0 composer dependency
affects:
  - 04-02-bitrix-client-wrapper (will populate the 14 LogicException-throwing BitrixClient methods + classify exceptions into BitrixTransient/PermanentException)
  - 04-03-webhook-listeners-push-jobs (will consume BitrixEntityMap for dedup, CrmStatusMapping::stageIdForStatus for D-06 stage transitions)
  - 04-04-filament-ui (CrmFieldMappingResource / CrmStatusMappingResource / CrmPipelineSettingsPage bind against these 5 models + policies)
  - 04-05-backfill-gdpr-guardrails (BitrixBackfillRun tracks progress; bitrix_entity_map.created_via='adopted_legacy' for Pitfall 5)
  - 07-dashboard-polish-cutover (sync_diffs.provider filter drives the divergence-scan UI)

# Tech tracking
tech-stack:
  added: [bitrix24/b24phpsdk ^1.10.0]
  patterns:
    - "SDK-agnostic wrapper pattern (BitrixClient hides SDK surface; mesilov/* fallback swappable via constructor)"
    - "Skeleton-first push-body-later — Plan 04-01 ships all 18 method signatures so Plan 04-03 listeners can type-hint against concrete contracts before Plan 04-02 writes the bodies"
    - "Two-layer production guard — env flag (BITRIX_SMOKE_TEST_ALLOWED) + dependency (BITRIX_WEBHOOK_URL) both required for smoke-test to run"
    - "Container-bound probe runner test seam — BitrixSmokeTestCommand::PROBE_RUNNER_KEY lets tests inject a fake probe without mocking the SDK"
    - "Shadow-Mode Option A (RESEARCH): sync_diffs.provider column reuses the Phase 1 shadow table instead of a new crm_push_shadow table"

key-files:
  created:
    - app/Domain/CRM/Services/BitrixClient.php (18 methods; 4 real bodies + 14 LogicException stubs)
    - app/Domain/CRM/Console/Commands/BitrixBootstrapCommand.php (idempotent 14-field creator)
    - app/Domain/CRM/Console/Commands/BitrixSmokeTestCommand.php (7-probe sandbox validator)
    - app/Domain/CRM/Exceptions/BitrixTransientException.php + BitrixPermanentException.php
    - app/Domain/CRM/Models/BitrixEntityMap.php (Pitfall 6 dedup ledger)
    - app/Domain/CRM/Models/CrmFieldMapping.php
    - app/Domain/CRM/Models/CrmStatusMapping.php
    - app/Domain/CRM/Models/CrmPipelineSetting.php (singleton)
    - app/Domain/CRM/Models/BitrixBackfillRun.php
    - app/Domain/CRM/Policies/* (5 admin-only hand-written policies)
    - database/factories/Domain/CRM/* (5 factories)
    - database/migrations/2026_04_20_080000..080500 (6 migrations)
    - database/seeders/Phase4/CrmStatusMappingSeeder.php
    - tests/Feature/CRM/* (4 test files, 16 tests, 52 assertions)
  modified:
    - composer.json + composer.lock (bitrix24/b24phpsdk ^1.10.0)
    - config/services.php (bitrix block)
    - .env.example (5 BITRIX_* keys + guidance comments)
    - app/Domain/Sync/Models/SyncDiff.php (provider added to fillable)
    - app/Providers/AppServiceProvider.php (5 Gate::policy bindings + 2 command registrations)
    - database/seeders/DatabaseSeeder.php (calls Phase4\CrmStatusMappingSeeder)
    - tests/Architecture/PolicyTemplateIntegrityTest.php (scans Domain/CRM/Policies; 14 Gate pairs; floor 9→14)
    - tests/Feature/Phase02DataModelTest.php (round-trip rollback step 11→17 + Phase 4 assertions)

key-decisions:
  - "SDK pin: bitrix24/b24phpsdk ^1.10.0 — resolved to 1.10.0 (latest published 1.10.x). Packagist only publishes 1.10.0 (not 1.10.1+ as plan truth wrote); ^1.10.0 permits future 1.10.x + 1.11.x but NOT 2.x. 3.x PHP-8.4-floor avoided."
  - "BitrixClient un-finalled (Phase 2 WooClient precedent) so Mockery can mock the concrete class in BitrixBootstrapCommandTest without extracting an interface"
  - "Sandbox validation INSIDE the app via bitrix:smoke-test rather than ad-hoc tinker script — probe results land in integration_events + structured audit row; ops can re-run safely with BITRIX_SMOKE_TEST_ALLOWED flag"
  - "integration_events.operation + status required (Phase 1 schema) — every BitrixClient + smoke-test log call fills operation=<endpoint> + status='success'|'failed' (caught at first RED run)"
  - "migration timestamp slot 2026_04_20_08* (not 19 to preserve Phase 3 ordering; Plan 04-01 Task 2 consumes 080000..080500)"

patterns-established:
  - "BitrixClient sdk() lazy-init — service builder built on first write/read, not in constructor (so the constructor doesn't fail when config is empty during boot)"
  - "PROBE_RUNNER_KEY container binding — test seam for artisan commands that want to exercise the command's loop WITHOUT mocking an injected service"
  - "Phase4 seeder namespace (Database\\Seeders\\Phase4\\*) — parallels Phase3 subdirectory convention"
  - "BitrixEntityMap factory state-method convention (dealFor / contactFor / companyFor) — Plan 04-03+ push job tests will reuse these"

requirements-completed: [CRM-01, CRM-02]

# Metrics
duration: ~95min
completed: 2026-04-19
---

# Phase 4 Plan 01: Data Model + SDK Bootstrap Summary

**Bitrix24 SDK 1.10.0 pinned + 6 migrations (Pitfall 6 dedup ledger + 4 CRM tables + sync_diffs.provider) + 5 Eloquent models with admin-only policies + bitrix:bootstrap (idempotent UF_CRM_WOO_* creator) + bitrix:smoke-test (7-probe sandbox validator with two-layer gate)**

## Performance

- **Duration:** ~95 min (3 tasks + 1 auto-fix)
- **Started:** 2026-04-19T12:05:34Z
- **Completed:** 2026-04-19T13:42:00Z
- **Tasks:** 3 (all tdd="true" pattern)
- **Files modified:** 30 (22 created, 8 edited)
- **Tests added:** 16 (52 assertions)

## Accomplishments

- **Pitfall 6 dedup ledger is live** — `bitrix_entity_map` table shipped with UNIQUE(entity_type, woo_id), VARCHAR(64) bitrix_id (Pitfall 3), indexed email_hash for GDPR, last_status_snapshot for D-09 stage-change guard. Plan 04-03 can query this ledger on day one.
- **SDK pin holds against PHP-8.4-floor 3.x line** — `composer require bitrix24/b24phpsdk:"^1.10"` originally resolved to 1.10.0 but composer warned "too strict"; widened to `^1.10.0` with `--with-all-dependencies` (symfony/event-dispatcher v8 → v6|v7) and `--ignore-platform-req=ext-pcntl,ext-posix` for Herd-on-Windows dev parity. 14 transitive deps added, including symfony/psr-http-message-bridge, guzzlehttp/psr7.
- **bitrix:bootstrap is deploy-safe on day one** — idempotent; fails hard if webhook URL empty OR userfield.list throws (Pitfall 6 auth-broken guard); audits every run to activity_log with created/skipped counts + dry_run flag.
- **bitrix:smoke-test collapses 3 MEDIUM-confidence SDK assumptions before Plan 04-02 writes wrapper bodies** — 7 probes cover dealUserfieldList / dealFieldsGet / contactFieldsGet / companyFieldsGet / dealList UF filter / duplicateFindByComm EMAIL / PHONE. Each emits one `integration_events` row with channel='bitrix' + shared correlation_id. Two-layer gate (BITRIX_SMOKE_TEST_ALLOWED=false default + BITRIX_WEBHOOK_URL required) keeps the command firmly off production by default.
- **Shadow-Mode Option A locked in** — `sync_diffs.provider` column (default 'woo', indexed) means Plan 04-02 reuses the Phase 1 shadow table instead of inventing a new one. Phase 7 divergence-scan filter is cheap.
- **Phase 1 PolicyTemplateIntegrityTest extended for free** — scan path now includes `app/Domain/CRM/Policies`, Gate-pair assertion covers 14 policies (was 9), positive-control floor raised 9→14. If a future shield:generate run leaks `{{ Placeholder }}` into any of the 5 new CRM policies, CI is red in <2 seconds.

## Task Commits

Each task was committed atomically (not TDD-split because the migration/model dependencies required matched ordering):

1. **Task 1: SDK install + config + smoke-test command** — `3346608` (feat)
2. **Task 2: Migrations + models + policies + factories + seeder** — `9a67cd2` (feat)
3. **Task 3: bitrix:bootstrap tests + Phase 2 round-trip fix** — `7f6cf58` (test + auto-fix Rule 2)

## Files Created/Modified

See frontmatter `key-files` for the authoritative list. Highlights:

- `app/Domain/CRM/Services/BitrixClient.php` — 293 lines. 18 methods total, 4 real-bodied (dealUserfieldList/Add + contactUserfieldList/Add via sdk()->getCRMScope()->dealUserfield()/contactUserfield() chain); 14 stubs throwing LogicException with "Plan 04-02" marker.
- `app/Domain/CRM/Console/Commands/BitrixBootstrapCommand.php` — 179 lines. Creates 7 Deal fields + 7 Contact fields; fails hard on auth-broken; audits every run.
- `app/Domain/CRM/Console/Commands/BitrixSmokeTestCommand.php` — 143 lines. 7 probe definitions; container-bound probe runner test seam.
- `database/migrations/2026_04_20_080000_create_bitrix_entity_map_table.php` — Pitfall 6 schema: UNIQUE(entity_type, woo_id) + 4 secondary indexes on email_hash, last_pushed_at, (entity_type, bitrix_id), last_correlation_id.
- `tests/Architecture/PolicyTemplateIntegrityTest.php` — Extended to include Domain/CRM/Policies path + 5 new Gate pairs. Floor 9→14.

## Decisions Made

- **SDK version resolution — 1.10.0 not 1.10.1+:** Plan truth-list wrote "1.10.x where x >= 1"; Packagist as of 2026-04-19 publishes only 1.10.0 as the latest 1.10.x patch. `^1.10.0` constraint pin will auto-upgrade to 1.10.1/1.10.2/1.11.x when released and stop at 2.x. Noted in composer show output: `versions : * 1.10.0`.
- **Constraint `^1.10.0` (not `^1.10`):** Composer emitted warning "The '1.10' constraint for bitrix24/b24phpsdk appears too strict"; widening to `^1.10.0` is semver-compliant, future-compat, still blocks 3.x PHP-8.4 line.
- **Un-final BitrixClient** for Mockery parity with Phase 2 WooClient precedent. Not extracting an interface — Plan 04-02 can retrospectively extract one if multiple implementations are needed.
- **smoke-test probe indirection via container key** not Mockery: the command must be testable without hitting Bitrix OR mocking a ServiceBuilder (SDK `ServiceBuilderFactory::createServiceBuilderFromWebhook` returns a concrete `ServiceBuilder` that's complex to fake). Binding a closure under `BitrixSmokeTestCommand::PROBE_RUNNER_KEY` is minimum-invasive.
- **Probe success shape:** probe runner returns `['ok' => true, 'count' => $count]`; test fakes return this shape; command logs one success row per probe. Simpler than forcing a Mockery expectation on each of 4 different SDK sub-services.
- **Singleton crm_pipeline_settings row created in migration** (not seeder) so `CrmPipelineSetting::current()` resolves cleanly even when a deploy skips seeders.
- **sync_diffs.provider default 'woo' + no explicit UPDATE:** MySQL column default handles backfill of existing Phase 1/2 rows; no UPDATE statement needed (saves migration time on prod).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Composer constraint `^1.10` too strict; `ext-pcntl/ext-posix` missing on Herd-for-Windows dev**
- **Found during:** Task 1 (SDK install)
- **Issue:** `composer require bitrix24/b24phpsdk:^1.10` failed because (a) constraint string was literal "1.10" (dropped caret in composer parse), (b) symfony/event-dispatcher v8 was locked (Horizon dep chain); (c) Horizon itself requires pcntl + posix which Windows PHP doesn't ship.
- **Fix:** Switch to `^1.10.0` (explicit patch) + `--with-all-dependencies` (downgrade symfony/event-dispatcher) + `--ignore-platform-req=ext-pcntl` + `--ignore-platform-req=ext-posix` (Phase 1 precedent for Horizon-on-Windows dev).
- **Files modified:** composer.json, composer.lock
- **Verification:** `composer show bitrix24/b24phpsdk` prints `versions : * 1.10.0`; vendor/bitrix24/b24phpsdk/src exists.
- **Committed in:** 3346608 (Task 1 commit)

**2. [Rule 2 - Missing Critical] `integration_events.operation` and `.status` are NOT-NULL**
- **Found during:** Task 1 (first RED→GREEN run of BitrixSmokeTestCommandTest)
- **Issue:** Plan's IntegrationLogger payload example omitted `operation` + `status` fields; SQLSTATE 22001 error at first test run revealed the schema requires them.
- **Fix:** Added `operation` (mirrors `endpoint` by default) + `status` ('success'|'failed') to every log call in BitrixClient.php + BitrixSmokeTestCommand.php + test fixture.
- **Files modified:** app/Domain/CRM/Services/BitrixClient.php, app/Domain/CRM/Console/Commands/BitrixSmokeTestCommand.php, tests/Feature/CRM/BitrixSmokeTestCommandTest.php
- **Verification:** 3 smoke-test Pest tests green.
- **Committed in:** 3346608 (Task 1 commit)

**3. [Rule 3 - Blocking] BitrixClient `final` keyword prevents Mockery mocking**
- **Found during:** Task 3 (first RED run of BitrixBootstrapCommandTest)
- **Issue:** `Mockery::mock(BitrixClient::class)` throws "marked final and its methods cannot be replaced" because Task 1 shipped the class as `final`. BitrixBootstrapCommand constructor-injects the client, so tests need to fake it.
- **Fix:** Drop `final` (Phase 2 P02 WooClient precedent). Class docblock documents: "Not marked final — Phase 2 P02 WooClient precedent; keeping open allows Mockery fakes + Plan 04-02's rate-limit subclass test seam without extracting a contract interface."
- **Files modified:** app/Domain/CRM/Services/BitrixClient.php
- **Verification:** BitrixBootstrapCommandTest 5/5 green.
- **Committed in:** 7f6cf58 (Task 3 commit)

**4. [Rule 2 - Missing Critical] Phase 2 round-trip rollback test step count drifted 11→17**
- **Found during:** Full-suite post-Task 3 verification
- **Issue:** `tests/Feature/Phase02DataModelTest.php` hardcodes `migrate:rollback --step 11`; Plan 04-01 Task 2 added 6 new migrations, making step 11 no longer reach products table. Phase 2 round-trip test went red.
- **Fix:** Update step count to 17 + extend rollback-assertions + re-migrate-assertions to cover the 5 new Phase 4 tables. Updated inline comment with new migration list + step-count formula.
- **Files modified:** tests/Feature/Phase02DataModelTest.php
- **Verification:** Phase02DataModelTest "rolls back the 6 Phase-2 migrations + re-migrates cleanly (round-trip)" — 1 passed (28 assertions); full suite 443 passed / 0 failed.
- **Committed in:** 7f6cf58 (Task 3 commit)

**5. [Rule 2 - Missing Critical] PolicyTemplateIntegrityTest did NOT scan Domain/CRM/Policies**
- **Found during:** Task 2 (after shipping 5 new policies)
- **Issue:** Phase 2's PolicyTemplateIntegrityTest (promoted to tests/Architecture) hardcodes a path list that does NOT include `app/Domain/CRM/Policies`. Without this, the Phase 4 policies would never be checked for Shield `{{ Placeholder }}` leaks — defeating the guardrail's whole purpose.
- **Fix:** Add `app_path('Domain/CRM/Policies')` to both the placeholder-literal scanner AND the positive-control glob. Add the 5 CRM Model→Policy pairs to the Gate::policy binding test. Raise positive-control floor 9→14.
- **Files modified:** tests/Architecture/PolicyTemplateIntegrityTest.php
- **Verification:** PolicyTemplateIntegrityTest still passes (16 assertions, was 11) — now covers every new CRM policy automatically.
- **Committed in:** 9a67cd2 (Task 2 commit)

---

**Total deviations:** 5 auto-fixed (2× Rule 3 blocking, 3× Rule 2 missing critical). All necessary for correctness + guardrail coverage. No scope creep into Plan 04-02 body work.

## Smoke-Test Sandbox Validation

**Status: NOT YET RUN against a real Bitrix tenant.**

Plan 04-01 delivers the command + 3 unit-level tests (blocked / 7-probe / fail-on-throw). The actual sandbox-validation probe against a configured Bitrix tenant is a deliberate operator step — the plan's "Manual" verification item. When Plan 04-02 kicks off:

1. Operator provisions BITRIX_WEBHOOK_URL in the dev sandbox .env
2. Opts in via `BITRIX_SMOKE_TEST_ALLOWED=true`
3. Runs `php artisan bitrix:smoke-test`
4. Inspects the output table — any failing probe is a Plan 04-02 wrapper-interface adjustment input

Until that run happens, Plan 04-02 works from the SDK's documented README shape with the `sdk()->getCRMScope()->dealUserfield()/contactUserfield()` chain this plan's userfield methods have already exercised.

**Fallback flag (mesilov/bitrix24-php-sdk ^2.0):** NOT needed. Official SDK installed cleanly. No divergence to report.

## Issues Encountered

- **Composer constraint parsing edge case** — `"^1.10"` vs `"1.10"` behaviour divergence caught at first install attempt; documented in deviation #1 so Plan 04-02 operators can copy the correct syntax.

## User Setup Required

- **BITRIX_WEBHOOK_URL** must be populated in `.env` before running `bitrix:bootstrap` or `bitrix:smoke-test`.
- **BITRIX_SMOKE_TEST_ALLOWED=true** must be explicitly set to run the smoke-test command (default false is the production guard).
- Neither command is auto-invoked by migrate/seed — operator action required. Documented in `.env.example` with inline comments.

## Next Phase Readiness

- **Plan 04-02 is unblocked** — 14 BitrixClient methods throw LogicException with the exact Plan 04-02 marker; the SDK is installed; the shadow-mode column exists; the dedup ledger is ready for EntityDeduper to consult.
- **Plan 04-03 listener design ready** — domain events will subscribe to Phase 1 `OrderReceived` + `CustomerRegistered`; push jobs consult `BitrixEntityMap::forWooOrder(id)` / `::forWooCustomer(id)` / `CrmStatusMapping::stageIdForStatus($status)`.
- **Plan 04-04 Filament resources ready** — 5 models + policies + LogsActivity trait means CrmFieldMappingResource / CrmStatusMappingResource / CrmPipelineSettingsPage each have working bindings.
- **Plan 04-05 backfill has progress table + adopted_legacy enum value ready** (`BitrixBackfillRun::MODE_ADOPT_LEGACY` + `BitrixEntityMap::VIA_ADOPTED_LEGACY`).

**Blockers:** None. BITRIX_WEBHOOK_URL provisioning is an operator step that happens before the first Plan 04-02 live run; it does NOT block Plan 04-02 code work.

## Self-Check: PASSED

All 10 sampled key files present on disk; all 3 task commits resolvable in `git log`:
- `3346608 feat(04-01): install Bitrix SDK ^1.10 + smoke-test command (Task 1)`
- `9a67cd2 feat(04-01): CRM data model (...) (Task 2)`
- `7f6cf58 test(04-01): bitrix:bootstrap command tests + Phase 2 round-trip fix (Task 3)`

Final test suite: **443 passed, 2 skipped, 0 failed (4754 assertions)**. Deptrac: **0 violations, 0 warnings**.

---
*Phase: 04-bitrix24-crm-sync*
*Plan: 01*
*Completed: 2026-04-19*
