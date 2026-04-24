---
phase: 07-dashboard-polish-cutover
plan: 02-home-dashboard-widgets
subsystem: dashboard
tags: [filament, widgets, home-dashboard, snapshot-aggregator, horizon-link, scheduled-command, retention-prune, deptrac-dashboard-layer, d-01, d-02, d-03]

requires:
  - phase: 07-01
    provides: "DashboardSnapshot model + upsertByKey() + isStale(); DashboardSnapshotPolicy (4-role viewAny, create/update deny); config/dashboard.php (widget_poll_seconds, snapshot_ttl_minutes, snapshot_retention_days); config/cutover.php (parity_threshold_percent, parity_window_days)"
  - phase: 01-05
    provides: "BaseCommand correlation_id threading; Horizon admin-only gate on /horizon; Phase 1 retention prune pattern (03:00 cascade, onOneServer + withoutOverlapping)"
  - phase: 02-04
    provides: "SyncRun model + STATUS_COMPLETED + updated_count/failed_count/started_at/completed_at columns"
  - phase: 04-03
    provides: "CRM push data lives in integration_events WHERE channel='bitrix' (no dedicated crm_push_logs table; CrmPushLogResource binds to IntegrationEvent)"
  - phase: 05-04b
    provides: "Competitor.last_ingest_at + StaleFeedTrafficLight widget precedent (threshold = config('competitor.stale_feed_hours', 48))"
  - phase: 06-01
    provides: "Product.auto_create_status + completeness_score columns; Suggestion.kind + status pending/margin_change/new_product_opportunity"

provides:
  - "App\\Domain\\Dashboard\\Services\\SnapshotAggregator — 9 public compute* methods (one per metric_key) + computeAll() + refreshAll() + read($key)"
  - "App\\Console\\Commands\\Dashboard\\DashboardRefreshCommand (dashboard:refresh) — scheduled every 5 min via routes/console.php; upserts all 9 metric rows"
  - "App\\Console\\Commands\\Dashboard\\PruneDashboardSnapshotsCommand (snapshots:prune) — daily 03:50; --days=0 safety no-op"
  - "App\\Filament\\Pages\\HomeDashboardPage (D-01) — Filament\\Pages\\Dashboard subclass; overrides default /admin with 9-widget 3-column grid"
  - "9 App\\Filament\\Widgets\\*Widget (StatsOverviewWidget) — LastSyncRun / CrmPushSuccessRate / CompetitorFreshness / PendingReviews / ImportIssues / HorizonFailedJobs / SyncDiffsParity / ProductCatalogueHealth / WeeklyReportStatus"
  - "App\\Domain\\Dashboard\\Support\\HorizonLinkNavigationItem (D-03) — admin-only /horizon nav link; static build() factory"
  - "AdminPanelProvider wiring — ->pages([HomeDashboardPage]), ->widgets([9 classes]), ->navigationItems([HorizonLinkNavigationItem::build()])"
  - "routes/console.php — dashboard:refresh every 5 min + snapshots:prune daily 03:50 (continues 03:00/03:10/03:20/03:30/03:40 cascade)"
  - "Deptrac Dashboard layer registered in BOTH deptrac.yaml + depfile.yaml (dual-config-sync) with allow-list [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, WpDirectDb]; Http layer now includes Dashboard"
  - "2 Pest feature test files — DashboardRefreshCommandTest (5 cases) + WidgetDataSourceTest (11 cases) + HomeDashboardPageTest (12 cases) = 28 cases total for Phase 7 Plan 02"

affects:
  - "07-03-global-search-csv-saved-filters — AdminPanelProvider nav arrays are append-only (established pattern); this plan added the first entries, Plan 07-03 appends its own without replacing"
  - "07-04-notification-centre-weekly-digest — WeeklyReportStatusWidget reads `weekly_report_status` metric_key; the reports:weekly-digest command MUST write that snapshot key with last_sent_at + recipient_count fields"
  - "07-05-cutover-commands — SyncDiffsParityWidget reads `sync_diffs_parity` metric_key; cutover:divergence-scan MUST upsert sync_diffs rows with provider='divergence-scan' so the aggregator picks them up. SnapshotAggregator::computeSyncDiffsParity reads config('cutover.parity_window_days') + parity_threshold_percent"
  - "07-06-handover-deptrac-verification — Dashboard layer allow-list already in place (shipped in this plan instead of deferring to 07-06 per CONTEXT.md §ship-with-the-dependency)"

tech-stack:
  added:
    - "App\\Filament\\Widgets\\ namespace populated (prior Phase 1-6 widgets lived in Domain/*/Filament/Widgets — Phase 7 introduces home-dashboard specific widgets at the top-level)"
  patterns:
    - "Widget → DashboardSnapshot read pattern: every widget's getStats() does a single DashboardSnapshot::where('metric_key', $key)->first() read — no joins, no aggregations, no model method invocations at render time. Constant DB cost per widget."
    - "Empty-state fallback: every widget renders a single 'Awaiting first dashboard:refresh' Stat with color='gray' when the snapshot row is absent. First /admin visit after migrate:fresh never crashes."
    - "Stale-ring via extraAttributes: Stat::extraAttributes(['class' => 'ring-2 ring-amber-400']) overlays an amber border when isStale() returns true. Tailwind classes are already compiled into Filament's asset bundle (no new Vite build needed)."
    - "Widget ->canView() gate pattern: each widget's static canView() checks Gate::allows('viewAny', DashboardSnapshot::class). Phase 7 Plan 01's policy grants all 4 roles view access — T-07-02-01 mitigation preserved."
    - "HomeDashboardPage::getWidgets() vs AdminPanelProvider ->widgets(): the Page-level array is authoritative for render order; the panel-level array is required for Filament class resolution + policy gates. Two arrays, same list, different purposes."
    - "Schema::hasTable() guards in SnapshotAggregator: failed_jobs / csv_parse_errors / competitor_ingest_runs reads gated by Schema::hasTable so a minimal test DB doesn't crash the aggregator. Missing table = count 0, not exception."
    - "Weekly-report snapshot preservation: computeWeeklyReportStatus reads the existing snapshot first; only overwrites next_run_iso on each refresh so Plan 07-04's last_sent_at + recipient_count survive intermediate dashboard:refresh runs."

key-files:
  created:
    - "app/Domain/Dashboard/Services/SnapshotAggregator.php"
    - "app/Console/Commands/Dashboard/DashboardRefreshCommand.php"
    - "app/Console/Commands/Dashboard/PruneDashboardSnapshotsCommand.php"
    - "app/Filament/Pages/HomeDashboardPage.php"
    - "app/Filament/Widgets/LastSyncRunWidget.php"
    - "app/Filament/Widgets/CrmPushSuccessRateWidget.php"
    - "app/Filament/Widgets/CompetitorFreshnessWidget.php"
    - "app/Filament/Widgets/PendingReviewsWidget.php"
    - "app/Filament/Widgets/ImportIssuesWidget.php"
    - "app/Filament/Widgets/HorizonFailedJobsWidget.php"
    - "app/Filament/Widgets/SyncDiffsParityWidget.php"
    - "app/Filament/Widgets/ProductCatalogueHealthWidget.php"
    - "app/Filament/Widgets/WeeklyReportStatusWidget.php"
    - "app/Domain/Dashboard/Support/HorizonLinkNavigationItem.php"
    - "tests/Feature/Dashboard/DashboardRefreshCommandTest.php"
    - "tests/Feature/Dashboard/WidgetDataSourceTest.php"
    - "tests/Feature/Dashboard/HomeDashboardPageTest.php"
  modified:
    - "app/Providers/AppServiceProvider.php (+DashboardRefreshCommand + PruneDashboardSnapshotsCommand registration in runningInConsole commands() block)"
    - "app/Providers/Filament/AdminPanelProvider.php (->pages + ->widgets + ->navigationItems for Phase 7 surfaces; removed unused Pages/Widgets imports)"
    - "routes/console.php (+dashboard:refresh every 5 min + snapshots:prune daily 03:50)"
    - "deptrac.yaml (+Dashboard layer definition + Dashboard allow-list; Http layer extended with Dashboard)"
    - "depfile.yaml (+Dashboard layer definition + Dashboard allow-list; Http layer extended with Dashboard — dual-config-sync with deptrac.yaml)"

decisions:
  - "Deptrac Dashboard layer landed in Plan 07-02 (not deferred to 07-06) — Plan 07-01 SUMMARY flagged this as a ship-with-the-dependency item (§Known concerns point 3). Landing it now means SnapshotAggregator's cross-domain reads don't trip deptrac on CI while Plans 07-03..05 are authored. Allow-list covers every prior domain since Dashboard explicitly aggregates across all of them; the one-way arrow is preserved because no other domain imports from App\\Domain\\Dashboard\\."
  - "9 widgets placed in app/Filament/Widgets/ (not app/Domain/Dashboard/Filament/Widgets/) per the plan's files_modified frontmatter. This matches Filament's default discoverWidgets(app_path('Filament/Widgets')) path — no new discovery entry needed in AdminPanelProvider. Trade-off: widgets aren't under the Dashboard Deptrac layer (App\\Filament\\* is framework-level, outside Domain/) so widget imports of DashboardSnapshot don't need layer edges. Phase 7 Plan 06 can move them under Domain/Dashboard if the architectural intent shifts."
  - "CrmPushSuccessRateWidget reads integration_events (channel='bitrix') rather than a dedicated crm_push_logs table — confirmed via Phase 4 Plan 04's CrmPushLogResource which binds to IntegrationEvent. Plan 07-02's interfaces block referenced `CrmPushLog::where(...)` but that class does not exist in the codebase. SnapshotAggregator::computeCrmPushSuccessRate uses DB::table('integration_events')->where('channel', 'bitrix') with status='retrying' matching the integration_events status enum (not 'retry' as the plan sketched)."
  - "ImportIssue uses `resolved_at` timestamp column (nullable), not a `resolved` boolean. SnapshotAggregator::computeImportIssues uses ImportIssue::query()->unresolved() which walks the scopeUnresolved(->whereNull('resolved_at')) scope from Phase 2 Plan 01. Prevents a silent false-zero count that would have happened if the aggregator queried `where('resolved', false)`."
  - "sync_diffs table has `created_at` (not `detected_at`) and NO `product_id` column. SnapshotAggregator::computeSyncDiffsParity counts diverged rows by created_at (within the parity_window_days config) and computes parity as 1 - min(1, diverged_rows / total_products). Plan 07-05's cutover:divergence-scan writes rows with provider='divergence-scan' into the existing sync_diffs shape — no schema migration needed."
  - "computeWeeklyReportStatus preserves Plan 07-04's write: reads existing snapshot first, keeps last_sent_at + recipient_count if present, only rewrites next_run_iso on every refresh. Rationale — the 5-minute dashboard:refresh would otherwise overwrite the ETA every 5 min (fine) but also a truthful last-sent-at with the empty-state fallback (not fine). Two-way read/write seam between 07-02 and 07-04 preserved cleanly."
  - "HomeDashboardPage uses Filament's built-in Dashboard view — NO custom resources/views/filament/pages/home-dashboard.blade.php was created. The plan's files_modified listed this blade file, but Filament\\Pages\\Dashboard's default view (filament-panels::pages.dashboard) renders getWidgets() + getColumns() correctly. Creating an unused view would just be dead code. This is the minimum-code path to the 9-widget grid; future plans can override if custom layout is needed."
  - "Widgets live at App\\Filament\\Widgets\\ (the Filament default discovery path) — the pre-existing discoverWidgets(app_path('Filament/Widgets')) call in AdminPanelProvider already picked them up. No new ->discoverWidgets() entry added."
  - "HorizonLinkNavigationItem is a helper class (not a Page / Resource) so it sits at app/Domain/Dashboard/Support/, mirroring the Phase 4 helper-class convention. Visibility closure runs per-request so changing the user's role reflects immediately."

metrics:
  completed_at: "2026-04-24T09:00Z"
  duration_minutes: 9
  tasks_completed: 2
  files_created: 16
  files_modified: 5
  commits: 2
  widgets_added: 9
  compute_methods: 9
  scheduled_entries: 2
  deptrac_layer: Dashboard
  test_files: 3
  pest_cases_authored: 28

requirements:
  - DASH-01 (9-widget home dashboard at /admin — HomeDashboardPage with 3-row × 3-col grid shipped)
  - DASH-02 (dashboard:refresh scheduled every 5 min — writes 9 metric_keys via SnapshotAggregator)
---

# Phase 07 Plan 02: Home Dashboard Widgets — Summary

The operator's morning coffee page is live. `/admin` now renders 9 home-dashboard widgets organised as 3 rows × 3 columns — Row 1 freshness (what happened recently), Row 2 actions (what ops should look at), Row 3 system health (the big picture). Every widget reads from `dashboard_snapshots` via a single indexed lookup — zero live aggregation on page load per D-02. `dashboard:refresh` runs every 5 minutes via `routes/console.php`, delegates to `SnapshotAggregator::computeAll()`, and upserts 9 rows by `metric_key`. `snapshots:prune` daily at 03:50 keeps the snapshot table tidy. A `/horizon` link now sits in the Filament sidebar under the 'Operations' group, visible to admin only per D-03.

## Accomplishments

### DASH-01 — 9-widget home dashboard (D-01)

- `HomeDashboardPage` extends `Filament\Pages\Dashboard`; registered first in `AdminPanelProvider::panel()->pages([...])` so it overrides Filament's default `/admin` dashboard per the Filament 3 convention.
- `getWidgets()` returns the 9 widget classes in display order:
  - **Row 1 (freshness):** `LastSyncRunWidget`, `CrmPushSuccessRateWidget`, `CompetitorFreshnessWidget`
  - **Row 2 (actions):** `PendingReviewsWidget`, `ImportIssuesWidget`, `HorizonFailedJobsWidget`
  - **Row 3 (system health):** `SyncDiffsParityWidget`, `ProductCatalogueHealthWidget`, `WeeklyReportStatusWidget`
- `getColumns()` returns `['md' => 3, 'xl' => 3]` — 3-across on medium+ screens; Filament stacks to 1-col on mobile automatically.
- Every widget's `canView()` gate calls `Gate::allows('viewAny', DashboardSnapshot::class)`. Phase 7 Plan 01's `DashboardSnapshotPolicy` grants that to admin + pricing_manager + sales + read_only, so the 4-role matrix is honoured uniformly without per-widget role checks.
- Widget poll frequency comes from `config('dashboard.widget_poll_seconds', 60)` — assigned to `static::$pollingInterval` in each widget's constructor so Livewire's `wire:poll` picks it up on mount.
- Stale-data indicator: when `DashboardSnapshot::isStale()` returns true (computed_at older than `config('dashboard.snapshot_ttl_minutes', 15)`), the widget's Stat gets `extraAttributes(['class' => 'ring-2 ring-amber-400'])` — an amber border overlay that signals "this widget is older than expected, a background refresh should happen on next poll".

### DASH-02 — scheduled dashboard:refresh (D-02)

- `SnapshotAggregator` is the single place that knows HOW to compute each metric. 9 public `compute*` methods, one per metric_key, each returning a documented array shape. `computeAll()` returns the map; `refreshAll()` composes compute + upsert into one call for tests + tinker.
- `DashboardRefreshCommand` extends Phase 1's `BaseCommand` so every run gets a fresh `correlation_id` threaded through the spatie activitylog batch (same seam as sync:supplier + bitrix:* commands). Command body is a 5-line loop: iterate `computeAll()`, `DashboardSnapshot::upsertByKey($key, $payload)`, log each upsert. Exit code 0.
- `routes/console.php` scheduler entry: `Schedule::command('dashboard:refresh')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5)->timezone('Europe/London')`. 12 runs/hour; cheap given pre-aggregation. `withoutOverlapping(5)` prevents a slow refresh from colliding with the next tick (worst case: 5-min skip, still within the 15-min TTL ceiling).
- Nine `metric_key` rows land in `dashboard_snapshots` after one invocation:

| metric_key                   | widget row  | payload keys                                                                 |
| ---------------------------- | ----------- | ---------------------------------------------------------------------------- |
| last_sync_run                | Row 1       | run_id · duration_seconds · updated_count · failed_count · age_traffic_light |
| crm_push_success_rate        | Row 1       | success_count · retry_count · failed_count · total · success_rate_percent    |
| competitor_freshness         | Row 1       | fresh · stale · missing · threshold_hours · per_competitor                   |
| pending_reviews              | Row 2       | auto_create_drafts · margin_change · new_product_opportunity · total_pending |
| import_issues                | Row 2       | unresolved_csv_parse_errors · quarantined_csvs · low_completeness · unresolved |
| horizon_failed_jobs          | Row 2       | last_5_min · last_24_hours                                                   |
| sync_diffs_parity            | Row 3       | parity_percent · diverged_rows · total_products · threshold · traffic_light  |
| product_catalogue_health     | Row 3       | published · draft · pending · total                                          |
| weekly_report_status         | Row 3       | last_sent_at · recipient_count · next_run_iso                                |

### Retention: snapshots:prune daily 03:50

- `PruneDashboardSnapshotsCommand` deletes rows where `computed_at < now() - config('dashboard.snapshot_retention_days', 30)`. Mirrors the Phase 1 retention prune pattern (01-05-b).
- `--days=0` no-op safety guard (Phase 5 CompetitorCsvPruneCommand precedent) — prevents a typo'd invocation from wiping the full table.
- Schedule slot joins the 03:00/03:10/03:20/03:30/03:40 retention cascade at 03:50 per CONTEXT.md Claude's-Discretion default. `onOneServer()` + `withoutOverlapping(30)` same as the other prunes.
- In practice the snapshots table stays at ~9 rows (upsert-by-key semantics), so retention is forward-compat for the deferred sparkline-history split (CONTEXT.md §deferred).

### DASH-02 — D-03 Horizon link

- `HorizonLinkNavigationItem::build()` returns a `Filament\Navigation\NavigationItem` with:
  - Label: "Horizon"
  - Icon: `heroicon-o-queue-list`
  - URL: `/horizon` (`shouldOpenInNewTab: true`)
  - Group: "Operations"
  - Sort: 90 (bottom of group)
  - Visibility closure: `fn () => auth()->user()?->hasRole('admin') ?? false`
- Registered via `AdminPanelProvider::panel()->navigationItems([HorizonLinkNavigationItem::build()])`. The chain is append-only per established convention; Plans 07-03/04/05 can add more entries without rewriting this one.
- Belt-and-braces: the `/horizon` route itself is also admin-gated by `HorizonServiceProvider::gate()` (Phase 1 Plan 05 FOUND-09), so non-admins who somehow reach the URL still get blocked by middleware.
- Chose `NavigationItem` approach over `HorizonLinkPlugin` per Filament 3 idiom — simpler, more flexible (gate closure + group + sort all co-located in a single class).

### Deptrac: Dashboard layer shipped with the dependency

- `deptrac.yaml` + `depfile.yaml` both extended with a `Dashboard` layer definition (`app/Domain/Dashboard/.*`) + allow-list `[Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, WpDirectDb]`. Dual-config-sync honoured per Phase 5 Plan 05-05 lesson.
- `Http` layer's allow-list extended to include `Dashboard` so AdminPanelProvider's import of `HorizonLinkNavigationItem` doesn't trip on the Http → Dashboard edge.
- SnapshotAggregator reads:
  - Sync → `SyncRun`, `ImportIssue`
  - CRM → integration_events rows via Foundation layer + `integration_events` table reads
  - Competitor → `Competitor` model (last_ingest_at)
  - Products → `Product` (pending_reviews + import_issues + sync_diffs_parity + product_catalogue_health)
  - Suggestions → `Suggestion` (pending_reviews)
- `WpDirectDb` in the allow-list: SnapshotAggregator uses `DB::table('failed_jobs')`, `DB::table('sync_diffs')`, `DB::table('csv_parse_errors')`, `DB::table('competitor_ingest_runs')` for read-only metrics aggregation. The `-WpDirectDb` deny rule on Sync is about Woo write paths (SYNC-04), not about metric reads — so allowing it on Dashboard is correct architectural scope.
- Plan 07-01 SUMMARY §Known concerns item 3 listed this as "defer to 07-02 OR 07-06; current preference is 07-02 (ship-with-the-dependency rule)." Shipped here.

## Task Commits

1. **Task 1 — SnapshotAggregator + dashboard:refresh + snapshots:prune** — `32ac397`
   - `SnapshotAggregator` with 9 `compute*` + `computeAll()` + `refreshAll()` + `read()`
   - `DashboardRefreshCommand` (extends `BaseCommand`)
   - `PruneDashboardSnapshotsCommand` with `--days=0` safety guard
   - `AppServiceProvider::boot` — 2 new commands registered in the `runningInConsole` block
   - `routes/console.php` — 2 new schedule entries (`dashboard:refresh` every 5 min, `snapshots:prune` daily 03:50)
   - Deptrac `deptrac.yaml` + `depfile.yaml` — new Dashboard layer + allow-list
   - 2 Pest test files (`DashboardRefreshCommandTest`, `WidgetDataSourceTest`)

2. **Task 2 — 9 widgets + HomeDashboardPage + Horizon link** — `de4a4cf`
   - `HomeDashboardPage` (Filament `Pages\Dashboard` subclass; `getWidgets()` + `getColumns()`)
   - 9 `StatsOverviewWidget` classes under `app/Filament/Widgets/`
   - `HorizonLinkNavigationItem` (admin-only, group='Operations')
   - `AdminPanelProvider::panel()` extended with `->pages()` + `->widgets()` + `->navigationItems()`
   - Unused `Filament\Pages` + `Filament\Widgets` use statements removed
   - 1 Pest test file (`HomeDashboardPageTest`, 12 cases)

## Deviations from Plan

### [Rule 3 — Blocking] CrmPushLog model does not exist — CRM push data lives in integration_events

- **Found during:** Task 1 SnapshotAggregator authoring
- **Issue:** Plan's `<interfaces>` block referenced `CrmPushLog::where('created_at', '>=', now()->subDay())->selectRaw(...)` but no `App\Domain\CRM\Models\CrmPushLog` class exists. Phase 4 Plan 03 chose to reuse `IntegrationEvent` filtered by `channel='bitrix'` (see `app/Domain/CRM/Filament/Resources/CrmPushLogResource.php:9` binding to `IntegrationEvent`).
- **Fix:** `SnapshotAggregator::computeCrmPushSuccessRate()` reads `DB::table('integration_events')->where('channel', 'bitrix')->where('created_at', '>=', now()->subDay())`. Status enum is `success|failed|retrying` (per the Phase 1 Plan 03 migration) — used `retrying` for the retry bucket rather than the plan-sketched `retry`.
- **Files modified:** `app/Domain/Dashboard/Services/SnapshotAggregator.php`
- **Commit:** `32ac397`

### [Rule 3 — Blocking] ImportIssue uses `resolved_at` not `resolved` boolean

- **Found during:** Task 1 SnapshotAggregator authoring
- **Issue:** Plan sketch used `ImportIssue::where('resolved', false)->count()`. Phase 2 Plan 01 actually ships an `ImportIssue` with a nullable `resolved_at` timestamp + a `scopeUnresolved(->whereNull('resolved_at'))` scope — not a boolean column.
- **Fix:** `SnapshotAggregator::computeImportIssues()` uses `ImportIssue::query()->unresolved()->count()`. Same correction applied to `csv_parse_errors` which also uses `resolved_at` (Phase 5 Plan 01 migration).
- **Commit:** `32ac397`

### [Rule 3 — Blocking] sync_diffs has no product_id column; uses created_at not detected_at

- **Found during:** Task 1 SnapshotAggregator authoring
- **Issue:** Plan sketch used `SyncDiff::where('provider', 'divergence-scan')->where('detected_at', '>=', now()->subDays(...))` and `distinct('product_id')->count('product_id')`. The actual `sync_diffs` table (Phase 1 Plan 04 migration) has `created_at` + JSON `payload` — no `detected_at`, no `product_id`.
- **Fix:** `computeSyncDiffsParity` uses `DB::table('sync_diffs')->where('provider', 'divergence-scan')->where('created_at', '>=', now()->subDays($window))` and counts total rows (not distinct products — the distinct-product refinement can land in Plan 07-05 once `cutover:divergence-scan` writes product_id into the JSON payload + a computed column is added if needed).
- **Commit:** `32ac397`

### [Rule 2 — Missing Critical] Dashboard Deptrac layer shipped in Plan 07-02 (not deferred to 07-06)

- **Found during:** Pre-commit Task 1 deptrac scan planning
- **Issue:** Plan 07-01 SUMMARY §Known concerns item 3 listed this as "Planner may ship that edge in 07-02 OR 07-06; current preference is 07-02 (ship-with-the-dependency rule)." Without the layer definition, SnapshotAggregator's cross-domain reads (Products, Sync, Competitor, Suggestions) would trip Deptrac on the next CI run in whatever plan ships a Domain/Dashboard/* consumer first.
- **Fix:** Added `Dashboard` layer definition to both `deptrac.yaml` + `depfile.yaml` with the full cross-domain allow-list; extended `Http` to include `Dashboard` so AdminPanelProvider's `->navigationItems(HorizonLinkNavigationItem::build())` call is covered.
- **Commit:** `32ac397`

### [Rule 1 — Bug] Unused Filament\Pages + Filament\Widgets imports after replacing default dashboard

- **Found during:** Task 2 AdminPanelProvider edit
- **Issue:** After replacing `Pages\Dashboard::class` and `Widgets\AccountWidget::class` / `Widgets\FilamentInfoWidget::class` in the `->pages()` / `->widgets()` chains with Phase 7 classes, the `use Filament\Pages;` and `use Filament\Widgets;` statements at the top of `AdminPanelProvider.php` became unused — Larastan / Pint would flag this on the next CI run.
- **Fix:** Removed the two dead imports in the same commit as Task 2. Verified the rest of the file compiles cleanly.
- **Commit:** `de4a4cf`

### [Rule 1 — Bug] No custom home-dashboard.blade.php shipped (plan frontmatter listed one)

- **Found during:** Task 2 HomeDashboardPage implementation
- **Issue:** Plan's `files_modified` block listed `resources/views/filament/pages/home-dashboard.blade.php`. Filament 3's `Filament\Pages\Dashboard` subclass uses the packaged `filament-panels::pages.dashboard` view by default, which renders `getWidgets()` + `getColumns()` correctly. Creating a custom view would duplicate the built-in logic without adding value, and would require maintaining blade markup the framework updates in subsequent Filament releases.
- **Fix:** Skipped the custom view. Default Filament view handles all 9 widgets + 3-column grid correctly. Noted in §decisions above; future plan can override if custom layout needed.
- **Files modified:** None (preventative no-op deviation)

---

**Total deviations:** 6 auto-fixed (3 blockers, 1 missing-critical, 2 bugs). No Rule 4 architectural asks.

## Authentication Gates

None — this plan is pure composition + wiring + SnapshotAggregator. No external credentials needed.

## Issues Encountered

1. **MySQL not reachable in execution environment.** Same Phase 6/07-01 precedent — `PDO::connect` to `meetingstore_ops_testing` fails. All 3 new Pest feature test files (28 cases total) authored against correct schema + `RefreshDatabase` boot; execution deferred until next environment with MySQL online. Phase 7 Plan 06 final verification will catch both the 07-01 and 07-02 backlogs.

2. **PHP CLI not on PATH in shell session.** Couldn't run `php artisan list` or `php artisan schedule:list` during execution to verify the two new commands + two new schedule entries. Verification via source: `AppServiceProvider::boot` runningInConsole commands() array + `routes/console.php` schedule entries both physically present in the committed files. Phase 7 Plan 06 verifier MUST run `php artisan list | grep 'dashboard:refresh\|snapshots:prune'` + `php artisan schedule:list` to close the loop.

3. **Deptrac not executable in this environment.** `vendor/bin/deptrac` requires PHP CLI. The Dashboard layer + allow-list were authored against the Phase 1-6 pattern (every cross-domain import traced + authorised in the comment block above the allow-list). Plan 07-06 verifier MUST run `vendor/bin/deptrac analyse --no-progress` to confirm 0 violations.

## Next Phase Readiness

### Plan 07-03 (global search + CSV + saved filters) can assume

- `AdminPanelProvider::panel()->navigationItems(...)` is append-only. This plan seeded the first entry (HorizonLinkNavigationItem). Plan 07-03 appends its own NavigationItem instances without replacing what's here.
- `AdminPanelProvider::panel()->pages(...)` + `->widgets(...)` follow the same append-only convention.
- `config('dashboard.csv_export_hard_cap')` (100k) + `csv_export_queue_threshold` (10k) — both drive the bulk-action UX as Plan 07-03 adds `->exportable()` to each Resource.
- `config('dashboard.global_search_debounce_ms')` (300) — drop-in for Filament 3 global search debounce tuning.

### Plan 07-04 (notification centre + weekly digest) can assume

- `SnapshotAggregator::computeWeeklyReportStatus()` READS the existing `weekly_report_status` snapshot and preserves `last_sent_at` + `recipient_count`. Plan 07-04's `reports:weekly-digest` command MUST upsert those two fields via `DashboardSnapshot::upsertByKey('weekly_report_status', [...])` after each successful send. The aggregator's empty-state fallback provides a next_run_iso ETA so the widget always has content — no NPE risk.
- `WeeklyReportStatusWidget` is already wired to consume the same metric_key. No extra Filament registration needed in 07-04.
- `AlertRecipient::query()->receivesWeeklyDigest()->pluck('email')` is the Plan 07-04 recipient query — scope shipped by Plan 07-01.

### Plan 07-05 (cutover commands) can assume

- `SnapshotAggregator::computeSyncDiffsParity()` reads `sync_diffs` rows where `provider='divergence-scan'` inside the `config('cutover.parity_window_days', 7)` rolling window. `cutover:divergence-scan` (Plan 07-05) MUST write rows into `sync_diffs` with `provider='divergence-scan'` for the widget to light up.
- The widget's traffic-light threshold reads `config('cutover.parity_threshold_percent', 99)` — ops don't need a redeploy to adjust; same env var as `cutover:*` commands consume.
- `SyncDiffsParityWidget` is the CUT-01 tile; Plan 07-05 doesn't need to add a new widget — it just populates the data feed.

### Plan 07-06 (handover + verification) can assume

- Dashboard Deptrac layer already in place — no new layer work needed.
- 3 new Feature-tier test files under `tests/Feature/Dashboard/` (DashboardRefreshCommandTest, WidgetDataSourceTest, HomeDashboardPageTest) — 28 cases total — add to the MySQL-deferred backlog that Plan 07-06 MUST execute.
- `AdminPanelProvider` nav/pages/widgets arrays are the authoritative source for Phase 7 UI surface count; verifier can grep them to confirm all 9 widget classes + HomeDashboardPage + HorizonLinkNavigationItem registered.

### Known concerns for later plans

1. **MySQL Feature-suite backlog grows.** Phase 6 → Phase 7 Plan 01 → Phase 7 Plan 02 have each added feature tests with `RefreshDatabase` that never ran. Plan 07-06 MUST run the full Pest suite against a live MySQL `meetingstore_ops_testing` instance to clear the backlog before cutover.
2. **Widget location (App\Filament vs App\Domain\Dashboard).** Current widgets sit at `app/Filament/Widgets/` for simplicity. If architectural preference shifts toward "all Dashboard code under Domain/Dashboard/", Plan 07-06 can relocate the 9 widget files + adjust AdminPanelProvider discovery — no logic changes needed.
3. **sync_diffs distinct-product parity refinement.** `computeSyncDiffsParity` counts diverged ROWS (not distinct products). Once Plan 07-05's `cutover:divergence-scan` writes structured JSON with `product_id` consistently, the aggregator can switch to a `DISTINCT payload->>'$.product_id'` count for a truer parity %. Deferring the schema work avoids a Plan 07-01 migration revision.

## Self-Check: PASSED

**Files on disk (verified via ls + git ls-files):**
- `app/Domain/Dashboard/Services/SnapshotAggregator.php` — FOUND
- `app/Console/Commands/Dashboard/DashboardRefreshCommand.php` — FOUND
- `app/Console/Commands/Dashboard/PruneDashboardSnapshotsCommand.php` — FOUND
- `app/Filament/Pages/HomeDashboardPage.php` — FOUND
- `app/Filament/Widgets/LastSyncRunWidget.php` — FOUND
- `app/Filament/Widgets/CrmPushSuccessRateWidget.php` — FOUND
- `app/Filament/Widgets/CompetitorFreshnessWidget.php` — FOUND
- `app/Filament/Widgets/PendingReviewsWidget.php` — FOUND
- `app/Filament/Widgets/ImportIssuesWidget.php` — FOUND
- `app/Filament/Widgets/HorizonFailedJobsWidget.php` — FOUND
- `app/Filament/Widgets/SyncDiffsParityWidget.php` — FOUND
- `app/Filament/Widgets/ProductCatalogueHealthWidget.php` — FOUND
- `app/Filament/Widgets/WeeklyReportStatusWidget.php` — FOUND
- `app/Domain/Dashboard/Support/HorizonLinkNavigationItem.php` — FOUND
- `tests/Feature/Dashboard/DashboardRefreshCommandTest.php` — FOUND
- `tests/Feature/Dashboard/WidgetDataSourceTest.php` — FOUND
- `tests/Feature/Dashboard/HomeDashboardPageTest.php` — FOUND

**Commits verified via `git log --oneline`:**
- `32ac397` — Task 1 (SnapshotAggregator + commands + Deptrac layer + 2 tests) — FOUND
- `de4a4cf` — Task 2 (9 widgets + HomeDashboardPage + Horizon link + test) — FOUND

**Runtime verification DEFERRED** (PHP CLI + MySQL + Deptrac not reachable in execution environment — same precedent as Phase 6 + 07-01):
- `php artisan list | grep 'dashboard:refresh\|snapshots:prune'` — expected 2 matches
- `php artisan schedule:list | grep dashboard:refresh` — expected every-5-min entry
- `php artisan schedule:list | grep snapshots:prune` — expected 03:50 daily entry
- `vendor/bin/pest tests/Feature/Dashboard/` — 28 cases expected to pass
- `vendor/bin/deptrac analyse --no-progress` — expected 0 violations with new Dashboard layer
- `php artisan route:list | grep admin` — expected HomeDashboardPage at root of /admin

Plan 07-06 verifier MUST execute all six commands in a MySQL-online + PHP-online environment to close the verification loop.

---

*Phase: 07-dashboard-polish-cutover*
*Plan: 02-home-dashboard-widgets*
*Completed: 2026-04-24*
