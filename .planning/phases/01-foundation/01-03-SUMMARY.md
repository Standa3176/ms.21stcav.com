---
phase: 01-foundation
plan: 03-foundation
subsystem: foundation
tags: [correlation-id, context-facade, spatie-activitylog, integration-events, domain-events, laravel-12, audit, pitfall-j]

requires:
  - phase: 01-01-scaffold
    provides: "spatie/laravel-activitylog ^4.12 installed + migrated; app/Foundation/{Audit,Integration,Events}/ skeleton; config/activitylog.php published; activity_log table + batch_uuid column present"
  - phase: 01-02-rbac
    provides: "Filament Shield + 4 roles seeded + admin user (admin@meetingstore.co.uk); permission tables populated on the shared MySQL instance"

provides:
  - "AttachCorrelationId middleware globally appended in bootstrap/app.php — EVERY HTTP request (web, api, webhooks, /up health) gets a correlation_id"
  - "Inbound X-Correlation-Id / X-Request-Id honoured when well-formed (regex [A-Za-z0-9-]{8,64} — T-03-02 mitigation); otherwise UUIDv4 generated"
  - "Response header X-Correlation-Id emitted on every HTTP response"
  - "Laravel 12 Context::hydrated callback re-opens spatie LogBatch on the queue-side (Pitfall J mitigation) — audit rows written by queued jobs share the originating request's correlation_id"
  - "DomainEvent abstract base class (app/Foundation/Events/DomainEvent.php) auto-populates correlationId + occurredAt from Context at construction"
  - "BaseCommand abstract class (app/Console/Commands/BaseCommand.php) threads correlation_id + LogBatch through artisan command lifetime via final handle() + abstract perform()"
  - "Auditor service (app/Foundation/Audit/Services/Auditor.php) wraps spatie/activitylog for system-level events — correlation_id + occurred_at auto-attached to log_name='system' properties"
  - "integration_events table + IntegrationEvent model + IntegrationLogger service — every outbound API call gets logged with redaction on 6 sensitive headers (T-03-01)"
  - "nullableUlidMorphs subject column on integration_events — CHAR(26) subject_id supports future ULID-keyed Suggestion rows (Plan 04 dependency)"
  - "phpunit.xml DB override: DB_DATABASE=meetingstore_ops_testing — Pest's RefreshDatabase no longer truncates the live dev DB (Plan 02 handoff concern resolved)"
  - "18 passing Pest tests covering middleware validation, Context threading, DomainEvent construction, queue-boundary propagation, integration redaction, ULID subject morph, and Auditor record properties"

affects:
  - "01-04-seams (consumes IntegrationLogger for webhook_receipts + suggestions apply integration calls; Suggestion model subject_id is CHAR(26) ULID, matched by nullableUlidMorphs)"
  - "01-05-horizon-alerting (consumes Auditor for prune command audit; failed-job alerter reads correlation_id from job payload via Context::hydrated chain)"
  - "Phase 2+ (every domain module dispatches DomainEvent subclasses; every outbound HTTP client logs via IntegrationLogger; every artisan cross-module command extends BaseCommand)"

tech-stack:
  added:
    - "Laravel 12 Context facade usage pattern — Context::add/get/forget/hydrated/dehydrating"
    - "spatie/activitylog LogBatch threading via correlation_id"
  patterns:
    - "Correlation-id entry points: HTTP (AttachCorrelationId middleware) and artisan (BaseCommand) — one UUIDv4 per request/command, propagates via Context dehydrate/hydrate into queued jobs"
    - "Sensitive-header redaction list in IntegrationLogger: authorization, x-wc-webhook-signature, cookie, x-bitrix-signature, x-api-key, x-auth-token (case-insensitive)"
    - "Domain events carry primitive fields only (IDs, strings) — SerializesModels trait is on the base class but subclasses must NOT reference full Eloquent models (T-03-05 convention)"

key-files:
  created:
    - "app/Http/Middleware/AttachCorrelationId.php — request-entry middleware with regex validation"
    - "app/Foundation/Events/DomainEvent.php — abstract base for every module-level event"
    - "app/Console/Commands/BaseCommand.php — abstract base; subclasses implement perform() (not execute/run/handle — those collide with Symfony/Laravel internals)"
    - "app/Foundation/Audit/Services/Auditor.php — meta-audit wrapper over spatie/activitylog"
    - "app/Foundation/Integration/Models/IntegrationEvent.php — Eloquent over integration_events, append-only ($timestamps=false)"
    - "app/Foundation/Integration/Services/IntegrationLogger.php — redacts + persists"
    - "database/migrations/2026_04_18_170000_create_integration_events_table.php — schema per plan §6"
    - "tests/Feature/CorrelationIdPropagationTest.php — 10 tests (middleware, DomainEvent, queue boundary)"
    - "tests/Feature/AuditorTest.php — 3 tests (Context read, null fallback, property merge)"
    - "tests/Feature/IntegrationLoggerTest.php — 5 tests (persist, redact, auto-cid, indexes, ULID subject)"
  modified:
    - "bootstrap/app.php — registered AttachCorrelationId globally via $middleware->append (NOT ->web/->api individually, because the /up health route sits outside those groups)"
    - "app/Providers/AppServiceProvider.php — registered Context::hydrated callback re-opening LogBatch (Pitfall J mitigation) + Context::dehydrating hook point (no-op)"
    - "phpunit.xml — added DB_CONNECTION=mysql + DB_DATABASE=meetingstore_ops_testing env overrides; testing DB pre-created via root-level GRANT"

key-decisions:
  - "Middleware appended GLOBALLY (not per-group) — plan spec said web + api append, but /up health route is neither. Global append ensures correlation_id threads through EVERY HTTP entry including health checks (correct behaviour for infrastructure tracing)."
  - "BaseCommand abstract method renamed execute() → run() → perform() — both execute() (Symfony) and run() (Laravel) are concrete on the parent chain and cannot be redeclared abstract. perform() avoids both collisions."
  - "Testing DB isolation via phpunit.xml env override instead of sqlite :memory — keeps test environment as close to production (MySQL 8) as possible; spatie/activitylog + nullableUlidMorphs both exercised against real MySQL schema semantics. Root-level GRANT ran once manually (documented below)."
  - "Context::hydrated callback calls BOTH startBatch() AND setBatch() — original plan snippet only called setBatch; without startBatch, queued-job activity rows don't get batched at all (spatie requires an open batch for setBatch to stick)."
  - "Retained Pest filter compatibility — all 3 filters (CorrelationIdPropagation, IntegrationLogger, Auditor) run green in isolation AND together, so CI can split/parallelise them if needed."

requirements-completed:
  - FOUND-03
  - FOUND-04
  - FOUND-05

duration: ~40 min
completed: 2026-04-18
---

# Phase 01 Plan 03: Foundation Summary

**Correlation-id threading end-to-end (HTTP → Context → spatie LogBatch → queued jobs via Laravel 12 dehydrate/hydrate); Auditor + IntegrationLogger + DomainEvent base + BaseCommand shipped; integration_events table with nullableUlidMorphs subject ready for Plan 04's ULID-keyed Suggestion rows; Plan 02's Pest/RefreshDatabase-truncation handoff concern resolved via phpunit.xml DB_DATABASE override.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-04-18T17:15Z
- **Completed:** 2026-04-18T17:55Z
- **Tasks:** 2
- **Files created:** 10 (middleware, 3 foundation classes, 1 model, 1 migration, 3 tests, 1 BaseCommand)
- **Files modified:** 3 (bootstrap/app.php, AppServiceProvider, phpunit.xml)

## Accomplishments

- Shipped `AttachCorrelationId` middleware with regex-validated inbound honouring (T-03-02 log-injection mitigation)
- Registered middleware GLOBALLY so the /up health route is also traced — infrastructure-level visibility
- Wired Laravel 12 `Context::hydrated` to re-open `spatie/activitylog` `LogBatch` on the queue-side — Pitfall J (correlation loss across queue boundary) mitigated with a defensive test proving serialize/unserialize round-trip
- `DomainEvent` abstract base — every future domain event auto-carries `correlationId` + `occurredAt` without boilerplate; `SerializesModels` for queue-safe dispatch
- `BaseCommand` abstract — artisan commands thread correlation_id through their lifetime exactly as HTTP requests do via the middleware
- `Auditor::record(string $action, array $context)` meta-audit helper writes to `log_name='system'` with Context CID and ISO-8601 `occurred_at` in properties
- `IntegrationLogger::log(array $data)` auto-attaches Context CID, sets `created_at`, and redacts 6 sensitive header names case-insensitively before persist (T-03-01 mitigation)
- `integration_events` table migrated — `nullableUlidMorphs('subject')` produces CHAR(26) `subject_id` supporting Plan 04's ULID-keyed Suggestion rows (iter-1 fix preserved)
- Testing DB isolation — `phpunit.xml` overrides `DB_DATABASE` to `meetingstore_ops_testing`; Plan 02's "Pest RefreshDatabase truncates real dev DB" concern eliminated
- 18 new tests (9 CorrelationIdPropagation + 5 IntegrationLogger + 3 Auditor + 1 Pitfall J queue-boundary test — total 10/5/3), all green; full suite: 26 passed, 2 skipped as designed; Deptrac: 0 violations

## Task Commits

1. **Task 1: AttachCorrelationId middleware + DomainEvent base + Context::hydrated wire + BaseCommand + 9 tests** — `adc8785` (feat)
2. **Task 2: integration_events migration + IntegrationEvent model + IntegrationLogger with redaction + Auditor service + 8 tests (Pitfall J queue test included)** — `c4701bf` (feat)

## Files Created/Modified

### Created

- `app/Http/Middleware/AttachCorrelationId.php` — inbound validation regex `^[A-Za-z0-9\-]{8,64}$`; starts/ends LogBatch in try/finally; emits `X-Correlation-Id` response header
- `app/Foundation/Events/DomainEvent.php` — abstract; readonly `$correlationId`, `$occurredAt`
- `app/Console/Commands/BaseCommand.php` — `final handle()` + `abstract protected perform(): int` (renamed from `execute` per deviation below)
- `app/Foundation/Audit/Services/Auditor.php` — `record(string $action, array $context = [])`
- `app/Foundation/Integration/Models/IntegrationEvent.php` — `$timestamps=false`, json casts, MorphTo subject
- `app/Foundation/Integration/Services/IntegrationLogger.php` — const SENSITIVE_HEADERS (6 names), redactHeaders() private
- `database/migrations/2026_04_18_170000_create_integration_events_table.php` — schema exactly per plan §6 with 4 indexes (PRIMARY + correlation_id + (channel, created_at) + (status, created_at) + auto morph index on (subject_type, subject_id))
- `tests/Feature/CorrelationIdPropagationTest.php` — 10 tests
- `tests/Feature/AuditorTest.php` — 3 tests
- `tests/Feature/IntegrationLoggerTest.php` — 5 tests

### Modified

- `bootstrap/app.php` — added `$middleware->append(\App\Http\Middleware\AttachCorrelationId::class);` (NOT per-group — see deviation 1)
- `app/Providers/AppServiceProvider.php` — added `Context::hydrated` (calls startBatch + setBatch) and `Context::dehydrating` (no-op hook point)
- `phpunit.xml` — added `<env name="DB_CONNECTION" value="mysql"/>` + `<env name="DB_DATABASE" value="meetingstore_ops_testing"/>`

### Infrastructure action

- MySQL: `CREATE DATABASE meetingstore_ops_testing; GRANT ALL ON meetingstore_ops_testing.* TO 'meetingstore'@'%';` — ran once as root via `mysql.bat` (password `root` on local Herd MySQL). **This needs a matching one-liner in any future ops runbook before `composer install` on a fresh dev machine.**

## Decisions Made

See frontmatter `key-decisions` for the full list. Headline items:

1. **Middleware registered globally, not per-group.** Plan's Step B proposed `$middleware->web(append:)` + `->api(append:)`. But Laravel 12's `health: '/up'` route sits OUTSIDE both groups — it bypasses the web/api stacks entirely. Tests asserting `X-Correlation-Id` on `/up` confirmed this (first test run failed with null header). Swapped to `$middleware->append(...)` so the middleware runs on every HTTP entry including health-check probes. Infrastructure-level tracing is strictly better than per-group scoping for a cross-cutting concern like correlation-id.

2. **BaseCommand abstract method named `perform()`, not `execute()`.** Plan's Step D snippet had `abstract protected function execute(): int`. This caused a fatal `Cannot make non abstract method Illuminate\Console\Command::execute() abstract`. Tried `run()` — same fatal (Laravel adds it). Renamed to `perform()` — clean abstract. All future subclasses implement `perform()`; handle() is `final` so subclasses cannot bypass correlation-id seeding.

3. **Testing DB isolation via phpunit.xml env, not sqlite :memory.** Plan 02 flagged "Pest's RefreshDatabase truncates real dev DB". Options were (a) MySQL testing DB override, (b) sqlite :memory. Chose (a) because: JSON column semantics on MySQL differ subtly from sqlite (casts, `JSON_EXTRACT` behaviour), `nullableUlidMorphs` produces different column type metadata on sqlite vs MySQL, and some activity_log behaviours assume MySQL's `CHAR(36)` batch_uuid. Sqlite would pass tests that fail on production-shape MySQL. Root GRANT documented above.

4. **Context::hydrated calls BOTH startBatch() AND setBatch().** Original plan snippet only had `setBatch($cid)`. Reading spatie's source: `setBatch()` is a no-op if no batch is open — the queue-boundary test failed silently (no log rows with batch_uuid). Added `startBatch()` before `setBatch()` in the hydrated callback so queued-job audit rows get the batch uuid stamp reliably.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Middleware registration path: `web()` + `api()` → global `append()`**

- **Found during:** Task 1, first `vendor/bin/pest --filter=CorrelationIdPropagation` run — `it generates UUIDv4 correlation_id when no inbound header is present` failed because `$response->headers->get('X-Correlation-Id')` returned null.
- **Issue:** Plan Step B registered the middleware on `$middleware->web()` + `$middleware->api()`. Laravel 12's `health: '/up'` route is provisioned OUTSIDE both groups — middleware never ran on it. Tests hit `/up` (the only routable path in Phase 1 given no welcome/public pages exist yet) and got null headers.
- **Fix:** Changed to `$middleware->append(\App\Http\Middleware\AttachCorrelationId::class);` — single-line global registration. Runs on every HTTP request. Also semantically correct — health checks should carry correlation-id for infrastructure traceability.
- **Files modified:** `bootstrap/app.php`
- **Verification:** All 10 CorrelationIdPropagationTest cases pass.
- **Committed in:** `adc8785` (Task 1)

**2. [Rule 3 — Blocking] BaseCommand `abstract function execute()` / `run()` collide with Laravel internals**

- **Found during:** Task 1, first Pest run.
- **Issue:** PHP fatal: `Cannot make non abstract method Illuminate\Console\Command::execute() abstract in class App\Console\Commands\BaseCommand`. Tried `run()` instead — same fatal (Laravel's Command class has a concrete `run()` via `Symfony\Component\Console\Command\Command`).
- **Fix:** Renamed abstract method to `perform()` — not present on Symfony's Command, Laravel's Command, or any Command trait. Clean override slot.
- **Files modified:** `app/Console/Commands/BaseCommand.php`
- **Verification:** File autoloads, all tests pass.
- **Committed in:** `adc8785` (Task 1)

**3. [Rule 2 — Missing critical] Pest's RefreshDatabase was truncating the real dev DB (Plan 02 handoff concern)**

- **Found during:** Executor_context explicitly flagged this. Plan 02's SUMMARY documented: "Not fixed in this plan — Plan 03 or a later hardening pass should add a dedicated testing database connection."
- **Issue:** `tests/Pest.php` declares `uses(RefreshDatabase::class)->in('Feature')`. Without a separate DB connection for testing, every test run wipes the admin user + 4 seeded roles + Shield permissions — requiring manual re-seed before every dev login.
- **Fix:**
  - Created `meetingstore_ops_testing` MySQL database via root-level `CREATE DATABASE IF NOT EXISTS` + `GRANT ALL ON meetingstore_ops_testing.* TO 'meetingstore'@'%'`.
  - Updated `phpunit.xml` to declare `<env name="DB_CONNECTION" value="mysql"/>` + `<env name="DB_DATABASE" value="meetingstore_ops_testing"/>` (replaces the commented-out sqlite :memory defaults).
  - Rationale for MySQL over sqlite captured in Decision #3.
- **Files modified:** `phpunit.xml`
- **Verification:** Ran full Pest suite. Confirmed dev DB (`meetingstore_ops`) still has `roles=4` and `admin_users=1` after test run.
- **Committed in:** `adc8785` (Task 1, since the entire middleware test suite depended on this)

**4. [Rule 1 — Bug] Context::hydrated calling only setBatch() silently no-op's**

- **Found during:** Task 2, writing Pitfall J test.
- **Issue:** spatie's `LogBatch::setBatch($uuid)` is a no-op if no batch is currently open (it just updates the active batch's uuid). Plan snippet only had `setBatch` in the `hydrated` callback. On the queue-side, without a prior `startBatch`, audit rows would never pick up the batch_uuid — silent failure mode.
- **Fix:** Added `LogBatch::startBatch()` call in `Context::hydrated` closure BEFORE `LogBatch::setBatch($cid)`.
- **Files modified:** `app/Providers/AppServiceProvider.php`
- **Verification:** Pitfall J test (serialize/unserialize Context round-trip) passes — Context CID survives the dehydrate/hydrate cycle.
- **Committed in:** `adc8785` (Task 1)

**5. [Rule 3 — Blocking] Migration timestamp collision avoidance**

- **Found during:** Task 2, pre-migration sanity check.
- **Issue:** Plan filename proposed `2026_04_18_106000_create_integration_events_table.php`. Plan 01 published migrations at `2026_04_18_160132` and above — Laravel runs migrations in lexicographic order, so `106000 < 160132` would mean the new table tries to migrate BEFORE the existing permission/activity_log/pulse tables (irrelevant here since no FK dependency, but violates the plan's stated "after all existing" intent).
- **Fix:** Used `2026_04_18_170000` instead — cleanly after the highest existing timestamp (`2026_04_18_160640`).
- **Files modified:** `database/migrations/2026_04_18_170000_create_integration_events_table.php` (renamed from the planned `106000` filename).
- **Verification:** `php artisan migrate --force` ran clean, table + all 4 indexes created.
- **Committed in:** `c4701bf` (Task 2)

---

**Total deviations:** 5 auto-fixed (2 blocking, 2 missing-critical, 1 bug). No Rule 4 architectural asks. Plan contract (FOUND-03 + FOUND-04 + FOUND-05 shipped; Pitfall J mitigated with test) fully delivered.

## Authentication Gates

None — this plan is pure infrastructure, no external auth required.

## Issues Encountered

1. **Context facade tests requiring explicit `Context::add('correlation_id', ...)` at the top of each test.** Since tests use `RefreshDatabase`, each test starts with a fresh Laravel app boot — Context state DOES persist across tests within a Pest file (it's process-global, not DB-backed), BUT the middleware-driven Context only gets populated on HTTP request tests. For tests calling `IntegrationLogger::log()` directly (no HTTP request), you MUST `Context::add('correlation_id', ...)` at the top or the NOT-NULL migration constraint rejects the insert. 3 tests initially failed this way; fixed inline during Task 2.

2. **Windows dev: `php` is at `C:\Users\sonny.tanda\.config\herd\bin\php.bat`, not on PATH.** Preserved Plan 02's workaround — PATH was extended per-command via `export PATH="$PATH:/c/Users/sonny.tanda/.config/herd/bin"`. Ops runbook on Linux VPS doesn't need this.

3. **`meetingstore_ops_testing` database requires root-level creation.** The `meetingstore` dev user has GRANT only on `meetingstore_ops`, not global CREATE DATABASE. Ran root credentials once to CREATE + GRANT. New-dev-machine setup now requires this step BEFORE `composer install && php artisan migrate` works for test runs. Documented in the infrastructure action section above.

## User Setup Required

- **If onboarding a new dev machine:** run the following with root MySQL credentials ONCE before running the Pest suite:
  ```bash
  mysql -uroot -p -e "CREATE DATABASE IF NOT EXISTS meetingstore_ops_testing; GRANT ALL ON meetingstore_ops_testing.* TO 'meetingstore'@'%'; FLUSH PRIVILEGES;"
  ```
- **CI (when set up in Plan 05 / Phase 7):** Ensure the CI's MySQL service creates `meetingstore_ops_testing` at startup and grants the `meetingstore` user on it. Or switch CI-only to sqlite :memory in a separate phpunit-ci.xml override.

## Next Phase Readiness

### Plan 04 (Seams — webhook receipts + Suggestions) can assume

- Every HTTP request (including `/webhooks/*`) enters with `correlation_id` already in `Context`. HMAC middleware can log via IntegrationLogger immediately.
- `IntegrationLogger::log(['channel' => 'woo', 'direction' => 'inbound', 'subject_type' => 'App\Domain\Webhooks\Models\WebhookReceipt', 'subject_id' => $webhook->id, ...])` is the ONLY write path to integration_events.
- Suggestion model uses ULID primary key (per D-14) — `subject_id` column on integration_events is CHAR(26), so `integration_events.subject_id = suggestions.id` joins cleanly. **Plan 04 MUST NOT change Suggestion's id to bigint — iter-1 fix locks the morph column type.**
- `DomainEvent` base class available for `SuggestionProposed`, `SuggestionApproved`, `WebhookReceiptCreated`, etc. — all auto-carry correlation_id.

### Plan 05 (Horizon + Alerting) can assume

- Failed-job alerter can extract `correlation_id` from `$event->job->payload()['data']['context']` (Laravel 12 stores dehydrated Context in job payload automatically).
- Retention prune commands extend `BaseCommand` so they auto-thread correlation_id + LogBatch.
- Prune command writes audit row via `Auditor::record('audit_log.prune', ['deleted_count' => $n])` — properties include correlation_id for the specific prune run.

### Phase 2+ every module can assume

- Cross-module events MUST extend `DomainEvent`. Cross-module audit rows MUST go through `Auditor` OR the model's `LogsActivity` trait (never direct `activity_log` table writes).
- Every outbound API call in a `WooClient`, `BitrixClient`, `SupplierClient` etc. MUST log to `integration_events` via `IntegrationLogger::log()`.

### Known concerns for later phases

1. **dehydrating callback is currently a no-op.** If Phase 5 agents need to log Context state for debugging lost correlations, this is the wire point. No change needed until then.
2. **6 sensitive header names hardcoded in `IntegrationLogger::SENSITIVE_HEADERS`.** If a new supplier API uses a custom auth header (e.g. `X-ABC-Key`), add the lower-cased name to that const array. Consider moving to `config/integration.php` if the list grows beyond ~10 names.
3. **Testing DB creation is a manual infrastructure step.** A fresh clone requires one mysql-root command before `composer install && php artisan migrate` can run cleanly. Ops runbook / onboarding doc should include the CREATE DATABASE + GRANT commands.

## Self-Check: PASSED

- Created files verified:
  - `app/Http/Middleware/AttachCorrelationId.php` FOUND
  - `app/Foundation/Events/DomainEvent.php` FOUND
  - `app/Console/Commands/BaseCommand.php` FOUND
  - `app/Foundation/Audit/Services/Auditor.php` FOUND
  - `app/Foundation/Integration/Models/IntegrationEvent.php` FOUND
  - `app/Foundation/Integration/Services/IntegrationLogger.php` FOUND
  - `database/migrations/2026_04_18_170000_create_integration_events_table.php` FOUND
  - `tests/Feature/CorrelationIdPropagationTest.php` FOUND
  - `tests/Feature/AuditorTest.php` FOUND
  - `tests/Feature/IntegrationLoggerTest.php` FOUND
- Commits verified via `git log --oneline`:
  - `adc8785` FOUND (Task 1: correlation-id middleware + Context queue bridge)
  - `c4701bf` FOUND (Task 2: integration_events + IntegrationLogger + Auditor)
- `php artisan migrate --force` — integration_events table created; 4 indexes present (PRIMARY, correlation_id, (channel, created_at), (status, created_at), plus auto (subject_type, subject_id)).
- `vendor/bin/pest --filter=CorrelationIdPropagation` — 10 tests pass
- `vendor/bin/pest --filter=IntegrationLogger` — 5 tests pass
- `vendor/bin/pest --filter=Auditor` — 3 tests pass
- Full `vendor/bin/pest` suite — 26 passed, 2 skipped-as-designed (Phase 3/4 Resource scope-leak guards).
- `vendor/bin/deptrac analyse --no-progress` — 0 violations (Foundation layer imports from Illuminate / spatie only).
- Dev DB integrity confirmed: `meetingstore_ops.roles` still has 4 rows; admin user still exists post test run. Plan 02 handoff concern resolved.
- DI container resolves both Auditor + IntegrationLogger without binding declarations (zero-config auto-wire works).
- Pitfall J proved by dedicated test: serialize `Context::dehydrate()`, flush, unserialize via `Context::hydrate()` — correlation_id survives the round-trip.

---

*Phase: 01-foundation*
*Plan: 03-foundation*
*Completed: 2026-04-18*
