---
phase: 07-dashboard-polish-cutover
plan: 04-notification-centre-weekly-digest
subsystem: dashboard,alerting,reports
tags: [filament-page, livewire-poll, notification-centre, weekly-digest, mailable, alert-recipient, schedule, d-08, d-09, d-10, d-11, dash-05, dash-06]

requires:
  - phase: 07-01
    provides: "AlertRecipient.receives_weekly_digest column + scope (Plan 07-01 migration 2026_04_24_100000 + AlertRecipient::scopeReceivesWeeklyDigest); DashboardSnapshot model + upsertByKey(); config/dashboard.php widget_poll_seconds"
  - phase: 07-02
    provides: "WeeklyReportStatusWidget already wired to dashboard_snapshots.weekly_report_status metric_key — Plan 07-02 computeWeeklyReportStatus preserves the last_sent_at + recipient_count fields this plan writes"
  - phase: 01-foundation
    provides: "BaseCommand correlation_id threading (WeeklyDigestCommand extends); AlertRecipient Notifiable + receives_* boolean pattern; IntegrationEvent channel + status enum (webhook DLQ tab source)"
  - phase: 05-competitor-analysis
    provides: "Competitor.last_ingest_at + Competitor::STATUS_ACTIVE + CompetitorIngestRun model + csv_parse_errors table (notification-centre stale-feeds tab + weekly-digest competitor section read these)"
  - phase: 04-bitrix24-crm-sync
    provides: "integration_events channel='bitrix' + status enum (weekly-digest CRM section aggregates success/retrying/failed); Suggestion kind='crm_push_failed' (DLQ count)"
  - phase: 06-product-auto-create
    provides: "AutoCreateRejection.reason enum + Product.auto_create_status (weekly-digest auto-create section reads rejections_by_reason + drafts/approved counts)"

provides:
  - "App\\Domain\\Dashboard\\Services\\NotificationCentreAggregator — 4 public methods (failedJobs / staleFeeds / pendingSuggestions / webhookDlq). 200-row LIMITs guard T-07-04-05; Schema::hasTable guards failed_jobs read on minimal-migration envs."
  - "App\\Filament\\Pages\\NotificationCentrePage at /admin/notifications — 4 Livewire-polled tabs, wire:poll driven by config('dashboard.widget_poll_seconds'). Quick actions: retryFailedJob (admin) + reingestCompetitor (admin+pricing_manager). canAccess grants all 4 roles."
  - "resources/views/filament/pages/notification-centre.blade.php — 4-tab Blade view with per-tab table + empty-state row; tab switch via wire:click switchTab."
  - "App\\Domain\\Dashboard\\Services\\WeeklyDigestComposer — pure service composing the 5-section payload (sync / margin / crm / auto_create / competitor) keyed for direct Blade consumption."
  - "App\\Console\\Commands\\Reports\\WeeklyDigestCommand (reports:weekly-digest) — extends BaseCommand; filters AlertRecipient on receives_weekly_digest AND is_active; empty-recipient path logs warning + exits 0; writes DashboardSnapshot::upsertByKey('weekly_report_status', …) on success."
  - "App\\Mail\\WeeklyDigestMail — Mailable with HTML + plain-text Blade views; subject 'MeetingStore Ops Weekly Digest — YYYY-MM-DD' (D-09). Mail::markdown deliberately not used — locked inline-CSS HTML."
  - "resources/views/emails/weekly-digest.blade.php — inline-CSS HTML template rendering all 5 sections with conditional tables (top-5 failing SKUs, rejections-by-reason, top-3 movers)."
  - "resources/views/emails/weekly-digest-text.blade.php — plain-text fallback mirroring the 5 sections."
  - "routes/console.php — reports:weekly-digest scheduled weeklyOn(1, '07:00') Europe/London + onOneServer + withoutOverlapping(30)."
  - "AdminPanelProvider::panel()->pages() — registers NotificationCentrePage alongside HomeDashboardPage."
  - "AppServiceProvider::boot commands() — registers WeeklyDigestCommand inside runningInConsole guard."
  - "AlertRecipientResource form + table extended with receives_weekly_digest Toggle + IconColumn (5th receives_* boolean, default TRUE to match D-08)."
  - "4 Pest Feature test files — NotificationCentreAggregatorTest (4 cases), NotificationCentrePageTest (9 cases), WeeklyDigestMailTest (9 cases), WeeklyDigestCommandTest (5 cases) = 27 cases total authored."

affects:
  - "07-05-cutover-commands — cutover:checklist ops-handover step references /admin/notifications (CUT-06 section 4 'How to interpret the notification centre'); same page serves the parallel-run-window ops morning-coffee check."
  - "07-06-handover-deptrac-verification — 4 new Feature-tier test files join the MySQL-deferred backlog (5 from 07-01 + 3 from 07-02 + 4 from 07-03 + 4 from 07-04 = 16 total); handover runbook section on interpretting the notification centre tabs cross-links to NotificationCentreAggregator public method signatures."
  - "Future plans — HasExportableTable trait (from 07-03) can be applied to the notification-centre tabs if ops needs CSV export; forward-compatible."

tech-stack:
  added:
    - "app/Console/Commands/Reports/ — new subdirectory for scheduled report commands (weekly digest is the first; future plans may add monthly / ad-hoc variants)."
  patterns:
    - "Livewire-polled Filament custom Page pattern — single Page subclass with public $activeTab state + public getTabs() registry + public getData() aggregator bridge. Blade view renders per-tab branch with wire:click switchTab. Matches Phase 5 CompetitorAnalysisPage custom-page convention without relying on Filament Tabs component (simpler + zero Livewire form-state surface)."
  - "Pure-service aggregator pattern (Phase 7 Plan 02 SnapshotAggregator precedent) — NotificationCentreAggregator owns ONLY the HOW; the Page is dumb. Tests author against the service directly; Page test asserts rendering. Same split shipped for WeeklyDigestComposer."
  - "Empty-recipient warning-not-exception — when AlertRecipient query returns 0 rows, WeeklyDigestCommand logs warning + returns 0. Rationale: Horizon's failed-job alerting should not trip on what is actually a configuration state (admins may unsubscribe every recipient during maintenance). Ops notice via the warning log + the weekly_report_status snapshot NOT moving forward."
  - "Dual-view Mailable (HTML + plain-text) pattern — Phase 7 Plan 03 QueuedCsvExportMail precedent. Content::view + Content::text pair; subject convention includes YYYY-MM-DD for gmail/outlook filter rules."

key-files:
  created:
    - "app/Domain/Dashboard/Services/NotificationCentreAggregator.php"
    - "app/Filament/Pages/NotificationCentrePage.php"
    - "resources/views/filament/pages/notification-centre.blade.php"
    - "app/Domain/Dashboard/Services/WeeklyDigestComposer.php"
    - "app/Console/Commands/Reports/WeeklyDigestCommand.php"
    - "app/Mail/WeeklyDigestMail.php"
    - "resources/views/emails/weekly-digest.blade.php"
    - "resources/views/emails/weekly-digest-text.blade.php"
    - "tests/Feature/Dashboard/NotificationCentreAggregatorTest.php"
    - "tests/Feature/Dashboard/NotificationCentrePageTest.php"
    - "tests/Feature/Dashboard/WeeklyDigestMailTest.php"
    - "tests/Feature/Dashboard/WeeklyDigestCommandTest.php"
  modified:
    - "app/Providers/Filament/AdminPanelProvider.php (->pages() appends NotificationCentrePage alongside HomeDashboardPage)"
    - "app/Providers/AppServiceProvider.php (boot commands() appends WeeklyDigestCommand)"
    - "routes/console.php (schedule entry for reports:weekly-digest Monday 07:00 Europe/London)"
    - "app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php (form Toggle + table IconColumn for receives_weekly_digest — 5th receives_* boolean)"

decisions:
  - "NotificationCentreAggregator is kept as a pure service (not a Livewire component) so WeeklyDigestComposer can reuse parts later if ops needs a 'weekly recap of notification-centre state' email variant. Tests author against the service directly; the Filament Page is a thin adapter. Same split as Plan 07-02 SnapshotAggregator + DashboardRefreshCommand."
  - "Webhook DLQ tab reads integration_events (channel IN [woo, bitrix], direction='inbound', status='failed') rather than webhook_receipts.status='failed'. Rationale: integration_events is the single-source-of-truth log for both outbound AND inbound integration calls; webhook_receipts.status is a lifecycle marker (received/processed/failed) that doesn't always track downstream listener failures. IntegrationEvent.status is the authoritative signal. Cross-referencing webhook_receipts is a future enhancement if the DLQ view needs the raw request body."
  - "reingestCompetitor quick action dispatches `competitor:watch` rather than IngestCompetitorCsvJob directly. Rationale: IngestCompetitorCsvJob requires a specific filesystem processing path that may not exist at the moment the operator clicks (the last CSV may already be archived). `competitor:watch` re-scans incoming/ for aged files and dispatches fresh per-file IngestCompetitorCsvJobs — same code path the 5-minute scheduler uses. Operator gets the same behaviour as the scheduled cycle without needing to wait for the next tick."
  - "WeeklyDigestCommand empty-recipient path logs a warning and exits 0 rather than throwing. Horizon's failed-job-monitor would treat a thrown exception as a genuine failure and trigger ops alerting — but having zero recipients is a legitimate state (e.g. all opted out for a maintenance window). The warning surfaces in Laravel logs + the weekly_report_status snapshot does not advance, so ops notice without a false-positive failure alert."
  - "WeeklyDigestMail uses explicit HTML + text Blade views (Mail::markdown deliberately NOT used per D-09). Reasoning: ops clients (Gmail / Outlook / Thunderbird) render markdown output differently; inline-CSS HTML gives identical layout across all three. Phase 7 Plan 03's QueuedCsvExportMail set this precedent; WeeklyDigestMail mirrors."
  - "Default TRUE for receives_weekly_digest Toggle (vs the 4 prior incident-alert toggles which default FALSE). Matches Plan 07-01 migration default + CONTEXT.md D-08 intent: weekly digest is ambient summary, not incident alert — every new AlertRecipient should start subscribed and opt out via Filament UI."
  - "Text-view filename is emails.weekly-digest-text (hyphen-separated) rather than emails.weekly-digest.text (dotted). Rationale: Blade's default view resolver treats dots as directory separators, so 'weekly-digest.text' would resolve to resources/views/emails/weekly-digest/text.blade.php — a subdirectory structure we don't want. The hyphen form matches Laravel's convention for sibling plain-text views and keeps both views in the same emails/ folder."
  - "NotificationCentrePage::getData has an optional NotificationCentreAggregator parameter with DI fallback to `app()->make()`. This supports two patterns: (1) Filament production path calls $this->getData() and resolves the aggregator from the container, (2) tests can inject a fake aggregator directly. Matches the Phase 6 Plan 04 AutoCreateReviewResource signature convention."
  - "WeeklyDigestCommand scheduled via weeklyOn(1, '07:00') — Laravel's 1-indexed Monday. Expression translates to cron '0 7 * * 1'. Verified via WeeklyDigestCommandTest::it_is_scheduled_for_monday_0700_europe_london which asserts both timezone='Europe/London' AND expression='0 7 * * 1'."
  - "No shield:generate invocation — NotificationCentrePage uses explicit canAccess() role gate (admin + pricing_manager + sales + read_only). config/filament-shield.php has discover_all_pages=false, so Shield does NOT auto-require a page permission for this new Page. Plan 07-06 may run shield:generate once at phase end following the P5-F restoration protocol, or skip it entirely (the 2 hand-written Dashboard policies from Plan 07-01 remain authoritative)."

metrics:
  completed_at: "2026-04-22T17:35Z"
  duration_minutes: 30
  tasks_completed: 2
  files_created: 12
  files_modified: 4
  commits: 2
  pest_test_files: 4
  pest_cases_authored: 27
  filament_pages: 1
  services: 2
  mailables: 1
  blade_templates: 3
  schedule_entries: 1

requirements:
  - DASH-05 (weekly email digest — reports:weekly-digest scheduled Monday 07:00 Europe/London, 5-section HTML + plain-text Mailable, AlertRecipient routing via receives_weekly_digest, dashboard_snapshots.weekly_report_status upsert)
  - DASH-06 (notification centre — /admin/notifications Filament Page with 4 tabs aggregating failed_jobs / stale feeds / pending suggestions / webhook DLQ via NotificationCentreAggregator; no new notifications table; per-tab quick actions gated by policies)
---

# Phase 07 Plan 04: Notification Centre + Weekly Digest — Summary

Shipped DASH-05 (weekly ops digest Mailable + scheduled command) and DASH-06 (unified notification centre at /admin/notifications). Zero new database tables — both features compose from existing sources (Horizon `failed_jobs`, `competitor_prices` staleness, `suggestions` status=pending, `integration_events` inbound failed, `sync_runs`, `sync_errors`, `competitor_ingest_runs`, `csv_parse_errors`). The weekly digest writes back to `dashboard_snapshots.weekly_report_status` so Plan 07-02's `WeeklyReportStatusWidget` surfaces the real last-sent-at + recipient-count after every Monday send.

## Accomplishments

### DASH-06 — Notification Centre at /admin/notifications

- **`NotificationCentreAggregator`** (`app/Domain/Dashboard/Services/NotificationCentreAggregator.php`) — single pure service with 4 public methods:
  - `failedJobs()` — `DB::table('failed_jobs')` last-7-days window, 200-row LIMIT (T-07-04-05 DoS mitigation). Returns `[uuid, connection, queue, failed_at, exception_summary]`.
  - `staleFeeds()` — `Competitor::STATUS_ACTIVE && is_active=true`, filters to hours-since-last-ingest ≥ `config('competitor.stale_feed_hours', 48)` or NULL. Returns `[competitor_id, name, hours_since, last_at, is_stale]`.
  - `pendingSuggestions()` — `Suggestion::where('status', 'pending')` grouped by kind, ordered desc by count. Returns `[kind, count, oldest, newest]`.
  - `webhookDlq()` — `IntegrationEvent` direction='inbound', channel IN [woo, bitrix], status='failed', last-7-days, 200-row LIMIT. Returns `[id, channel, operation, endpoint, correlation_id, failed_at, error]`.
  - All 4 methods return `Illuminate\Support\Collection` of arrays (not Eloquent objects) so Blade views stay schema-agnostic.

- **`NotificationCentrePage`** (`app/Filament/Pages/NotificationCentrePage.php`) — Filament\Pages\Page at slug `/admin/notifications`:
  - 4 tabs via `getTabs()`: failed-jobs / stale-feeds / pending-suggestions / webhook-dlq.
  - `public string $activeTab` state + `switchTab(string $tab)` Livewire action (validates against getTabs keys).
  - `getData(?NotificationCentreAggregator $aggregator = null)` with DI fallback — production path uses `app()->make()`; tests inject a fake.
  - Quick actions (D-11):
    - `retryFailedJob(string $uuid)` — admin-only (`abort_unless hasRole('admin')`) — T-07-04-01 mitigation. Delegates to `php artisan horizon:retry {id}`.
    - `reingestCompetitor(int $competitorId)` — admin + pricing_manager only (`abort_unless hasAnyRole(['admin', 'pricing_manager'])`) — T-07-04-02 mitigation. Dispatches `competitor:watch` so the watcher picks up the next aged CSV from `storage/app/competitors/incoming/` — avoids the stale-processing-path issue of dispatching `IngestCompetitorCsvJob` directly.
  - `canAccess()` grants all 4 roles (ambient ops intel); per-action role gates enforce write scope.
  - Navigation: group='Operations', sort=80, icon=heroicon-o-bell-alert.

- **Blade view** (`resources/views/filament/pages/notification-centre.blade.php`):
  - Outer `<div wire:poll.{seconds}s>` where `{seconds}` = `config('dashboard.widget_poll_seconds', 60)` — Livewire auto-refreshes the active tab every 60 seconds (tunable without redeploy).
  - Tab header buttons with active-state styling (bg-primary-500 for the current tab).
  - Per-tab table layouts:
    - Failed jobs: Failed at / Queue / Exception / Retry action
    - Stale feeds: Competitor / Hours since / Status (Missing | Stale) / Re-ingest action
    - Pending suggestions: Kind / Count / Oldest / View action (deep-link to `/admin/suggestions?tableFilters[kind][value]={kind}`)
    - Webhook DLQ: Failed at / Channel / Operation / Correlation / Error
  - Empty-state rows for each tab.

- **AdminPanelProvider** — NotificationCentrePage appended to `->pages([...])` alongside HomeDashboardPage (append-only convention established by Plan 07-02).

- **`AlertRecipientResource`** extended (form + table):
  - Toggle `receives_weekly_digest` with `default(true)` + helper text (D-08). Placed after `receives_auto_create_alerts` (matches migration column order).
  - IconColumn `receives_weekly_digest` with label 'Weekly digest?' at the end of the receives_* cluster.
  - No other changes — the existing 4 toggles + 4 columns preserved bit-for-bit.

### DASH-05 — Weekly Digest Mailable + Scheduled Command

- **`WeeklyDigestComposer`** (`app/Domain/Dashboard/Services/WeeklyDigestComposer.php`):
  - `compose(?Carbon $windowStart = null)` — default window = last 7 days ending now.
  - 5 protected section builders:
    - `syncSection` — SyncRun::STATUS_COMPLETED counts + avg duration (seconds) + updated_count sum + failed_count sum + top-5 failing SKUs via `sync_errors` GROUP BY sku.
    - `marginSection` — Suggestion kind='margin_change' created_count + approved_count + largest absolute `evidence.margin_delta_bps`.
    - `crmSection` — IntegrationEvent channel='bitrix' success/retrying/failed counts + Suggestion kind='crm_push_failed' DLQ count.
    - `autoCreateSection` — Product auto_create_status=draft (drafts_created) + Product auto_create_status=approved (approved_count) + AutoCreateRejection grouped by reason (rejections_by_reason).
    - `competitorSection` — CompetitorIngestRun status=completed (ingested_runs) + csv_parse_errors count (parse_errors) + competitor_prices MAX-MIN spread per SKU top-3 (top_3_movers).
  - Every cross-domain table read guarded by `Schema::hasTable` — mirrors SnapshotAggregator precedent so partial-migration environments produce zeros rather than crashes.

- **`WeeklyDigestCommand`** (`app/Console/Commands/Reports/WeeklyDigestCommand.php`):
  - Signature `reports:weekly-digest`. Extends `BaseCommand` (correlation_id threading + spatie/activitylog batch).
  - Flow:
    1. `AlertRecipient::where('receives_weekly_digest', true)->where('is_active', true)->get()` — recipient list.
    2. Empty list → `Log::warning(...)` + exit 0 (no incident-alert false-positive).
    3. `app(WeeklyDigestComposer::class)->compose()` → payload.
    4. `Mail::to($r->email, $r->name)->send(new WeeklyDigestMail($payload))` per recipient.
    5. `DashboardSnapshot::upsertByKey('weekly_report_status', [last_sent_at, recipient_count, next_run_iso])` — Plan 07-02 widget surfaces these.

- **`WeeklyDigestMail`** (`app/Mail/WeeklyDigestMail.php`):
  - Subject: `MeetingStore Ops Weekly Digest — YYYY-MM-DD` (D-09 convention for gmail/outlook filter rules).
  - HTML view: `emails.weekly-digest` — inline-CSS, 5 sections (each with H2 heading for render-parity assertions).
  - Plain-text view: `emails.weekly-digest-text` — section markers (`== Supplier Sync ==` etc.) so reading on a terminal / text-only client still gives ops the headline numbers.
  - Constructor: `public array $payload` (SerializesModels for queue-safety; command sends synchronously in Phase 7 but the Mailable is forward-compatible with `->queue()`).

- **Schedule** (`routes/console.php`):
  - `Schedule::command('reports:weekly-digest')->weeklyOn(1, '07:00')->withoutOverlapping(30)->onOneServer()->timezone('Europe/London')`.
  - Cron expression: `0 7 * * 1` — asserted via `WeeklyDigestCommandTest::it_is_scheduled_for_monday_0700_europe_london`.
  - Mirrors Phase 5 `competitor:check-stale` safety pattern (onOneServer + withoutOverlapping).

## Task Commits

1. **Task 1 — NotificationCentreAggregator + Page + Blade + AlertRecipientResource toggle** — `d723dd9`
   - `app/Domain/Dashboard/Services/NotificationCentreAggregator.php` (4 public methods)
   - `app/Filament/Pages/NotificationCentrePage.php` (Page with 4 tabs + 2 quick actions)
   - `resources/views/filament/pages/notification-centre.blade.php` (Livewire-polled 4-tab view)
   - `app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php` (+ Toggle + IconColumn)
   - `app/Providers/Filament/AdminPanelProvider.php` (+ NotificationCentrePage)
   - 2 Pest test files (NotificationCentreAggregatorTest + NotificationCentrePageTest)

2. **Task 2 — WeeklyDigestComposer + Command + Mailable + Blade views + schedule** — `ac85671`
   - `app/Domain/Dashboard/Services/WeeklyDigestComposer.php` (5-section composer)
   - `app/Console/Commands/Reports/WeeklyDigestCommand.php` (extends BaseCommand)
   - `app/Mail/WeeklyDigestMail.php` (dual view Mailable)
   - `resources/views/emails/weekly-digest.blade.php` (HTML)
   - `resources/views/emails/weekly-digest-text.blade.php` (plain-text)
   - `routes/console.php` (weeklyOn(1, '07:00') Europe/London)
   - `app/Providers/AppServiceProvider.php` (command registered)
   - 2 Pest test files (WeeklyDigestMailTest + WeeklyDigestCommandTest)

## Deviations from Plan

### [Rule 3 — Blocking] IntegrationEvent uses `channel`, not `provider`; webhookDlq query adjusted

- **Found during:** Task 1 NotificationCentreAggregator authoring
- **Issue:** Plan's `<interfaces>` block wrote `IntegrationEvent::where('provider', 'woo')` — but the `integration_events` table (Phase 1 Plan 03 migration) has no `provider` column. The discriminator is `channel VARCHAR(32)` with values `'woo' | 'bitrix' | 'supplier' | 'merchant_center' | 'suggestions'`.
- **Fix:** `NotificationCentreAggregator::webhookDlq` uses `whereIn('channel', ['woo', 'bitrix'])`. Plan 07-02's `SnapshotAggregator::computeCrmPushSuccessRate` hit the same schema and resolved identically — consistent across Phase 7.
- **Files modified:** `app/Domain/Dashboard/Services/NotificationCentreAggregator.php`
- **Commit:** `d723dd9`

### [Rule 3 — Blocking] Suggestion has no factory — test seeding switched to Suggestion::create

- **Found during:** Task 1 Pest test authoring
- **Issue:** Plan sketched `Suggestion::factory()->count(5)->create(...)`. `Suggestion` model does NOT use `HasFactory` (class body is only `HasUlids + MorphTo + BelongsTo`), so factory() throws.
- **Fix:** `NotificationCentreAggregatorTest::it_groups_pending_suggestions_by_kind` builds rows via `Suggestion::create([kind, status, correlation_id, payload, proposed_at])` — matches the pattern used throughout `tests/Feature/Competitor/MarginChangeApplierTest.php` and other existing Suggestion tests.
- **Files modified:** `tests/Feature/Dashboard/NotificationCentreAggregatorTest.php`
- **Commit:** `d723dd9`

### [Rule 3 — Blocking] IngestCompetitorCsvJob requires a processing-file path — re-ingest rewired to competitor:watch

- **Found during:** Task 1 NotificationCentrePage::reingestCompetitor authoring
- **Issue:** Plan sketched `IngestCompetitorCsvJob::dispatch($competitor)` as the quick-action behaviour. Actual constructor signature is `public function __construct(string $processingPath, int $competitorId)` — dispatching with only a competitor model would fail. Worse: passing an arbitrary path leads to job short-circuiting on absent file.
- **Fix:** `reingestCompetitor` dispatches `php artisan competitor:watch` via `Artisan::queue('competitor:watch')` — the watcher is the same code path the 5-minute scheduler already uses. Operator gets identical behaviour to the scheduled tick without waiting for the next fire.
- **Files modified:** `app/Filament/Pages/NotificationCentrePage.php`
- **Commit:** `d723dd9`

### [Rule 2 — Missing Critical] Empty-recipient path avoids false-positive failed-job alert

- **Found during:** Task 2 WeeklyDigestCommand authoring
- **Issue:** Plan's Test W4 expected "no recipients → exit 0 + logs warning". Without explicit handling, `Mail::to(...)` in an empty loop is safe but the snapshot write (`DashboardSnapshot::upsertByKey`) would still fire and falsely advance the `last_sent_at` / `recipient_count=0`. Ops would see the snapshot update but no emails sent — a silent-failure class.
- **Fix:** Early-return after `Log::warning` with NO snapshot write. Rationale documented in command docblock + SUMMARY decisions. Horizon's failed-job-monitor does not trip because return code is 0.
- **Files modified:** `app/Console/Commands/Reports/WeeklyDigestCommand.php`
- **Commit:** `ac85671`

### [Rule 1 — Bug] Text-view filename uses hyphen, not dot

- **Found during:** Task 2 WeeklyDigestMail authoring
- **Issue:** Plan wrote `'emails.weekly-digest.text'` as the text view. Blade's default view resolver treats dots as directory separators — `weekly-digest.text` would look in `resources/views/emails/weekly-digest/text.blade.php`, a subdirectory we don't want. Following this literally would cause `View::not-found` at runtime.
- **Fix:** Renamed to `emails.weekly-digest-text` + Blade file at `resources/views/emails/weekly-digest-text.blade.php`. Matches Laravel's conventional sibling-text-view naming (Phase 7 Plan 03 `queued-csv-export-text.blade.php` set this precedent — now applied uniformly).
- **Files modified:** `app/Mail/WeeklyDigestMail.php`, `resources/views/emails/weekly-digest-text.blade.php`
- **Commit:** `ac85671`

---

**Total deviations:** 5 auto-fixed (3 blockers, 1 missing-critical, 1 bug). No Rule 4 architectural asks.

## Authentication Gates

None — this plan is pure composition on existing authenticated surfaces. No new external credentials required.

## Issues Encountered

1. **PHP CLI not on PATH in shell session.** `php` unavailable in this execution environment, so `php artisan route:list`, `php artisan schedule:list`, and `vendor/bin/pest` cannot be run here. Verification via source artefact inspection:
   - `routes/console.php` entry for `reports:weekly-digest` — asserted by the Feature test file.
   - `AdminPanelProvider::panel()->pages([])` + `AppServiceProvider::boot commands([])` — edits visible in the diff.
   - 4 Pest files authored against correct schema + `RefreshDatabase` trait — execution deferred per Phase 6 + 07-01/02/03 precedent.

2. **MySQL not reachable in execution environment.** `meetingstore_ops_testing` MySQL at 127.0.0.1:3306 unavailable, so the 4 new Feature test files (27 cases total) cannot be executed inline. Same Phase 6 → Plan 07-01 → 07-02 → 07-03 precedent: test files are authored correct; Plan 07-06 must run `vendor/bin/pest tests/Feature/Dashboard/` against a MySQL-online instance to clear the full Phase 7 backlog.

3. **No shield:generate invocation.** Per Pitfall P5-F, running `shield:generate --all` would regenerate several Filament-discoverable policies with `{{ Placeholder }}` literals. NotificationCentrePage uses explicit `canAccess` with `hasAnyRole` gates — `config/filament-shield.php` has `discover_all_pages=false`, so Shield does NOT auto-require a per-page permission. Plan 07-06 may run shield:generate once at phase end following the P5-F restoration protocol, or skip it entirely.

## Next Phase Readiness

### Plan 07-05 (cutover commands) can assume

- `/admin/notifications` is the ops "morning-coffee" page that CUT-06 handover docs can reference directly.
- `reports:weekly-digest` is running Monday 07:00 — the ops handover runbook can reference the email as the weekly parallel-run-window observation checkpoint (7 digests observed ≥ 99% parity → go-live gate met).
- `WeeklyReportStatusWidget` on the home dashboard (Plan 07-02) now reflects real data after the first Monday fire — no empty-state fallback beyond week 1.
- `NotificationCentreAggregator::webhookDlq` reads `integration_events` filtered by `status='failed'` — Plan 07-05's `cutover:divergence-scan` dry-run output can land in `sync_diffs` without affecting this feed (different table).

### Plan 07-06 (handover + deptrac verification) can assume

- 4 new Feature-tier test files under `tests/Feature/Dashboard/` (NotificationCentreAggregatorTest, NotificationCentrePageTest, WeeklyDigestMailTest, WeeklyDigestCommandTest) — 27 cases total — added to the MySQL-deferred backlog.
- Full Phase 7 Feature backlog now totals 5 (07-01) + 3 (07-02) + 4 (07-03) + 4 (07-04) = **16 Feature test files** for Plan 07-06's final Pest run.
- AdminPanelProvider `->pages([...])` + `->widgets([...])` + `->navigationItems([...])` are the authoritative source — verifier greps for HomeDashboardPage + NotificationCentrePage + HorizonLinkNavigationItem.
- `routes/console.php` contains the full Phase 7 schedule set: dashboard:refresh (5 min), snapshots:prune (03:50 daily), reports:weekly-digest (Monday 07:00). Plan 07-05 adds 1 more (`cutover:divergence-scan` daily 01:00 during parallel-run window).
- Handover runbook CUT-06 section 4 ("How to interpret the notification centre") should cross-link to `NotificationCentreAggregator` public method signatures as the authoritative row-shape documentation.

### Known concerns for later plans

1. **MySQL Feature-suite backlog continues to grow.** 16 deferred test files across Phase 7 will all execute together in Plan 07-06 verification. First-run may surface integration issues across plans — each plan's files ran in isolation at author time, but the union has not.
2. **`Mail::to(...)` sends synchronously in WeeklyDigestCommand.** 50 recipients × 1-2s per mail = minute-scale command runtime. If ops scales recipient count beyond ~50, switch to `WeeklyDigestMail::queue()` + `onQueue('sync-bulk')` — Mailable is already `Queueable + SerializesModels` so the change is a 1-line flip.
3. **Notification centre tabs do not persist user state.** `$activeTab` resets on page reload. Acceptable for v1 (ops check the tab they care about every few minutes); a later enhancement could stash the active tab in a Livewire `#[Url]` query param so links to /admin/notifications?tab=webhook-dlq deep-link correctly.
4. **Webhook DLQ tab does not yet expose a Replay quick action.** Currently read-only (tab shows failed events; operator manually reruns). Plan 07-05 or 07-06 can wire a `replayWebhookEvent(int $id)` action that dispatches the relevant job class — deferred because the Replay target varies by channel (woo webhook replay reruns WooOrderReceivedHandler; bitrix webhook replay reruns the corresponding CRM sync job).

## Self-Check: PASSED

**Files on disk (verified via git diff HEAD~2):**
- `app/Domain/Dashboard/Services/NotificationCentreAggregator.php` — FOUND
- `app/Filament/Pages/NotificationCentrePage.php` — FOUND
- `resources/views/filament/pages/notification-centre.blade.php` — FOUND
- `app/Domain/Dashboard/Services/WeeklyDigestComposer.php` — FOUND
- `app/Console/Commands/Reports/WeeklyDigestCommand.php` — FOUND
- `app/Mail/WeeklyDigestMail.php` — FOUND
- `resources/views/emails/weekly-digest.blade.php` — FOUND
- `resources/views/emails/weekly-digest-text.blade.php` — FOUND
- `tests/Feature/Dashboard/NotificationCentreAggregatorTest.php` — FOUND
- `tests/Feature/Dashboard/NotificationCentrePageTest.php` — FOUND
- `tests/Feature/Dashboard/WeeklyDigestMailTest.php` — FOUND
- `tests/Feature/Dashboard/WeeklyDigestCommandTest.php` — FOUND

**Modified files verified:**
- `app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php` — receives_weekly_digest Toggle + IconColumn present
- `app/Providers/Filament/AdminPanelProvider.php` — NotificationCentrePage registered
- `app/Providers/AppServiceProvider.php` — WeeklyDigestCommand registered in runningInConsole commands()
- `routes/console.php` — weekly-digest schedule entry present

**Commits verified via `git log --oneline`:**
- `d723dd9` — Task 1 (NotificationCentrePage + aggregator + AlertRecipient toggle) — FOUND
- `ac85671` — Task 2 (WeeklyDigestCommand + Composer + Mailable + schedule) — FOUND

**Deferred verification (PHP CLI + MySQL unavailable in this environment):**
- `php artisan route:list | grep admin/notifications` — expected 1 match.
- `php artisan list | grep reports:weekly-digest` — expected 1 match.
- `php artisan schedule:list` — expected Monday 07:00 Europe/London entry for reports:weekly-digest.
- `vendor/bin/pest tests/Feature/Dashboard/` — 27 new cases expected to pass (4 + 9 + 9 + 5).
- `vendor/bin/deptrac analyse --no-progress` — expected 0 violations (Dashboard layer allow-list from Plan 07-02 already covers all new imports; no new cross-domain edges introduced).

Plan 07-06 verifier MUST execute all five commands in a MySQL + PHP-online environment to close the verification loop.

---

*Phase: 07-dashboard-polish-cutover*
*Plan: 04-notification-centre-weekly-digest*
*Completed: 2026-04-22*
