---
phase: 01-foundation
plan: 05-horizon-alerting
subsystem: horizon,alerting,retention,ci
tags: [horizon, supervisors, failed-job-monitor, throttled-dedup, alert-recipient, retention-prune, deptrac-ci, pitfall-k, pitfall-l, pitfall-m]

requires:
  - phase: 01-01-scaffold
    provides: "laravel/horizon ^5.45, spatie/laravel-failed-job-monitor ^4.4, 4 logical Redis DBs (horizon=2), HorizonServiceProvider registered, config/horizon.php + config/failed-job-monitor.php published, depfile.yaml + pint.json + phpstan.neon"
  - phase: 01-02-rbac
    provides: "4 roles seeded (admin/pricing_manager/sales/read_only), hasRole('admin') works on User model"
  - phase: 01-03-foundation
    provides: "Auditor service (meta-audit), BaseCommand for correlation_id threading, integration_events table + IntegrationEvent model, phpunit.xml DB override to meetingstore_ops_testing"
  - phase: 01-04-seams
    provides: "sync_diffs table (Pitfall L conditional prune scope), SyncDiff model, services.woo.write_enabled flag, SuggestionPolicy pattern (hasRole admin override) replicated for AlertRecipientPolicy"

provides:
  - "config/horizon.php: 7 production supervisors (webhook-inbound, crm-bitrix, sync-woo-push, sync-bulk, competitor-csv, critical, default) mapped 1-to-1 to 7 named queues; 1 all-in-one local supervisor"
  - "HorizonServiceProvider::gate() — admin-only (hasRole('admin')); Pitfall E Redis-driver boot assertion (suppressed in testing env)"
  - "Horizon uses dedicated 'horizon' Redis connection (DB 2 — Plan 01 logical segregation)"
  - "alert_recipients table (email unique, name, is_active, notes, timestamps)"
  - "AlertRecipient Eloquent model + AlertRecipientPolicy (admin-only) + AlertRecipientResource (Filament CRUD with getEloquentQuery() no-op override)"
  - "AlertDistribution Notifiable with stable getKey() — routes to active recipients at dispatch time (no cache); routeNotificationForSlack() hardcoded null (D-10); defensive Log::warning on empty list (Pitfall M)"
  - "ThrottledFailedJobNotifier listener with 5-minute Cache::add atomic lock (D-13 race-safe dedup)"
  - "config/failed-job-monitor.php: notifiable => null (T-05-06 package auto-listener suppressed)"
  - "EventServiceProvider (new file) registered in bootstrap/providers.php — maps JobFailed => ThrottledFailedJobNotifier"
  - "AlertRecipientSeeder seeds ops@meetingstore.co.uk fallback (Pitfall M); wired in DatabaseSeeder"
  - "TestFailingJobCommand (php artisan alerts:test-failure) + TestFailingJob"
  - "PruneActivityLogCommand (365 days, D-04), PruneIntegrationEventsCommand (90 days, D-05), PruneSyncDiffsCommand (conditional, D-08) — each writes Auditor::record meta-audit (D-09)"
  - "routes/console.php: 3 scheduled prunes at 03:00/03:10/03:30 with withoutOverlapping(30) + onOneServer()"
  - ".github/workflows/ci.yml: 4 parallel jobs (Pint + Larastan + Deptrac + Pest --min=60); MySQL 8 + Redis 7 services"
  - "tests/Architecture/DeptracTest.php: positive + negative (deliberate violator) tests; Architecture suite bound to TestCase in tests/Pest.php"

affects:
  - "Phase 2 (supplier sync): supervisors ready for sync-bulk + sync-woo-push queues; PruneSyncDiffsCommand ready to activate post-cutover when WOO_WRITE_ENABLED=true"
  - "Phase 4 (CRM push): crm-bitrix-supervisor already respects Bitrix 2 req/sec ceiling; failed Bitrix pushes trigger ThrottledFailedJobNotifier"
  - "Phase 5 (margin suggestions): ApplySuggestionJob failures fire via JobFailed → dedup 5-min → email to AlertRecipient list"
  - "Phase 7 (cutover): PruneSyncDiffsCommand flag-check pattern is the cutover gate signal"
  - "All phases: CI pipeline enforces Deptrac + Pint + Larastan + Pest on every PR"

tech-stack:
  added:
    - "Laravel 12 schedule (routes/console.php) with withoutOverlapping(30) + onOneServer() pattern"
    - "Cache::add atomic lock pattern for dedup (superior to has+put race-window)"
    - "NotificationFake nested-array flattening helper for Pest assertions"
  patterns:
    - "Admin-only gate pattern: Policy hardcodes hasRole('admin'); registered via Gate::policy() in AppServiceProvider::boot (shared pattern with SuggestionPolicy from Plan 04)"
    - "Retention prune pattern: extends Illuminate\\Console\\Command; injects Auditor; records action-name + deleted_count + cutoff_date in meta-audit"
    - "Conditional prune pattern (PruneSyncDiffsCommand): config() flag read at handle() runtime, not constructor — toggling env takes effect on next schedule fire"
    - "Horizon boot-assertion escape hatch: app()->environment('testing') early-return so phpunit.xml QUEUE_CONNECTION=sync doesn't crash tests"
    - "CI test step TODO marker: --min=60 floor with explicit comment about Phase 3 threshold raise"

key-files:
  created:
    - "database/migrations/2026_04_18_190000_create_alert_recipients_table.php"
    - "app/Domain/Alerting/Models/AlertRecipient.php"
    - "app/Domain/Alerting/Notifiables/AlertDistribution.php"
    - "app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php"
    - "app/Domain/Alerting/Policies/AlertRecipientPolicy.php"
    - "app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php"
    - "app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/ListAlertRecipients.php"
    - "app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/CreateAlertRecipient.php"
    - "app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/EditAlertRecipient.php"
    - "app/Domain/Alerting/Jobs/TestFailingJob.php"
    - "app/Console/Commands/TestFailingJobCommand.php"
    - "app/Console/Commands/PruneActivityLogCommand.php"
    - "app/Console/Commands/PruneIntegrationEventsCommand.php"
    - "app/Console/Commands/PruneSyncDiffsCommand.php"
    - "app/Providers/EventServiceProvider.php"
    - "database/seeders/AlertRecipientSeeder.php"
    - ".github/workflows/ci.yml"
    - "tests/Feature/HorizonSupervisorTest.php (9 tests)"
    - "tests/Feature/FailedJobAlertTest.php (15 tests)"
    - "tests/Feature/RetentionPruneTest.php (8 tests)"
    - "tests/Architecture/DeptracTest.php (2 tests)"
  modified:
    - "config/horizon.php (7 production supervisors + 1 local all-in-one; use=horizon)"
    - "config/failed-job-monitor.php (notifiable => null — T-05-06 suppression)"
    - "app/Providers/HorizonServiceProvider.php (gate + Pitfall E boot assertion)"
    - "app/Providers/AppServiceProvider.php (AlertRecipientPolicy registration)"
    - "bootstrap/providers.php (EventServiceProvider registration)"
    - "database/seeders/DatabaseSeeder.php (AlertRecipientSeeder call)"
    - "routes/console.php (3 prune schedules)"
    - "tests/Pest.php (Architecture suite binds to TestCase)"

key-decisions:
  - "Migration timestamp 2026_04_18_190000 (not plan-proposed 104000) — 104000 would sort BEFORE Plan 01's permission/activity_log tables; 190000 is cleanly after Plan 04's 180200 per orchestrator permission-to-deviate"
  - "Pitfall E boot assertion suppressed in testing env — phpunit.xml forces QUEUE_CONNECTION=sync for test isolation; without the `if (environment('testing')) return;` guard every test would crash on Horizon boot. Verified fires correctly in dev env."
  - "AlertDistribution::getKey() hardcoded to 'alert-distribution' — required by Laravel's NotificationFake infrastructure (keys the sent-notifications array). Singleton-shape: there's only one distribution list, so a constant key is semantically correct."
  - "ThrottledFailedJobNotifier uses Cache::add (atomic) instead of Cache::has + Cache::put — has+put leaves a race window where two concurrent listeners can both pass the `has` check. Cache::add is get-or-set in one operation; only one listener wins the lock."
  - "shield:generate NOT re-run in Plan 05 — same pattern as Plan 04: re-running would regenerate SuggestionPolicy with permission-based stubs (losing the hasRole override), AND regenerate RolePolicy with {{ Placeholder }} literals (Shield 3.9.10 bug Plan 02 fixed). AlertRecipientPolicy uses hasRole('admin') directly, so no shield-generated permission is needed for admin-only access."
  - "Schedule uses ->onOneServer() without a cache driver config — safe because our prod Redis cache is already provisioned; Laravel uses the default cache store for the one-server lock."
  - "DeptracTest negative test imports App\\Domain\\Sync\\Models\\SyncDiff (a REAL class) instead of a non-existent CRM\\Services\\BitrixClient — deptrac only flags resolved symbols, an unresolvable import gets marked 'uncovered' not 'violating'."

patterns-established:
  - "Pattern (01-05-a): Admin-only Domain Policy — Policy class hardcodes hasRole('admin') gate on every method; policy is registered via Gate::policy() in AppServiceProvider::boot(); Filament Resource inherits the gate implicitly. Do NOT regenerate via shield:generate without porting back the role check."
  - "Pattern (01-05-b): Retention prune command — extends Illuminate\\Console\\Command, signature `{domain}:prune {--days=N}`, injects Auditor, deletes where created_at < cutoff, writes meta-audit with deleted_count + cutoff_date + days. Scheduled via routes/console.php with withoutOverlapping(30) + onOneServer()."
  - "Pattern (01-05-c): Conditional prune — flag read via config() at handle() runtime (not container boot); if disabled, early-return with a skip meta-audit row so operators can see the skip decision in the audit log."
  - "Pattern (01-05-d): Atomic dedup lock via Cache::add — `if (!Cache::add($key, 1, now()->addMinutes(N))) return;` is race-safe vs the naive has+put pattern. Use for any throttle/rate-limit/dedup window."

requirements-completed:
  - FOUND-09
  - FOUND-10
  - FOUND-11
  - FOUND-12

duration: ~85 min
completed: 2026-04-18
---

# Phase 01 Plan 05: Horizon + Alerting + Retention + CI Summary

**Queue/alert/retention/CI infrastructure closing out Phase 1. 7 Horizon supervisors mapped 1-to-1 to the 7 named queues with admin-only /horizon gate (Pitfall K). Failed-job alerting with 5-minute race-safe dedup (D-13 via atomic Cache::add) routing through DB-backed AlertRecipient list (D-12 new scope); fallback ops@meetingstore.co.uk seeded (Pitfall M); spatie package's auto-listener suppressed to prevent double-send (T-05-06). Three retention prunes (activity_log 365d, integration_events 90d, sync_diffs conditional) each writing meta-audit rows; sync_diffs prune skipped entirely while WOO_WRITE_ENABLED=false to preserve parity evidence (Pitfall L regression test). GitHub Actions CI runs Pint + Larastan + Deptrac + Pest --min=60 on every PR. 34 new tests; 92 total (2 skipped as designed); Deptrac 0 violations.**

## Performance

- **Duration:** ~85 min
- **Started:** 2026-04-18T19:00Z
- **Completed:** 2026-04-18T20:25Z
- **Tasks:** 4
- **Commits:** 5 (4 feat/chore + 1 style)
- **Files created:** 21
- **Files modified:** 8 (config/horizon.php, config/failed-job-monitor.php, HorizonServiceProvider, AppServiceProvider, bootstrap/providers.php, DatabaseSeeder, routes/console.php, tests/Pest.php)
- **Test count delta:** +34 (HorizonSupervisor 9 + FailedJobAlert 15 + RetentionPrune 8 + Deptrac 2) — full suite now 92 passing + 2 skipped

## Accomplishments

### FOUND-09 — Horizon 7 supervisors + admin gate

- `config/horizon.php` `environments.production` redefined with 7 named supervisors per 01-RESEARCH.md §4 verbatim:
  - `webhook-inbound-supervisor` (3–10 procs, 60s timeout — latency-critical)
  - `crm-bitrix-supervisor` (1–2 procs — Bitrix 2 req/sec hard cap)
  - `sync-woo-push-supervisor` (2–3 procs — Woo 100 req/min ceiling)
  - `sync-bulk-supervisor` (1 proc, 1800s timeout — 30-min chunked supplier syncs)
  - `competitor-csv-supervisor` (1–2 procs, 600s timeout)
  - `critical-supervisor` (2–5 procs, 60s)
  - `default-supervisor` (1–3 procs, 120s)
- `environments.local` uses one all-in-one supervisor covering every queue
- `config/horizon.php use = 'horizon'` — Plan 01's dedicated Redis DB 2
- `HorizonServiceProvider::gate()` — `$user !== null && $user->hasRole('admin')` (Pitfall K)
- `HorizonServiceProvider::boot()` — Pitfall E assertion `config('queue.default') === 'redis'` — suppressed under `testing` env (phpunit.xml forces QUEUE_CONNECTION=sync for isolation)

### FOUND-10 — Redis persistence

Production Redis config template documented in plan's `user_setup` (already there from planner) — our local Docker Compose Redis already has `--appendonly yes --appendfsync everysec --maxmemory-policy noeviction`. CI's Redis service (GitHub Actions) doesn't accept these env vars on the stock image — production hardening lives on the VPS per `/etc/redis/redis.conf`. Ops runbook note ships in the user_setup.

### FOUND-11 — Failed-job alerting

- **alert_recipients table** (id, email unique, name, is_active default true, notes, timestamps) — D-12 new scope vs original REQUIREMENTS.md.
- **AlertRecipient model** with `is_active` boolean cast; fillable email/name/is_active/notes.
- **AlertDistribution** Notifiable (`use Notifiable`) with:
  - `getKey()` returning `'alert-distribution'` — required for NotificationFake (discovered during test run; see Deviation 2).
  - `routeNotificationForMail()` returns `AlertRecipient::where('is_active', true)->pluck('name', 'email')->all()` — resolved at dispatch time (no cache).
  - `routeNotificationForSlack()` returns `null` (D-10 explicit — never routes to Slack even if config drifts).
  - Defensive `Log::warning` on empty recipient list (Pitfall M safety net behind the seeder).
- **ThrottledFailedJobNotifier** listener: `Cache::add("failed-job-alert:{md5(jobClass|exClass|exMessage)}", 1, now()->addMinutes(5))` — atomic get-or-set; if `add` returns false the lock is already held and the listener short-circuits. Race-safe vs the naive `has`+`put` pattern.
- **config/failed-job-monitor.php** `notifiable => null` — suppresses spatie's built-in auto-listener (T-05-06 no double-send).
- **AlertRecipientPolicy** — every method hardcodes `$user->hasRole('admin')` (Pitfall K). Registered via `Gate::policy()` in `AppServiceProvider::boot()`. **Do not regenerate via shield:generate** — would overwrite with permission-based stubs (same pattern Plan 04 documented for SuggestionPolicy).
- **AlertRecipientResource** (Filament): admin-only CRUD with `getEloquentQuery()` no-op override (Gemini MEDIUM 2 forward pattern — prevents future N+1 regression even though no relations are rendered yet).
- **AlertRecipientSeeder** seeds `ops@meetingstore.co.uk` fallback (Pitfall M); wired into `DatabaseSeeder::run()` after `TestSuggestionSeeder`.
- **EventServiceProvider** (new file, registered in `bootstrap/providers.php`) maps `Illuminate\Queue\Events\JobFailed => ThrottledFailedJobNotifier` — Laravel 12 doesn't auto-generate this provider, so we create it explicitly.
- **TestFailingJobCommand** (`php artisan alerts:test-failure`) dispatches `TestFailingJob` which always throws `RuntimeException('Deliberate failure — alerts:test-failure')` with `tries=1` for fast failure signal.

### FOUND-12 — Retention prunes

- **PruneActivityLogCommand** (`activitylog:prune --days=365`) — 365-day window per D-04; writes `activitylog.pruned` meta-audit.
- **PruneIntegrationEventsCommand** (`integration-events:prune --days=90`) — 90-day window per D-05.
- **PruneSyncDiffsCommand** (`sync-diffs:prune`) — **conditional** on `config('services.woo.write_enabled')`:
  - False (shadow mode): early-return with `sync-diffs.prune.skipped` meta-audit; **preserves parity evidence** for Phase 7 cutover (Pitfall L regression guard).
  - True (post-cutover): delete applied rows older than 30 days; un-applied diffs kept for investigation.
- **routes/console.php** schedules all 3 at staggered 03:00/03:10/03:30 with `withoutOverlapping(30)` + `onOneServer()`. TODO comments mark Phase 2 `sync-errors:prune` + Phase 5 `competitor-csv:prune` future additions.

### CI pipeline

- `.github/workflows/ci.yml` — 4 parallel jobs on PR + push to main/master:
  - **Pint** (`vendor/bin/pint --test`)
  - **Larastan** (`vendor/bin/phpstan analyse --memory-limit=1G`)
  - **Deptrac** (`vendor/bin/deptrac analyse --no-progress`)
  - **Pest** with `--coverage --min=60` — includes `TODO: raise to --min=80 after Phase 3` comment per Gemini Suggestion 3
- MySQL 8 + Redis 7 services block for the Pest job.
- `tests/Architecture/DeptracTest.php` — 2 tests:
  1. Positive: asserts current codebase exits 0 from `deptrac analyse`.
  2. Negative: plants `app/Domain/Products/Services/__DeptracViolator.php` that imports `App\Domain\Sync\Models\SyncDiff` (real class), runs deptrac, asserts non-zero exit, cleans up. Uses a real symbol so deptrac resolves it (an unresolvable symbol gets marked 'uncovered' not 'violating').
- `tests/Pest.php` binds Architecture suite to `TestCase` (no RefreshDatabase — architecture tests are pure file-system + process spawn).

## Task Commits

1. **Task 1:** Horizon supervisors + admin gate + HorizonSupervisorTest (9 tests) — `b58cbb8`
2. **Task 2:** Alerting (migration + model + notifiable + listener + resource + seeder + job + command + config suppress + EventServiceProvider) + FailedJobAlertTest (15 tests) — `435d221`
3. **Task 3:** 3 retention prune commands + routes/console.php schedule + RetentionPruneTest (8 tests) — `fafa5aa`
4. **Task 4:** CI workflow + DeptracTest architecture suite (2 tests) — `64c42c8`
5. **Style:** Pint auto-fix across new files — `394baae`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] HorizonServiceProvider::boot() Pitfall E assertion fires in testing env**

- **Found during:** Task 1 first Pest run — 9 tests failed with `Horizon requires QUEUE_CONNECTION=redis; found: sync`.
- **Issue:** Plan's boot() assertion `throw_unless(config('queue.default') === 'redis', ...)` correctly fires in dev/prod but phpunit.xml forces `QUEUE_CONNECTION=sync` for test-time synchronous dispatch. Every test in the suite crashes on Horizon boot.
- **Fix:** Added `if (app()->environment('testing')) return;` guard BEFORE the throw_unless. Pitfall E still enforced everywhere else — dev, staging, prod — just not under phpunit.
- **Files modified:** `app/Providers/HorizonServiceProvider.php`
- **Verification:** 9 HorizonSupervisorTest cases pass; Pitfall E assertion manually verified by running `QUEUE_CONNECTION=database php artisan migrate` in a shell (would throw RuntimeException, not tested in suite because test env always overrides).
- **Committed in:** `b58cbb8` (Task 1)

**2. [Rule 1 — Bug] AlertDistribution missing `getKey()` for NotificationFake**

- **Found during:** Task 2, first FailedJobAlertTest run — 3 tests failed with `Call to undefined method App\Domain\Alerting\Notifiables\AlertDistribution::getKey()`.
- **Issue:** Laravel's `NotificationFake` at `vendor/laravel/framework/src/Illuminate/Support/Testing/Fakes/NotificationFake.php:333` keys sent notifications by `get_class($notifiable).$notifiable->getKey()`. The `Notifiable` trait doesn't provide `getKey()` on its own — Eloquent models get it from `Model`, but our `AlertDistribution` is a plain class.
- **Fix:** Added `public function getKey(): string { return 'alert-distribution'; }` — singleton-shape key since there's only one distribution list.
- **Files modified:** `app/Domain/Alerting/Notifiables/AlertDistribution.php`
- **Verification:** All 15 FailedJobAlertTest cases pass.
- **Committed in:** `435d221` (Task 2)

**3. [Rule 1 — Bug] `Notification::assertSentOnDemand()` / `assertSentOnDemandTimes()` don't exist**

- **Found during:** Task 2, second Pest run after fix #2.
- **Issue:** I assumed those helpers existed on NotificationFake; they don't in Laravel 12. The correct helper for on-demand-style notifiables is a generic `sentNotifications()` accessor.
- **Fix:** Wrote a local `flattenSentNotifications()` helper that traverses NotificationFake's 4-level nested array structure (`[notifiableClass][key][notificationClass][]`) and returns a flat list. Assertions now use `expect(flattenSentNotifications())->toHaveCount(N)`.
- **Files modified:** `tests/Feature/FailedJobAlertTest.php`
- **Verification:** All 3 dedup tests pass.
- **Committed in:** `435d221` (Task 2)

**4. [Rule 3 — Blocking] DeptracTest negative test didn't fire**

- **Found during:** Task 4 first Pest run — positive test passes (exit 0) but negative test fails (expected non-zero, got zero).
- **Issue:** Plan's violator file imported `App\Domain\CRM\Services\BitrixClient` — CRM domain has no classes in Phase 1 (only `.gitkeep`). Deptrac marks unresolvable symbols as "uncovered" not "violating", so the file passed despite the deliberate cross-domain import intent.
- **Fix:** Switched the violator's import to `App\Domain\Sync\Models\SyncDiff` (a REAL class since Plan 04). Deptrac now resolves the symbol and correctly flags the Products → Sync layer violation.
- **Files modified:** `tests/Architecture/DeptracTest.php`
- **Verification:** Both positive + negative tests pass; no leftover violator file (`ls app/Domain/Products/Services/` empty post-test).
- **Committed in:** `64c42c8` (Task 4)

**5. [Rule 3 — Blocking] Migration timestamp — plan's `104000` would sort before dependency tables**

- **Found during:** Task 2 pre-migration sanity check.
- **Issue:** Plan proposed filename `2026_04_18_104000_create_alert_recipients_table.php`. Plan 01's permission/activity_log/pulse migrations sit at `160132–160640`; Plan 03's integration_events at `170000`; Plan 04's webhook/suggestion/sync_diff at `180000–180200`. Using `104000` would attempt to create `alert_recipients` BEFORE the activity_log table (which the seeder's Auditor meta-audit eventually writes to). Harmless in practice (no FK) but violates plan's "logical ordering" intent.
- **Fix:** Used `2026_04_18_190000` — cleanly after every dependency. Explicitly authorised by orchestrator's `permission_to_deviate` ("Adjust migration timestamps to maintain logical ordering").
- **Files modified:** Migration filename only
- **Verification:** `php artisan migrate --force` ran clean on dev + testing DBs.
- **Committed in:** `435d221` (Task 2)

**6. [Rule 2 — Missing Critical] shield:generate deliberately NOT re-run — same pattern as Plan 04**

- **Found during:** Task 2 pre-commit review.
- **Issue:** Plan Step I says "Re-run `shield:generate` + seeder so `alert_recipient` permissions land on admin". Plan 04's summary documented that `shield:generate --all` regenerates BOTH `SuggestionPolicy.php` (losing the `hasRole('admin')` override we specifically added) AND `RolePolicy.php` (reintroducing `{{ Placeholder }}` template literals per Shield 3.9.10 bug). Running it in Plan 05 would cascade-break two previously-fixed policies and require 3 manual re-fixes.
- **Fix:** Skipped `shield:generate`. AlertRecipientPolicy already enforces admin-only access via hardcoded `hasRole('admin')` — no shield-generated permission required. The admin role gets access via the role check, not via a Shield-generated `view_any_alert_recipient` permission.
- **Files modified:** None (preventative — no-op deviation)
- **Verification:** Full Pest suite green; admin role CAN viewAny + create AlertRecipient per tests.
- **Note for Plan 06+ / Phase 2+:** When adding new Filament Resources that need pricing_manager/sales access (e.g., `Product`, `PricingRule`, `CrmPushLog`), shield:generate WILL need to run and WILL regress SuggestionPolicy, AlertRecipientPolicy, RolePolicy — each requires porting back the hasRole overrides. A dedicated architecture test (`grep '\{\{ ' app/Policies/ app/Domain/*/Policies/`) would catch this — candidate for deferred-items.

**7. [Rule 2 — Missing Critical] Defensive Log::warning on empty AlertRecipient list**

- **Found during:** Task 2 implementation.
- **Issue:** Executor directive explicitly called out Pitfall M: "Even with the seeder, if someone empties `alert_recipients`, alerts silently vanish. Add a defensive log warning."
- **Fix:** `AlertDistribution::routeNotificationForMail()` now calls `Log::warning(...)` if the active-recipient pluck returns empty. Defensive assertion — should NEVER fire in practice because the seeder seeds ops@meetingstore.co.uk, and that row stays unless explicitly deleted.
- **Files modified:** `app/Domain/Alerting/Notifiables/AlertDistribution.php`
- **Verification:** Dedicated Pest test `AlertDistribution logs a warning when the recipient list is empty (Pitfall M defence)` uses `Log::shouldReceive('warning')->once()` to verify the assertion fires.
- **Committed in:** `435d221` (Task 2)

---

**Total deviations:** 7 auto-fixed (2 bugs, 3 blockers, 2 missing-critical). No Rule 4 architectural asks. Plan contract (FOUND-09 + FOUND-10 + FOUND-11 + FOUND-12 shipped; Pitfalls K/L/M mitigated with feature tests; T-05-01..T-05-07 threats addressed) fully delivered.

## Authentication Gates

None — plan is pure infrastructure. No external service credentials needed to ship Phase 1.

## Issues Encountered

1. **NotificationFake keys nested 4 levels deep.** `sentNotifications()` returns `[notifiableClass][notifiableKey][notificationClass][] = entry` — not a flat list. Required a helper function in the test file to flatten for `toHaveCount` assertions. Worth documenting in a future "Laravel testing patterns" note.

2. **Plan's non-existent helpers `assertSentOnDemand` / `assertSentOnDemandTimes`.** These aren't in Laravel 12's NotificationFake. A grep of the vendor directory confirmed only `assertSent`, `assertSentTo`, `assertSentOnDemand()` (no Times variant), `assertNotSentTo`, `assertSentTimes`. Future plans referencing these helpers should verify against current vendor code first.

3. **Laravel 12 doesn't ship EventServiceProvider.** Plan assumed it existed. Had to create it + register in `bootstrap/providers.php`. Minor — ~20 lines of scaffolding.

4. **Horizon's Pitfall E assertion vs phpunit.xml.** The env guard `if (environment('testing'))` feels like weakening the assertion, but it's the correct trade-off — without it, running the entire test suite is impossible. The assertion remains functional for every environment that matters (dev, staging, prod).

## User Setup Required

From plan's `user_setup` block — carried forward as Phase 1 → Phase 2 handoff:

### VPS (production)

- Write `/etc/redis/redis.conf`:
  ```
  appendonly yes
  appendfsync everysec
  appendfilename "appendonly.aof"
  auto-aof-rewrite-percentage 100
  auto-aof-rewrite-min-size 64mb
  maxmemory 2gb
  maxmemory-policy noeviction
  ```
- Install Supervisor, write `/etc/supervisor/conf.d/horizon.conf`:
  ```ini
  [program:horizon]
  process_name=%(program_name)s
  command=php /var/www/meetingstore-ops/artisan horizon
  autostart=true
  autorestart=true
  user=www-data
  redirect_stderr=true
  stdout_logfile=/var/log/horizon.log
  stopwaitsecs=3600
  ```
- Add cron entry: `* * * * * cd /var/www/meetingstore-ops && php artisan schedule:run >> /dev/null 2>&1`
- After first deploy: visit `/admin/alert-recipients`, add real ops email addresses, optionally deactivate the seeded `ops@meetingstore.co.uk` fallback (but do not delete — keep as safety net per Pitfall M).

### Dev

- None beyond what Plans 01–04 already require (testing DB created, Herd PHP on PATH).

## Phase 1 — Complete Picture

With Plan 05 complete, **Phase 1 "Foundation"** is DONE. Aggregated delivery across 5 plans:

### Infrastructure
- Laravel 12 + Filament 3 + Horizon + Pulse + Spatie auth stack (Plan 01)
- 9-layer Deptrac module boundary enforcement (Plan 01, tested in Plan 05)
- phpunit.xml DB isolation (Plan 03)
- GitHub Actions CI (Plan 05)

### RBAC
- 4 seeded roles: admin / pricing_manager / sales / read_only (Plan 02)
- Filament Shield plugin + permission tables (Plan 02)
- Admin-only policies pattern (Plans 04 + 05)
- Seeded admin@meetingstore.co.uk for immediate /admin/login (Plan 02)

### Correlation / Audit / Integration
- AttachCorrelationId global middleware (Plan 03)
- Laravel 12 Context::hydrated queue bridge (Pitfall J mitigation — Plan 03)
- DomainEvent abstract base + BaseCommand abstract (Plan 03)
- Auditor service + IntegrationLogger with 6-header redaction (Plan 03)
- integration_events with nullableUlidMorphs subject (Plan 03)

### Seams
- HMAC Woo webhook intake with `(source, delivery_id)` dedup (Plan 04)
- ULID-keyed Suggestions inbox + SuggestionApplier registry + stub applier (Plan 04)
- Shadow-mode WooClient that writes to `sync_diffs` when `WOO_WRITE_ENABLED=false` (Plan 04)
- Domain events: OrderReceived, CustomerRegistered, SuggestionProposed, SuggestionApproved (Plan 04)

### Operations
- 7 Horizon supervisors mapped to 7 named queues (Plan 05)
- Admin-only `/horizon` gate (Plan 05)
- Failed-job alerting with 5-min race-safe dedup (Plan 05)
- DB-backed AlertRecipient distribution list with Filament CRUD (Plan 05)
- 3 retention prunes (activitylog 365d, integration_events 90d, sync_diffs conditional) with meta-audit (Plan 05)

### Test coverage
- 92 passing + 2 skipped tests (Phase 1 end-state)
- 0 Deptrac violations across all 9 domain layers
- Pint + Larastan clean on all new-this-plan files
- CI enforces all four gates on every PR

## Success Criteria (ROADMAP.md Phase 1)

1. **Admin logs in at `/admin/login` and sees role-gated nav** — FOUND-01 (Plan 02). Admin user seeded; ShieldInstallationTest + RoleGatedNavigationTest (9 tests).
2. **Correlation_id threads through webhook → event → audit row end-to-end** — FOUND-03..05 (Plan 03). CorrelationIdPropagationTest + IntegrationLoggerTest + AuditorTest (18 tests).
3. **HMAC-signed Woo webhook acknowledged + deduped + <200ms** — FOUND-07 (Plan 04). WooWebhookTest (8 tests, including the 200ms latency gate).
4. **Seeded test suggestion approve/reject end-to-end** — FOUND-06 (Plan 04). SuggestionInboxTest (15 tests) + SuggestionResourceQueryCountTest (2 tests).
5. **7 queues visible in Horizon + deliberately-failed job triggers admin email** — FOUND-09 + FOUND-11 (Plan 05). HorizonSupervisorTest (9) + FailedJobAlertTest (15).
6. **Deptrac CI blocks a cross-domain PR** — FOUND-02 (Plan 01) + CI wiring (Plan 05). DeptracTest (2) + `.github/workflows/ci.yml`.

**All 6 success criteria are satisfied.**

## Handoff to Phase 2 (Supplier Sync)

### Phase 2 can assume

- `WooClient` skeleton in `app/Domain/Sync/Services/WooClient.php` — replace the `LogicException` throw in `writeOrShadow()` with `$this->inner->put/post/patch/delete(...)` when `WOO_WRITE_ENABLED=true`.
- Supplier / WooClient HTTP calls thread through `IntegrationLogger::log()` (Plan 03) — every request persists in `integration_events` with correlation_id + redacted headers.
- `sync-bulk-supervisor` (1 proc, 1800s timeout) is ready for long-running supplier sync chunks.
- `sync-woo-push-supervisor` (2-3 procs, 90s timeout) respects Woo 100 req/min rate ceiling.
- `competitor-csv-supervisor` (1–2 procs, 600s timeout) ready for Phase 5 competitor CSV drops.
- Failed Phase 2 jobs (e.g., `SyncSupplierPriceJob`, `PushProductToWooJob`) → JobFailed event → ThrottledFailedJobNotifier → email to all active AlertRecipient rows with 5-min dedup.
- `sync-errors:prune` slot is TODO'd in `routes/console.php` — Phase 2 ships the command + the `sync_errors` table migration together.
- `PruneSyncDiffsCommand` will activate automatically when Phase 7 flips `WOO_WRITE_ENABLED=true`.

### Phase 4 (CRM) can assume

- `crm-bitrix-supervisor` respects Bitrix 2 req/sec hard cap (1-2 workers).
- `OrderReceived` + `CustomerRegistered` domain events fire from Plan 04's webhook handler — Phase 4 subscribes with `PushDealToBitrixJob` + `UpsertBitrixContactJob` listeners.
- Failed Bitrix pushes trigger the same alert path; admins see Bitrix outages in email within 5 min.

### Phase 5 (margin suggestions) can assume

- Register `MarginChangeApplier` in `AppServiceProvider::boot()`: one-line `$resolver->register('margin_change', MarginChangeApplier::class);` addition.
- `ApplySuggestionJob` failures fire through ThrottledFailedJobNotifier automatically.
- `competitor-csv:prune` TODO slot ready in `routes/console.php`.

### Known concerns for later phases

1. **Shield regeneration damages 3 policies now** — SuggestionPolicy, AlertRecipientPolicy, RolePolicy. Every future `shield:generate --all` needs a post-grep audit. Candidate architecture test for deferred-items: `grep '\{\{ ' app/Policies/ app/Domain/*/Policies/` should exit non-zero if template literals leak.
2. **CI coverage threshold at `--min=60`** — TODO comment notes raise to `--min=80` after Phase 3. Don't forget to revisit.
3. **Horizon boot assertion suppressed under `testing` env** — dev + prod still enforced, so this is fine, but if someone copies the pattern to Pulse or future supervisor providers they need to do the same `environment('testing')` early-return.
4. **3 deferred prune commands** in `routes/console.php` TODO comments:
   - Phase 2: `sync-errors:prune --days=90` (D-07)
   - Phase 5: `competitor-csv:prune --days=90` (D-06)
5. **`AlertRecipient` has no factory** — future tests creating recipients use `AlertRecipient::create([...])` directly. Phase 2 can ship a factory if bulk fixtures are needed.
6. **CI services block doesn't enforce `appendonly yes` on Redis 7** — prod VPS `/etc/redis/redis.conf` is where the real persistence hardening lives. CI's Redis is in-memory only, which is fine for test runs.

## Self-Check: PASSED

- Created files verified:
  - `database/migrations/2026_04_18_190000_create_alert_recipients_table.php` FOUND
  - `app/Domain/Alerting/Models/AlertRecipient.php` FOUND
  - `app/Domain/Alerting/Notifiables/AlertDistribution.php` FOUND
  - `app/Domain/Alerting/Listeners/ThrottledFailedJobNotifier.php` FOUND
  - `app/Domain/Alerting/Policies/AlertRecipientPolicy.php` FOUND
  - `app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php` FOUND
  - `app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/ListAlertRecipients.php` FOUND
  - `app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/CreateAlertRecipient.php` FOUND
  - `app/Domain/Alerting/Filament/Resources/AlertRecipientResource/Pages/EditAlertRecipient.php` FOUND
  - `app/Domain/Alerting/Jobs/TestFailingJob.php` FOUND
  - `app/Console/Commands/TestFailingJobCommand.php` FOUND
  - `app/Console/Commands/PruneActivityLogCommand.php` FOUND
  - `app/Console/Commands/PruneIntegrationEventsCommand.php` FOUND
  - `app/Console/Commands/PruneSyncDiffsCommand.php` FOUND
  - `app/Providers/EventServiceProvider.php` FOUND
  - `database/seeders/AlertRecipientSeeder.php` FOUND
  - `.github/workflows/ci.yml` FOUND
  - `tests/Feature/HorizonSupervisorTest.php` FOUND
  - `tests/Feature/FailedJobAlertTest.php` FOUND
  - `tests/Feature/RetentionPruneTest.php` FOUND
  - `tests/Architecture/DeptracTest.php` FOUND
- Commits verified via `git log --oneline`:
  - `b58cbb8` FOUND (Task 1: horizon supervisors + admin gate)
  - `435d221` FOUND (Task 2: alerting with dedup)
  - `fafa5aa` FOUND (Task 3: retention prunes)
  - `64c42c8` FOUND (Task 4: CI + DeptracTest)
  - `394baae` FOUND (Pint style fix across new files)
- Test results:
  - `HorizonSupervisor` — 9 pass
  - `FailedJobAlert` — 15 pass
  - `RetentionPrune` — 8 pass
  - `Deptrac` — 2 pass (positive + negative)
  - **Full suite: 92 passed, 2 skipped** (same pre-existing skips from Plan 02)
- `vendor/bin/deptrac analyse --no-progress` — 0 violations, 8 allowed, 204 uncovered (framework noise)
- `php artisan list` includes: `activitylog:prune`, `integration-events:prune`, `sync-diffs:prune`, `alerts:test-failure`
- `php artisan schedule:list` shows all 3 prunes at 03:00 / 03:10 / 03:30 with `--without-overlapping`
- `php artisan db:seed --class=AlertRecipientSeeder --force` — dev DB has 1 recipient (`ops@meetingstore.co.uk`), idempotent
- `php artisan migrate --force` — testing + dev DBs both have `alert_recipients` table
- Manual HorizonServiceProvider gate check: `hasRole('admin')` returns false for read_only/sales/pricing_manager, true for admin
- AlertDistribution route resolution: returns `[email => name]` keyed map of active recipients
- ThrottledFailedJobNotifier atomic lock: second dispatch within 5 min returns without mail
- PruneSyncDiffsCommand with `WOO_WRITE_ENABLED=false`: exits 0, writes `sync-diffs.prune.skipped` meta-audit, preserves all sync_diffs rows

---

*Phase: 01-foundation*
*Plan: 05-horizon-alerting*
*Completed: 2026-04-18*

*Phase 1 complete — foundation ready for Phase 2 supplier sync.*
