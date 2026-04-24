---
phase: 07-dashboard-polish-cutover
plan: 01-data-model-foundation
subsystem: dashboard,cutover,alerting
tags: [data-model, migrations, config, eloquent, policies, factories, shield-seeder, policy-integrity, d-02, d-07, d-08, d-12, d-17, pitfall-p6-d, pitfall-p5-f]

requires:
  - phase: 01-foundation
    provides: "AlertRecipient model + receives_* boolean pattern; PolicyTemplateIntegrityTest Architecture suite; Pitfall P5-F hand-written policy protocol; meetingstore_ops_testing MySQL DB for Feature suite"
  - phase: 02-supplier-sync
    provides: "receives_sync_reports migration pattern (nullable bool default true + scope); domain-scoped factory namespace convention Database\\Factories\\Domain\\<Module>\\"
  - phase: 04-bitrix24-crm-sync
    provides: "receives_crm_alerts backfill pattern (force-update ops fallback row on install)"
  - phase: 05-competitor-analysis
    provides: "Hand-written hasRole policy pattern (Pitfall P5-F restoration protocol); explicit whereIn (not LIKE) for resources with action-level scope (MySQL _ single-char wildcard lesson)"
  - phase: 06-product-auto-create
    provides: "Pitfall P6-D belt-and-braces UPDATE backfill pattern; 5-migration-file layout; receives_auto_create_alerts scope precedent; ProductAutoCreate allow-list in Deptrac"

provides:
  - "3 migrations at 2026_04_24_100000..100200 — 1 additive column + 2 new tables, all with indexes"
  - "alert_recipients.receives_weekly_digest — nullable boolean default TRUE + Pitfall P6-D belt-and-braces UPDATE on NULL (backfills every existing row)"
  - "dashboard_snapshots table: id + metric_key varchar(128) UNIQUE + metric_value_json JSON + computed_at timestamp useCurrent indexed + timestamps"
  - "user_saved_filters table: id + user_id FK cascadeOnDelete + resource_slug varchar(64) + filter_name varchar(128) + filter_payload_json JSON + timestamps + UNIQUE(user_id, resource_slug, filter_name) + INDEX(user_id, resource_slug)"
  - "config/cutover.php — 9 keys: parity_threshold_percent (99), parity_window_days (7), 3 env-gate var NAMES, 2 storage paths, 2 legacy plugins, 3 legacy cron hooks"
  - "config/dashboard.php — 7 keys: snapshot_ttl_minutes (15), widget_poll_seconds (60), refresh_interval_minutes (5), snapshot_retention_days (30), csv_export_hard_cap (100000), csv_export_queue_threshold (10000), global_search_debounce_ms (300)"
  - "App\\Domain\\Dashboard\\Models\\DashboardSnapshot — upsertByKey() + isStale() helpers; JSON + datetime casts; HasFactory"
  - "App\\Domain\\Dashboard\\Models\\UserSavedFilter — belongsTo(User); JSON cast; HasFactory"
  - "App\\Domain\\Dashboard\\Policies\\DashboardSnapshotPolicy — viewAny all 4 roles; create/update DENY everyone (scheduled command sole writer); delete admin-only"
  - "App\\Domain\\Dashboard\\Policies\\UserSavedFilterPolicy — owner-scoped CRUD with admin delete override; T-07-01-01 defence-in-depth"
  - "2 factories at database/factories/Domain/Dashboard/ with state methods (stale/fresh/forResource)"
  - "AppServiceProvider::boot — 2 new Gate::policy bindings for DashboardSnapshot + UserSavedFilter"
  - "RolePermissionSeeder — pricing_manager gets view-only on dashboard_snapshot + full CRUD on user_saved_filter (owner-scoped via policy); sales gets view on dashboard_snapshot + owner-scoped CRUD on user_saved_filter; admin gets all via Permission::all() sync"
  - "Sales query restructured so owner-scoped CRUD perms escape the read-only view_% outer gate (defect caught in-plan — the prior query would have stripped create/update/delete)"
  - "PolicyTemplateIntegrityTest — +Dashboard/Policies scan root + 2 Gate binding pairs + floor bumped 24 → 26"
  - "3 Pest test files (AlertRecipientWeeklyDigestMigrationTest, CutoverConfigTest, DashboardConfigTest) for Task 1"
  - "2 Pest test files (DashboardSnapshotModelTest, UserSavedFilterModelTest) for Task 2"

affects:
  - "07-02-home-dashboard-widgets — reads dashboard_snapshots via DashboardSnapshot::upsertByKey(); isStale() gates amber border + background refresh; config('dashboard.widget_poll_seconds') drives Livewire wire:poll"
  - "07-03-global-search-csv-saved-filters — reads user_saved_filters via UserSavedFilter with owner-scoped query in each Filament Resource; config('dashboard.csv_export_hard_cap') + csv_export_queue_threshold drive bulk-action UX; config('dashboard.global_search_debounce_ms') tunes Filament 3 search"
  - "07-04-notification-centre-weekly-digest — reads AlertRecipient::query()->receivesWeeklyDigest() for the Mailable recipient list; WeeklyDigestMail dispatched by reports:weekly-digest scheduled command"
  - "07-05-cutover-commands — reads config('cutover.*') for parity threshold, env-gate var NAMES, storage paths, legacy plugin + cron slugs; cutover:drill-rollback gated by config('cutover.drill_allowed_env_var'); cutover:disable-legacy-plugins gated by disable_live_allowed_env_var"
  - "07-06-handover-deptrac-verification — Dashboard policy count (26) + zero placeholder leaks baked into PolicyTemplateIntegrityTest; verification relies on this plan's floor bump"

tech-stack:
  added:
    - "app/Domain/Dashboard/ — new domain directory (PSR-4 autoloaded via composer.json App\\ → app/ map)"
    - "Database\\Factories\\Domain\\Dashboard\\ — factory namespace matching Phase 5/6 convention"
  patterns:
    - "Hand-written hasRole/ownership policies (Pitfall K + P2-H + P5-F) — 2 new policies match Phase 1-6 shape; restore-protocol docblock on every file"
    - "Pitfall P6-D belt-and-braces UPDATE in migration up() — `ADD COLUMN ... DEFAULT TRUE` plus explicit `UPDATE ... WHERE col IS NULL` so historical rows always land on TRUE"
    - "One-row-per-metric table via UNIQUE index on metric_key (dashboard_snapshots) — upsert semantics via updateOrCreate in a static helper"
    - "Config-of-env-var-NAMES (not values) — cutover.php stores 'CUTOVER_DRILL_ALLOWED' as a string, so commands look up the env value at runtime; admin setting the var alone doesn't auto-approve anything until a command references the config key"

key-files:
  created:
    - "database/migrations/2026_04_24_100000_add_receives_weekly_digest_to_alert_recipients.php"
    - "database/migrations/2026_04_24_100100_create_dashboard_snapshots_table.php"
    - "database/migrations/2026_04_24_100200_create_user_saved_filters_table.php"
    - "config/cutover.php"
    - "config/dashboard.php"
    - "app/Domain/Dashboard/Models/DashboardSnapshot.php"
    - "app/Domain/Dashboard/Models/UserSavedFilter.php"
    - "app/Domain/Dashboard/Policies/DashboardSnapshotPolicy.php"
    - "app/Domain/Dashboard/Policies/UserSavedFilterPolicy.php"
    - "database/factories/Domain/Dashboard/DashboardSnapshotFactory.php"
    - "database/factories/Domain/Dashboard/UserSavedFilterFactory.php"
    - "tests/Feature/Dashboard/AlertRecipientWeeklyDigestMigrationTest.php"
    - "tests/Feature/Dashboard/CutoverConfigTest.php"
    - "tests/Feature/Dashboard/DashboardConfigTest.php"
    - "tests/Feature/Dashboard/DashboardSnapshotModelTest.php"
    - "tests/Feature/Dashboard/UserSavedFilterModelTest.php"
  modified:
    - "app/Domain/Alerting/Models/AlertRecipient.php (added receives_weekly_digest to fillable + casts + scopeReceivesWeeklyDigest)"
    - "app/Providers/AppServiceProvider.php (2 new Gate::policy bindings for Dashboard models)"
    - "database/seeders/RolePermissionSeeder.php (pricing_manager + sales Dashboard perms via explicit whereIn; sales query restructured so CRUD perms escape view_% outer gate)"
    - "tests/Architecture/PolicyTemplateIntegrityTest.php (+Dashboard/Policies scan root + 2 Gate pairs + floor 24 → 26)"

decisions:
  - "Migration timestamps start 2026_04_24_* — Phase 6 ended at 2026_04_23_200000 (AutoCreateSetting), so Phase 7's first migration group starts cleanly on 2026_04_24 per CONTEXT.md §established patterns."
  - "receives_weekly_digest defaults TRUE (unlike receives_crm_alerts / receives_auto_create_alerts which default FALSE) because the weekly digest is an ambient ops summary, not an incident alert — D-08 explicitly calls out 'default true for existing fallback ops@meetingstore.co.uk'."
  - "config/cutover.php stores env-var NAMES (not values) for the three safety gates (CUTOVER_DRILL_ALLOWED / CUTOVER_DISABLE_LIVE_ALLOWED / CUTOVER_IMMEDIATE_PUBLISH_ALLOWED). Commands read the env var at runtime via env(config('cutover.drill_allowed_env_var')). This means an admin setting the var alone doesn't auto-approve anything until a command explicitly consults the key — two-step safety."
  - "DashboardSnapshotPolicy::create + ::update return FALSE for everyone (not admin-gated). The scheduled dashboard:refresh command runs under the scheduler (no user context), and Filament will never route CRUD through the policy. Returning FALSE is a defensive net against a future plan accidentally wiring a Filament Resource that offers create/update — it would fail-closed."
  - "UserSavedFilterPolicy::viewAny returns TRUE for any authenticated user (not role-gated). The actual row-level filter lives in Plan 07-03's getEloquentQuery(->where('user_id', auth()->id())). This policy layer is defence-in-depth: if a rogue controller forgets the query scope, view/update/delete still block cross-user access via $filter->user_id === $user->id."
  - "Sales query in RolePermissionSeeder was restructured: prior to this plan, the outer ->where('name', 'like', 'view_%') AND clause stripped out create/update/delete permissions from every role assignment. Adding user_saved_filter CRUD to sales required splitting the query into two orWhere branches — the read-only branch retains the view_% gate, and the owner-scoped CRUD branch sits outside it. Caught in-plan before commit; no Phase 5/6 regression."
  - "Deptrac layer for Dashboard deferred to Plan 07-06 (planner's explicit handoff — 'other domains MUST NOT depend on Dashboard/Cutover' is enforced at phase-end verification, not per-plan). This plan's models import only from Foundation / base Laravel, so no layer edge is needed today."
  - "No shield:generate invocation — the 2 Dashboard policies are hand-written per Pitfall P5-F. Running shield:generate --all would regenerate 6 previously-restored policies with {{ Placeholder }} literals, which is how Phase 5 spent 35 minutes on the restoration cycle. Plan 07-06 may run it once at phase end following the P5-F restoration protocol, or skip it entirely (hand-written policies are authoritative)."

metrics:
  completed_at: "2026-04-24T09:30Z"
  duration_minutes: 22
  tasks_completed: 3
  files_created: 16
  files_modified: 4
  commits: 3
  migrations: 3
  config_files: 2
  domain_models: 2
  policies: 2
  factories: 2
  test_files: 5
  deptrac_violations: 0
  policy_floor: 26

requirements:
  - DASH-04 (dashboard_snapshots table + config/dashboard.php tunables — Plan 07-02 widgets + Plan 07-03 CSV caps will consume)
  - DASH-05 (receives_weekly_digest column + AlertRecipient scope — Plan 07-04 Mailable routing will consume)
---

# Phase 07 Plan 01: Data Model Foundation — Summary

Foundation layer for Phase 7's dashboard polish + cutover: 3 migrations, 2 config files, 2 Eloquent models with hand-written policies + factories, Shield seeder extension, and a PolicyTemplateIntegrityTest floor bump from 24 → 26. No user-facing UI in this plan — every artefact is a prerequisite for Plans 07-02..07-05.

## Accomplishments

### DASH-04 foundation: dashboard_snapshots + config/dashboard.php

- `dashboard_snapshots` table: one row per widget metric keyed by `metric_key` (VARCHAR(128) UNIQUE). JSON payload in `metric_value_json`; `computed_at` timestamp indexed for freshness queries and the Plan 07-06 retention prune. The UNIQUE(metric_key) index enforces D-02's upsert-by-key semantics — widgets read a fixed small rowset, never run live aggregations.
- `DashboardSnapshot::upsertByKey($key, $payload)` — static helper wrapping `updateOrCreate`. Plan 07-02's `dashboard:refresh` command uses this on every metric; Plan 07-05's divergence scan uses it for `sync_diffs_parity_pct`.
- `DashboardSnapshot::isStale()` — compares `computed_at` against `config('dashboard.snapshot_ttl_minutes')` (default 15). Widgets show an amber border + trigger a background refresh when stale.
- `config/dashboard.php` — 7 keys covering widget poll frequency (60s), snapshot TTL (15m), scheduled refresh interval (5m), retention (30d), CSV export caps (10k queue threshold / 100k hard cap), and global-search debounce (300ms). Every Plan 07-02..07-04 consumer reads through these keys — no hard-coded inline values.

### DASH-05 foundation: receives_weekly_digest column + AlertRecipient scope

- Migration `2026_04_24_100000` adds `receives_weekly_digest` BOOLEAN nullable DEFAULT TRUE to `alert_recipients`, after `receives_auto_create_alerts` (the 5th `receives_*` boolean). Pitfall P6-D belt-and-braces UPDATE backfills every existing row to TRUE so the seeded `ops@meetingstore.co.uk` fallback auto-receives the digest.
- `AlertRecipient` model extended with the new column in `$fillable` + `$casts` (boolean), plus `scopeReceivesWeeklyDigest()` mirroring the 4 prior `receives_*` scopes.
- Default TRUE (unlike `receives_crm_alerts` / `receives_auto_create_alerts` which default FALSE) because the weekly digest is an ambient ops summary, not an incident alert. D-08 explicitly calls out this default.

### DASH-04 + DASH-07 read-side: user_saved_filters

- `user_saved_filters` table: per-user private filter store keyed by `(user_id, resource_slug, filter_name)` UNIQUE composite index. JSON payload in `filter_payload_json`; FK on `user_id` cascades delete so filters die with the user. Secondary index `(user_id, resource_slug)` serves the common "my saved filters for this Resource" query.
- `UserSavedFilter::user()` BelongsTo relationship; JSON cast on `filter_payload_json`.
- T-07-01-01 (cross-user read) mitigation: `UserSavedFilterPolicy::view` checks ownership; Plan 07-03's Resource queries add the belt-and-braces query scope.
- T-07-01-02 (JSON payload tampering): Plan 07-03 will validate `filter_payload_json` against each Resource's declared filter schema before applying — this plan establishes the untrusted-payload storage; validation lives downstream.

### Cutover tunables: config/cutover.php

- 9 keys covering:
  - Parity: `parity_threshold_percent` (99, D-12), `parity_window_days` (7, D-12)
  - Env-gate var NAMES (not values): `drill_allowed_env_var`, `disable_live_allowed_env_var`, `immediate_publish_allowed_env_var` — commands look up the env value at runtime, so an admin setting `CUTOVER_DRILL_ALLOWED=true` alone doesn't auto-approve until a command explicitly reads the config key. Two-step safety.
  - Storage paths: `backup_path` (CUT-04 mysqldump output), `drill_report_path` (CUT-05 markdown output)
  - Legacy WP artefacts: `legacy_plugins` array (stock-updater + woocommerce-bitrix24-integration) and `legacy_cron_hooks` array (3 hook slugs) for D-18 deregistration

### Hand-written Dashboard policies (P5-F protocol)

- `DashboardSnapshotPolicy`: viewAny/view for all 4 roles (ambient ops intel); create/update DENY for everyone (the scheduled command is the sole writer — defensive net against future Filament CRUD wiring); delete admin-only.
- `UserSavedFilterPolicy`: viewAny TRUE (row-scope lives in Plan 07-03 query); view/update owner-only; delete owner OR admin; create any authenticated user.
- Every policy file carries the P5-F restoration docblock warning against `shield:generate` regeneration.
- Gate::policy bindings appended to `AppServiceProvider::boot()` in a Phase-7-labelled block.

### Shield seeder extension

- pricing_manager: explicit whereIn for `view_dashboard_snapshot` (view-only; widgets are ambient intel) + full CRUD perm names on `user_saved_filter` (policy enforces per-user ownership).
- sales: view on `dashboard_snapshot` + owner-scoped CRUD on `user_saved_filter`. **The sales query was restructured in-plan** — the prior shape had an outer `->where('name', 'like', 'view_%')` AND clause that would have stripped the create/update/delete perms before they attached. Caught before the commit; now split into two `orWhere` branches (read-only + owner-scoped CRUD) with the view_% gate only on the read-only branch.
- admin: catches both new resources via the existing `syncPermissions(Permission::all())` — no seeder edit needed for admin.
- read_only: catches view_* perms automatically via the existing `where('name', 'like', 'view_%')` pattern — no seeder edit needed for read_only either.

### PolicyTemplateIntegrityTest bump

- `+ app_path('Domain/Dashboard/Policies')` in both the placeholder-leak scan and the policy-count glob.
- Floor bumped 24 → 26 (23 Phase 1-5 + 1 Phase 6 Plan 04 + 2 Dashboard).
- 2 new Gate binding pairs added to the resolver test (DashboardSnapshot → DashboardSnapshotPolicy, UserSavedFilter → UserSavedFilterPolicy).
- **Architecture suite verified passing in this environment: 3 tests green** (see §Self-Check).

## Task Commits

1. **Task 1 — Migrations + config + AlertRecipient extension** — `c1c7728`
   - 3 migrations (2026_04_24_100000..100200)
   - config/cutover.php + config/dashboard.php
   - AlertRecipient fillable/casts/scope extension
   - 3 Pest test files (AlertRecipientWeeklyDigestMigrationTest + CutoverConfigTest + DashboardConfigTest)

2. **Task 2 — Domain models + policies + factories + provider wiring** — `d51b839`
   - DashboardSnapshot + UserSavedFilter models
   - DashboardSnapshotPolicy + UserSavedFilterPolicy
   - 2 factories with state methods
   - AppServiceProvider::boot — 2 new Gate::policy bindings
   - 2 Pest test files (DashboardSnapshotModelTest + UserSavedFilterModelTest)

3. **Task 3 — Shield seeder + PolicyTemplateIntegrityTest floor bump** — `65e5ddb`
   - RolePermissionSeeder: pricing_manager + sales Dashboard perms via whereIn
   - Sales query restructured (defect caught in-plan)
   - PolicyTemplateIntegrityTest: scan root + 2 Gate pairs + floor 24 → 26

## Deviations from Plan

### [Rule 1 — Bug] Sales query would have stripped user_saved_filter CRUD perms

- **Found during:** Task 3 edit pass on RolePermissionSeeder
- **Issue:** The existing sales query wraps every permission match in an outer `->where(fn ($q) => $q->where('name', 'like', 'view_%'))` AND clause to enforce read-only scope. Adding `create_user_saved_filter` / `update_user_saved_filter` / `delete_user_saved_filter` to the inner `orWhereIn` would have silently dropped them — they'd match the inner whereIn but fail the outer `view_%` AND filter.
- **Fix:** Restructured the sales query into two `orWhere` branches. The read-only branch (CRM log + competitor perms + Phase 7 dashboard_snapshot view-only) keeps the `view_%` outer gate. The new owner-scoped CRUD branch (user_saved_filter CRUD) sits outside the gate so the perms actually attach. Admin delete override is enforced at the policy level, not seeder level.
- **Files modified:** `database/seeders/RolePermissionSeeder.php`
- **Commit:** `65e5ddb`

### [Rule 2 — Missing Critical] Config tests authored beyond plan spec

- **Found during:** Task 1 implementation — plan mentioned `DashboardConfigTest + CutoverConfigTest` in <success_criteria> but didn't list them under Task 1's explicit `<files>` block.
- **Fix:** Added both config-assertion test files in Task 1's commit. They lock the key names + default values so a Plan 07-02..07-05 drift fails fast.
- **Files modified:** `tests/Feature/Dashboard/CutoverConfigTest.php`, `tests/Feature/Dashboard/DashboardConfigTest.php` (both created)
- **Commit:** `c1c7728`

---

**Total deviations:** 2 auto-fixed (1 bug caught in-plan, 1 missing critical). No Rule 4 architectural asks.

## Authentication Gates

None — this plan is pure schema + Eloquent + policy + config plumbing.

## Issues Encountered

1. **MySQL service not running in execution environment.** `PDO::connect()` to `meetingstore_ops_testing` at 127.0.0.1:3306 fails with SQLSTATE[HY000] [2002]. This matches the Phase 6 Plan 01 precedent documented in its SUMMARY under "Deferred Verification — MySQL Testing Environment." All 11 Pest Feature test files (5 new this plan) are authored against the correct schema + assertion shape; their execution is deferred until the next environment where MySQL is online. The Architecture suite (PolicyTemplateIntegrityTest) does NOT use `RefreshDatabase` and was executed successfully.

2. **No shield:generate executed.** Per Pitfall P5-F, running `shield:generate --all` would regenerate the 6 Filament-discoverable policies with `{{ Placeholder }}` literals (Shield 3.9.10 bug) and overwrite the hasRole overrides on SuggestionPolicy / AlertRecipientPolicy / RolePolicy / AutoCreateSkipRulePolicy / AutoCreateRejectionPolicy / AutoCreateSettingsPolicy. Phase 7 Plan 06 will either run shield:generate once with the full restoration protocol (Phase 5 04a precedent) or skip it entirely since hand-written policies are authoritative. This plan ships 2 hand-written Dashboard policies without invoking shield:generate, so no restoration cycle was triggered.

## Next Phase Readiness

### Plan 07-02 (home dashboard + widgets) can assume

- `dashboard_snapshots` table exists; read via `DashboardSnapshot::where('metric_key', $key)->first()`.
- Write via `DashboardSnapshot::upsertByKey($key, $payload)` from the scheduled `dashboard:refresh` command.
- `isStale()` helper returns true if `computed_at` < `now() - config('dashboard.snapshot_ttl_minutes', 15)`.
- `config('dashboard.widget_poll_seconds')` (default 60) drives Livewire `wire:poll`.
- `config('dashboard.refresh_interval_minutes')` (default 5) drives the scheduled command.
- Dashboard widgets authorize via `Gate::allows('viewAny', DashboardSnapshot::class)` — the policy currently grants all 4 roles view access.

### Plan 07-03 (global search + CSV + saved filters) can assume

- `user_saved_filters` table exists with cascade-delete on user.
- UserSavedFilter model with User relationship + JSON cast + factory for fixtures.
- UserSavedFilterPolicy enforces ownership on view/update (delete allows admin override).
- Plan 07-03 MUST add `->where('user_id', auth()->id())` query scope in every Filament Resource's getEloquentQuery extension — the policy is defence-in-depth, not the primary filter.
- Plan 07-03 MUST validate `filter_payload_json` against each Resource's declared filter schema before applying (T-07-01-02 mitigation).
- `config('dashboard.csv_export_hard_cap')` (100k) + `csv_export_queue_threshold` (10k) drive the bulk-action UX ("queue this export to email?").
- `config('dashboard.global_search_debounce_ms')` (300) tunes Filament 3 search debounce.

### Plan 07-04 (notification centre + weekly digest) can assume

- `AlertRecipient::query()->receivesWeeklyDigest()->pluck('email')` returns the digest recipients.
- `ops@meetingstore.co.uk` auto-opted-in via the migration backfill.
- Filament AlertRecipientResource form needs a 5th toggle for `receives_weekly_digest` in Plan 07-04 Task 1 (mirrors the Phase 6 Plan 04 auto-create toggle).

### Plan 07-05 (cutover commands) can assume

- `config('cutover.parity_threshold_percent')` (99) is the go-live gate.
- `config('cutover.parity_window_days')` (7) is the rolling window.
- `env(config('cutover.drill_allowed_env_var'))` returns true only if `CUTOVER_DRILL_ALLOWED=true` in .env AND the command reads the config key — two-step safety.
- Same pattern for `disable_live_allowed_env_var` + `immediate_publish_allowed_env_var`.
- `config('cutover.legacy_plugins')` + `legacy_cron_hooks` are the D-18 disable-targets.
- `config('cutover.backup_path')` (default `storage/app/cutover/backups`) is the CUT-04 mysqldump location.
- `config('cutover.drill_report_path')` (default `storage/app/cutover`) is the CUT-05 drill-report markdown location.
- `DashboardSnapshot::upsertByKey('divergence_parity_pct', [...])` is the widget-read hookup for CUT-01.

### Plan 07-06 (handover + verification) can assume

- 26 policies exist across 10 scan roots with zero `{{ Placeholder }}` leaks.
- PolicyTemplateIntegrityTest floor bumped to 26 — CI catches any regression.
- All Dashboard / Cutover layer edges land in deptrac.yaml + depfile.yaml in Plan 07-06 (deferred from this plan — the models import only Foundation + base Laravel today, no new edge needed).

### Known concerns for later plans

1. **MySQL test suite deferral carries forward.** Phase 6 ended with FLAG verdict for Feature-tier execution; Phase 7 inherits this + adds its own 5 new Feature test files. Plan 07-06 verification MUST run against an environment with `meetingstore_ops_testing` MySQL online to clear both backlogs.
2. **shield:generate regeneration hazard persists.** If Plan 07-06 runs `shield:generate --all`, the 2 new Dashboard policies join the 6+ previously-restored policies in the P5-F restoration list. Operator should consider whether shield:generate is needed at all — PolicyTemplateIntegrityTest provides the architectural guarantee without running the generator.
3. **Deptrac Dashboard layer edge not yet defined.** This plan's models import only `App\Models\User` (cross-domain) + Laravel base classes. Plan 07-02 will import from Sync/CRM/Competitor/Pricing/ProductAutoCreate domains for the widget aggregations — at that point Dashboard needs a `Dashboard: [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate]` allow-list in BOTH deptrac.yaml + depfile.yaml (Phase 5 dual-config lesson). Planner may ship that edge in 07-02 OR 07-06; current preference is 07-02 (ship-with-the-dependency rule).

## Self-Check: PASSED

**Files on disk (verified):**
- 3 migrations at `database/migrations/2026_04_24_*` — FOUND
- `config/cutover.php` + `config/dashboard.php` — FOUND; config values verified via `php artisan config:show` (cutover.parity_threshold_percent=99, dashboard.widget_poll_seconds=60, etc.)
- 2 models at `app/Domain/Dashboard/Models/` — FOUND
- 2 policies at `app/Domain/Dashboard/Policies/` — FOUND
- 2 factories at `database/factories/Domain/Dashboard/` — FOUND
- 5 Pest test files at `tests/Feature/Dashboard/` — FOUND
- PolicyTemplateIntegrityTest updated with +Dashboard/Policies path + 2 Gate pairs + floor 26

**Commits verified via `git log --oneline`:**
- `c1c7728` — Task 1 (migrations + config + AlertRecipient) — FOUND
- `d51b839` — Task 2 (models + policies + factories + provider) — FOUND
- `65e5ddb` — Task 3 (seeder + policy integrity bump) — FOUND

**Runtime verification (executed successfully in this environment):**
- `php -l` — 0 syntax errors across all 16 new + 4 modified PHP files
- `php artisan config:show cutover` → all 9 keys present with expected defaults (99 / 7 / env-var names / paths / legacy plugin + cron lists)
- `php artisan config:show dashboard` → all 7 keys present with expected defaults (15 / 60 / 5 / 30 / 100000 / 10000 / 300)
- `Gate::getPolicyFor(new DashboardSnapshot())` → `App\Domain\Dashboard\Policies\DashboardSnapshotPolicy`
- `Gate::getPolicyFor(new UserSavedFilter())` → `App\Domain\Dashboard\Policies\UserSavedFilterPolicy`
- `vendor/bin/deptrac analyse --no-progress` → **0 violations, 0 errors, 0 warnings** (318 allowed, 2285 uncovered framework noise)
- Policy scan script → **26 policy files across 10 scan roots, 0 `{{ Placeholder }}` leaks**
- `vendor/bin/pest tests/Architecture/PolicyTemplateIntegrityTest.php` → **3 tests passing, 27 assertions** (placeholder scan + count floor ≥26 + Gate::getPolicyFor resolution for all 26 models)

**Deferred verification (requires MySQL online):**
- 5 Feature tests under `tests/Feature/Dashboard/` — all authored against correct schema + RefreshDatabase boot, execution deferred per Phase 6 Plan 01 precedent until `meetingstore_ops_testing` MySQL is reachable.

---

*Phase: 07-dashboard-polish-cutover*
*Plan: 01-data-model-foundation*
*Completed: 2026-04-24*
