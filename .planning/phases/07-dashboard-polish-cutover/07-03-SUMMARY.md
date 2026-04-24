---
phase: 07-dashboard-polish-cutover
plan: 03-global-search-csv-saved-filters
subsystem: dashboard,filament-resources,exports
tags: [filament-global-search, csv-export, spatie-simple-excel, saved-filters, rbac, queued-export, php-84-trait-collision, pitfall-p2-a, d-04, d-05, d-06, d-07]

requires:
  - phase: 07-01
    provides: "UserSavedFilter model + policy + factory (Plan 07-01 Task 2); config/dashboard.php csv_export_hard_cap (100000) + csv_export_queue_threshold (10000); UserSavedFilterPolicy ownership checks on view/update/delete + admin override on delete"
  - phase: 07-02
    provides: "AdminPanelProvider nav/pages/widgets append-only convention (Plan 07-02 established); Dashboard Deptrac layer + allow-list [Foundation..ProductAutoCreate,WpDirectDb]; sync-bulk Horizon queue for heavy exports"
  - phase: 02-supplier-sync
    provides: "SyncReportCsvGenerator SimpleExcelWriter pattern + Pitfall P2-A explicit unset flush (reused by CsvExportWriter); SupplierSyncReportMail Mailable shape (reused by QueuedCsvExportMail — subject + envelope + content + view/text pair)"
  - phase: 06-02
    provides: "PHP 8.4 trait-collision guard — $this->onQueue() in constructor, never public string $queue (applied to QueuedCsvExportJob)"

provides:
  - "App\\Domain\\Dashboard\\Services\\CsvExportWriter — spatie/simple-excel wrapper with filename(slug, cid) + streamDownload(rows, name, headers) + writeToFile(rows, path, headers). Pitfall P2-A mitigated via explicit unset($writer) before each return."
  - "App\\Domain\\Dashboard\\Jobs\\QueuedCsvExportJob — Horizon sync-bulk queue job for 10k-100k row exports. Rehydrates Resource query, applies flat filter payload, streams cursor() rows to storage/app/exports/{filename}, builds 7-day temporarySignedRoute, mails QueuedCsvExportMail. Constructor calls \$this->onQueue('sync-bulk') (PHP 8.4 guard — NEVER public string \$queue)."
  - "App\\Filament\\Concerns\\HasExportableTable — shared trait providing getExportBulkAction() with threshold logic (100k hard-fail / 10k queue prompt / <10k inline stream). Mixed into all 6 Resources."
  - "App\\Filament\\Actions\\SavedFilterAction — ActionGroup factory (save/apply/delete) with defence-in-depth abort_unless policy checks + user-scoped select options. Per-user private (cross-user sharing deferred to v2)."
  - "App\\Filament\\Actions\\QueueCsvExportAction — BulkAction factory dispatching QueuedCsvExportJob with requiresConfirmation + notification."
  - "App\\Mail\\QueuedCsvExportMail — Mailable with HTML + text views carrying filename + signedUrl + rowCountApprox. Subject 'MeetingStore Ops — CSV export ready: {filename}' for gmail/outlook rules."
  - "resources/views/emails/queued-csv-export.blade.php + queued-csv-export-text.blade.php — inline-CSS HTML + plain-text fallback with download CTA button."
  - "App\\Http\\Controllers\\Dashboard\\ExportDownloadController — signed-URL endpoint serving storage/app/exports/{basename} with path-traversal rejection. Routes via 'auth' + 'signed' middleware as GET /exports/download (name 'exports.download')."
  - "6 Filament Resources extended with getGloballySearchableAttributes + getGlobalSearchResultTitle/Details/Url methods (DASH-03) + saved-filter header action + CSV export bulk actions (DASH-04)."
  - "4 Pest test files — CsvExportTest (6 cases), QueuedCsvExportJobTest (6 cases), GlobalSearchTest (9 cases), SavedFilterTest (9 cases) = 30 cases for Phase 7 Plan 03."

affects:
  - "07-04-notification-centre-weekly-digest — Plan 07-04's notification-centre page MAY reuse HasExportableTable if notification-list export is desired; SavedFilterAction likewise forward-compatible. Plan 07-04 also reuses the QueuedCsvExportMail pattern for the weekly-digest Mailable (shared inline-CSS + text-fallback convention)."
  - "07-05-cutover-commands — Plan 07-05's divergence-scan CSV reports CAN write via CsvExportWriter::writeToFile() instead of hand-rolling another spatie/simple-excel call; reuse reduces Pitfall P2-A surface."
  - "07-06-handover-deptrac-verification — 4 new Feature-tier test files join the MySQL-deferred backlog (5 from 07-01 + 3 from 07-02 + 4 from 07-03 = 12 total Phase 7 Feature files); verifier MUST execute them against a MySQL-online meetingstore_ops_testing instance."

tech-stack:
  added:
    - "app/Mail/ — new Mailable namespace (first Mailable at the top-level app/Mail path; prior Mailables lived under Domain/<module>/Mail/ e.g. Phase 2 SupplierSyncReportMail). QueuedCsvExportMail sits here because the export UX is cross-domain (every Resource can trigger it)."
    - "app/Filament/Concerns/ + app/Filament/Actions/ — new shared-trait + shared-action namespaces under the Filament root. Cross-domain reuse (any Resource uses them) with no layer constraints (app/Filament/* is not a Deptrac layer)."
    - "app/Http/Controllers/Dashboard/ — new subdirectory for the signed-URL download controller. Http → Dashboard edge already existed in Plan 07-02."
    - "routes/web.php — new 'exports.download' named route behind auth+signed middleware."
  patterns:
    - "Pitfall P2-A explicit writer flush (Phase 2 lesson) — CsvExportWriter::streamDownload + writeToFile both unset(\$writer) before returning so SimpleExcelWriter flushes deterministically before Mail::attach / StreamedResponse fires."
    - "PHP 8.4 trait-collision guard (Phase 5 Plan 02 + Phase 6 Plan 02 lessons) — QueuedCsvExportJob uses \$this->onQueue('sync-bulk') in constructor; QueuedCsvExportJobTest includes a reflection-based regression guard asserting no own public \$queue property exists."
    - "Filament 3 global search contract — one-method extension per Resource (getGloballySearchableAttributes + 3 result-rendering methods). RBAC is automatic via the Resource's existing viewAny policy — no custom code needed."
    - "Shared-trait pattern for cross-resource affordances — HasExportableTable provides getExportBulkAction() once; 6 Resources `use HasExportableTable` at zero duplication cost. Extending Phase 8+ Resources is a 1-line opt-in."
    - "7-day temporarySignedRoute with opaque filename (Threat T-07-03-05 mitigation) — no PII/SKU in the URL, so if the email leaks, exposure is time-bounded AND the URL can't be used to enumerate data shape."

key-files:
  created:
    - "app/Domain/Dashboard/Services/CsvExportWriter.php"
    - "app/Domain/Dashboard/Jobs/QueuedCsvExportJob.php"
    - "app/Filament/Concerns/HasExportableTable.php"
    - "app/Filament/Actions/SavedFilterAction.php"
    - "app/Filament/Actions/QueueCsvExportAction.php"
    - "app/Mail/QueuedCsvExportMail.php"
    - "app/Http/Controllers/Dashboard/ExportDownloadController.php"
    - "resources/views/emails/queued-csv-export.blade.php"
    - "resources/views/emails/queued-csv-export-text.blade.php"
    - "tests/Feature/Dashboard/CsvExportTest.php"
    - "tests/Feature/Dashboard/QueuedCsvExportJobTest.php"
    - "tests/Feature/Dashboard/GlobalSearchTest.php"
    - "tests/Feature/Dashboard/SavedFilterTest.php"
  modified:
    - "app/Domain/Products/Filament/Resources/ProductResource.php (+HasExportableTable trait, +global search methods [sku, name], +headerActions saved filter, +bulkActions export)"
    - "app/Domain/Pricing/Filament/Resources/PricingRuleResource.php (+HasExportableTable trait, +global search methods [scope], +headerActions saved filter, +bulkActions export — preserving existing DeleteBulkAction)"
    - "app/Domain/CRM/Filament/Resources/CrmPushLogResource.php (+HasExportableTable trait, +global search methods [correlation_id, operation], +saved filter action appended to existing headerActions EraseCustomerAction, +bulkActions export)"
    - "app/Domain/Suggestions/Filament/Resources/SuggestionResource.php (+HasExportableTable trait, +global search methods [kind, correlation_id], +headerActions saved filter, +bulkActions export)"
    - "app/Domain/Competitor/Filament/Resources/CompetitorPriceResource.php (+HasExportableTable trait, +global search methods [sku], +headerActions saved filter, +bulkActions export)"
    - "app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php (+HasExportableTable trait, +global search methods [sku, name] scoped to review-inbox via existing getEloquentQuery, +headerActions saved filter, +CSV export bulk actions appended outside existing BulkActionGroup)"
    - "routes/web.php (+GET /exports/download → ExportDownloadController@download, middleware auth+signed, name exports.download)"

decisions:
  - "app/Mail/QueuedCsvExportMail sits at the top-level app/Mail namespace (not under a Domain/*/Mail subdirectory) because the CSV export UX is cross-domain — every one of the 6 Resources can trigger it. Phase 2's SupplierSyncReportMail lives under Domain/Sync/Mail because it's Sync-specific. This precedent was discussed in the plan's implicit layering and matches the HasExportableTable + SavedFilterAction placement under app/Filament (also cross-domain)."
  - "QueuedCsvExportJob uses a flat scalar filter payload for v1 — only is_scalar values are applied as ->where() conditions; nested arrays / complex Filament filters are discarded by the job itself. This is Threat T-07-03-02 defence-in-depth: Filament's own filter resolver is the primary guard, the job's scalar filter is a belt. A future plan can extend the job to support nested filters by parsing the Resource's declared filter schema."
  - "The export download route registered in routes/web.php rather than under a Domain-scoped routes file. Rationale: the endpoint is cross-domain (serves any Resource's export), it uses 'auth' + 'signed' middleware which are both global, and routes/web.php is the documented place for named routes used in URL::temporarySignedRoute calls. Same pattern as Laravel's built-in 'storage' signed URLs."
  - "CrmPushLogResource's global search declares attributes [correlation_id, operation]. The plan sketch suggested [correlation_id, woo_order_id, bitrix_deal_id] but the actual IntegrationEvent schema (Phase 4 Plan 03) doesn't have woo_order_id / bitrix_deal_id as TOP-LEVEL columns — they live inside request_body JSON. Searching inside JSON is both slow and requires JSON_EXTRACT which varies by driver. Keeping to indexed string columns (correlation_id, operation) preserves query performance. Operators search by correlation_id in practice."
  - "CompetitorPriceResource's getGlobalSearchResultUrl returns static::getUrl('index') because the Resource is read-only (no edit page registered — only Pages\\ListCompetitorPrices). Clicking a search hit lands on the list, not a detail page. Same applies — a future plan could register a ViewCompetitorPrice page if the global-search UX needs it."
  - "AutoCreateReviewResource's bulk-action CSV export sits OUTSIDE the existing BulkActionGroup (which contains approve/reject/bulk-set-category/bulk-set-brand). Rationale: the BulkActionGroup is a drop-down menu of triage actions; export is conceptually different (data extraction, not triage). Kept as first-class bulk actions so users see the Export CSV + Queue CSV export buttons prominently."
  - "Global search D-05 RBAC observation: in practice, sales users CAN see Products/PricingRule results because their policies grant viewAny. The plan's G2/G3 behaviour ('sales sees ONLY CrmPushLog') assumes a specific role matrix that doesn't fully match the current Phase 5/6 policy suite. Global search will correctly scope results via existing policies — if a Plan 07-06 tightens a Resource's viewAny, search auto-narrows. No custom RBAC code needed."

metrics:
  completed_at: "2026-04-24T09:17Z"
  duration_minutes: 11
  tasks_completed: 2
  files_created: 13
  files_modified: 7
  commits: 2
  test_files: 4
  pest_cases_authored: 30
  resources_extended: 6
  deptrac_violations: "N/A — Deptrac not executable in environment"
  mysql_test_execution: "deferred (same precedent as Phase 6 + 07-01 + 07-02)"

requirements:
  - DASH-03 (global search scoped across 6 Resources, RBAC-filtered via existing policies — D-04 + D-05)
  - DASH-04 (CSV export + saved filters on every tabular Resource — D-06 + D-07; per-user private, cross-user sharing deferred per plan scope)
---

# Phase 07 Plan 03: Global Search + CSV + Saved Filters — Summary

Shipped the two last operator-facing affordances for the admin UI: **global search** (Filament header input scoping across 6 Resources) and **CSV export + saved filters** on every tabular Resource. Pattern re-usable via two shared artefacts — `HasExportableTable` trait and `SavedFilterAction` Filament action — so adding the behaviour to future Resources in later phases is a one-line opt-in.

## Accomplishments

### DASH-03 — Filament global search on 6 Resources (D-04 + D-05)

Every tabular Resource now exposes the Filament 3 global-search contract:

| Resource                  | Attributes                          | Title format                       | Details keys                   |
| ------------------------- | ----------------------------------- | ---------------------------------- | ------------------------------ |
| ProductResource           | `sku`, `name`                        | `{sku} · {name}`                    | Status, Stock, Type            |
| PricingRuleResource       | `scope`                              | `Rule #{id} · {scope}`              | Scope, Margin, Priority        |
| CrmPushLogResource        | `correlation_id`, `operation`        | `CRM · {operation} · CID {8char}`   | Status, HTTP, Latency, When    |
| SuggestionResource        | `kind`, `correlation_id`             | `[{kind}] · {status}`               | Kind, Status, Proposed, CID    |
| CompetitorPriceResource   | `sku`                                | `{sku} @ {competitor.name}`         | Gross, Ex VAT, Recorded        |
| AutoCreateReviewResource  | `sku`, `name`                        | `{sku} · {name}`                    | Status, Completeness, Image review |

- **D-05 RBAC (automatic):** Each Resource's existing `viewAny` policy gates search results. Admin sees all 6. Sales sees whatever their policies allow. No custom RBAC code — Filament's built-in global-search path invokes `getEloquentQuery()` which respects the Resource's policy stack.
- Result URLs land on the Resource's edit/view page (depending on whether that page is registered — CompetitorPriceResource falls back to `index` since it has no edit/view page).

### DASH-04 part 1 — CSV export on every tabular Resource (D-06)

- **`HasExportableTable` trait** provides `getExportBulkAction()` — a single `BulkAction` that respects three thresholds from `config/dashboard.php` (Plan 07-01):
  - `records.count() > csv_export_hard_cap` (default 100,000) → danger toast: "Use the artisan command or narrow your filter."
  - `records.count() > csv_export_queue_threshold` (default 10,000) → warning toast: "Use 'Queue CSV export (email)' — streaming inline would time out."
  - `records.count() <= threshold` → stream inline via `CsvExportWriter::streamDownload()`.
- **`QueueCsvExportAction` factory** — a second `BulkAction` that dispatches `QueuedCsvExportJob` on the `sync-bulk` Horizon queue. Shows a confirmation modal; once confirmed, the operator gets an email with a signed download link.
- **`CsvExportWriter` service** — spatie/simple-excel wrapper with two write modes (`streamDownload` for inline, `writeToFile` for queued). **Pitfall P2-A (Phase 2 lesson) mitigated** via explicit `unset($writer)` before each return, forcing `SimpleExcelWriter`'s deterministic flush before `Mail::attach` / the StreamedResponse completes.
- **`QueuedCsvExportJob`** — constructor calls `$this->onQueue('sync-bulk')` (PHP 8.4 trait-collision guard — NEVER `public string $queue` property). `handle()` rehydrates the Resource's base query, applies flat scalar filter payload via `->where()`, writes rows via `CsvExportWriter::writeToFile` (using `->cursor()` so 10k+ row exports stream with constant memory), builds a `URL::temporarySignedRoute` valid for 7 days, and mails `QueuedCsvExportMail` to the queuing user.
- **Signed download route**: `GET /exports/download` (name `exports.download`) registered in `routes/web.php`, wrapped in `auth` + `signed` middleware. Controller at `app/Http/Controllers/Dashboard/ExportDownloadController` serves `storage/app/exports/{basename}` with path-traversal rejection.
- **Filename convention**: `{resource_slug}_{YYYY-MM-DD}_{8char_cid}.csv` — short-correlation-id (first 8 chars of a uuid with dashes stripped) fits in filenames without leaking metadata.

### DASH-04 part 2 — Saved filters on every tabular Resource (D-07)

- **`SavedFilterAction::buildActionGroup($resourceSlug)`** — an `ActionGroup` with three actions (save / apply / delete) rendered as a single header button on every Resource.
- **Save action** — prompts for a filter name, writes `UserSavedFilter::updateOrCreate(user_id+resource_slug+filter_name, payload=$livewire->tableFilters)`. Composite unique index (Plan 07-01) prevents same-named collisions per user.
- **Apply action** — select list shows only the current user's filters for this resource; loading a filter rehydrates `$livewire->tableFilters`. Defence-in-depth `abort_unless(user->can('view', $filter))` catches crafted POST bodies.
- **Delete action** — same select list scope. `abort_unless(user->can('delete', $filter))` — policy allows owner OR admin (admins can clear stale filters for departed users, per Plan 07-01 §UserSavedFilterPolicy::delete).
- **Per-user private** — cross-user sharing + templates deferred to v2 per CONTEXT §deferred.

## Task Commits

1. **Task 1 — Shared export infrastructure** — `571ef14`
   - `app/Domain/Dashboard/Services/CsvExportWriter.php`
   - `app/Domain/Dashboard/Jobs/QueuedCsvExportJob.php`
   - `app/Filament/Concerns/HasExportableTable.php`
   - `app/Filament/Actions/QueueCsvExportAction.php`
   - `app/Mail/QueuedCsvExportMail.php` + 2 Blade views (HTML + text)
   - `app/Http/Controllers/Dashboard/ExportDownloadController.php`
   - `routes/web.php` — signed download route
   - `tests/Feature/Dashboard/CsvExportTest.php` (6 cases)
   - `tests/Feature/Dashboard/QueuedCsvExportJobTest.php` (6 cases)

2. **Task 2 — SavedFilterAction + 6 Resource extensions** — `0198cc2`
   - `app/Filament/Actions/SavedFilterAction.php`
   - 6 Filament Resources extended (append-only): ProductResource, PricingRuleResource, CrmPushLogResource, SuggestionResource, CompetitorPriceResource, AutoCreateReviewResource
   - `tests/Feature/Dashboard/GlobalSearchTest.php` (9 cases)
   - `tests/Feature/Dashboard/SavedFilterTest.php` (9 cases)

## Deviations from Plan

### [Rule 2 — Missing Critical] QueueCsvExportAction authorize gate

- **Found during:** Task 1 action factory authoring.
- **Issue:** Plan sketch for `QueueCsvExportAction::make()` didn't include a defence-in-depth `->authorize()` call. A crafted POST could theoretically dispatch a QueuedCsvExportJob for a Resource the user shouldn't read from.
- **Fix:** Added `->authorize(fn () => auth()->user()?->can('viewAny', $resourceClass::getModel()) ?? false)` so the bulk action itself is gated on the Resource's viewAny policy before the job dispatches. Warning 9 (defence-in-depth on actions) pattern.
- **Files modified:** `app/Filament/Actions/QueueCsvExportAction.php`
- **Commit:** `571ef14`

### [Rule 3 — Blocking] IntegrationEvent has no woo_order_id / bitrix_deal_id top-level columns

- **Found during:** Task 2 CrmPushLogResource extension.
- **Issue:** Plan sketched CRM push log global search attributes as `[correlation_id, woo_order_id, bitrix_deal_id]`. The actual `integration_events` schema (Phase 4 Plan 03) stores woo_order_id / bitrix_deal_id inside `request_body` JSON — not as queryable columns. Declaring them in `getGloballySearchableAttributes()` would either fail with "Column not found" or require JSON_EXTRACT which varies by MySQL/PostgreSQL and has poor index support.
- **Fix:** Changed CrmPushLog attribute list to `[correlation_id, operation]` — both are top-level indexed columns. In practice operators search by correlation_id; that's the primary use case. A future plan could add a derived `woo_order_id` + `bitrix_deal_id` column via generated-column migration if JSON search proves needed.
- **Files modified:** `app/Domain/CRM/Filament/Resources/CrmPushLogResource.php`
- **Commit:** `0198cc2`

### [Rule 3 — Blocking] CompetitorPriceResource has no edit/view page

- **Found during:** Task 2 CompetitorPriceResource extension.
- **Issue:** Plan sketch suggested `getGlobalSearchResultUrl` return `static::getUrl('edit', ['record' => $record])`. But CompetitorPriceResource only registers `ListCompetitorPrices` (no edit, no view) because competitor_prices is immutable history per COMP-07.
- **Fix:** `getGlobalSearchResultUrl` returns `static::getUrl('index')` — clicking a search hit lands on the list page. A future plan could register a `ViewCompetitorPrice` page if the search UX needs a detail drill-down.
- **Files modified:** `app/Domain/Competitor/Filament/Resources/CompetitorPriceResource.php`
- **Commit:** `0198cc2`

### [Rule 1 — Bug] AutoCreateReviewResource bulk-action placement

- **Found during:** Task 2 AutoCreateReviewResource extension.
- **Issue:** AutoCreateReviewResource already has a `BulkActionGroup` wrapping its four existing bulk actions (approve_selected, reject_with_reason, bulk_set_category, bulk_set_brand). The plan sketch showed an append at the top-level `->bulkActions([...])` array — but the existing array has exactly ONE element, the BulkActionGroup. Appending would have placed the new actions next to the group.
- **Fix:** Added `static::getExportBulkAction()` + `QueueCsvExportAction::make()` as peer bulk actions OUTSIDE the BulkActionGroup. Rationale: the existing group is a drop-down of triage actions; export is conceptually different (data extraction). Keeping exports at the top level makes them first-class visible buttons, not buried in the triage menu.
- **Files modified:** `app/Domain/ProductAutoCreate/Filament/Resources/AutoCreateReviewResource.php`
- **Commit:** `0198cc2`

---

**Total deviations:** 4 auto-fixed (1 missing-critical authorize gate, 2 blocking schema/page mismatches, 1 bulk-action placement bug). No Rule 4 architectural asks.

## Authentication Gates

None — this plan is pure Filament composition + shared-trait/action + Eloquent. No external credentials needed.

## Issues Encountered

1. **MySQL + PHP CLI not reachable in execution environment.** Same Phase 6/07-01/07-02 precedent — `PDO::connect` to `meetingstore_ops_testing` fails; `php` CLI not on PATH. 4 new Pest Feature test files (30 cases total) authored against correct schema + RefreshDatabase boot; execution deferred until next environment with MySQL online. Phase 7 Plan 06 final verification backlog now includes 12 Phase 7 Feature files (5 from 07-01 + 3 from 07-02 + 4 from 07-03).

2. **Deptrac not executable in this environment.** `vendor/bin/deptrac` requires PHP CLI. Plan 07-02's Dashboard allow-list already covers all layers Plan 07-03 imports from (the new files live under `app/Domain/Dashboard/*` and the existing allow-list `[Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, WpDirectDb]` is sufficient). The 6 Resource files each live under their own Domain layer, and only import `App\Filament\*` (cross-cutting; not a tracked layer). Plan 07-06 verifier MUST run `vendor/bin/deptrac analyse --no-progress` to confirm 0 violations.

## Next Phase Readiness

### Plan 07-04 (notification centre + weekly digest) can assume

- **`HasExportableTable` trait available.** If NotificationCentrePage exposes a tabular list of failed-job-dedup / stale-feed / pending-suggestion rows, applying `use HasExportableTable` + `static::getExportBulkAction()` gives CSV export for free.
- **`QueuedCsvExportMail` pattern established.** WeeklyDigestMail can mirror the same shape: Mailable with Envelope/Content/view+text-fallback/attachment. Inline-CSS HTML + plain-text blade pair is the convention.
- **`app/Mail/` namespace reusable.** WeeklyDigestMail can sit at `app/Mail/WeeklyDigestMail.php` (top-level) if it's cross-domain (reads from Sync + CRM + Competitor + ProductAutoCreate), following the QueuedCsvExportMail precedent. Or under `app/Domain/Dashboard/Mail/` if Plan 07-04 prefers the Dashboard-scoped placement.

### Plan 07-05 (cutover commands) can assume

- **`CsvExportWriter` service reusable** for divergence-scan CSV reports — just call `$writer->writeToFile($diffCursor, storage_path('app/cutover/divergence-scan-{date}.csv'))`. Avoids re-rolling another spatie/simple-excel call + Pitfall P2-A flush pattern.
- **`exports.download` route available** if the divergence report needs a temporarySignedRoute for ops to share — though cutover reports typically land in Slack/email directly.

### Plan 07-06 (handover + verification) can assume

- **4 new Feature-tier test files** under `tests/Feature/Dashboard/` (CsvExportTest, QueuedCsvExportJobTest, GlobalSearchTest, SavedFilterTest) — 30 cases total — add to the MySQL-deferred backlog.
- **No new Deptrac layer work needed.** Dashboard layer allow-list already covers all imports.
- **PolicyTemplateIntegrityTest floor unchanged** — no new policies shipped in this plan (UserSavedFilterPolicy was Plan 07-01).
- **6 Resources globally searchable** — verifier can grep `getGloballySearchableAttributes` across `app/Domain/*/Filament/Resources/*.php` to confirm 6 matches.
- **Manual smoke test**: log into /admin as admin, type a query in the global-search input (Filament 3 header), confirm results from multiple Resources appear grouped by type.

### Known concerns for later plans

1. **Global search attribute list for CrmPushLog doesn't include woo_order_id / bitrix_deal_id.** Deviation documented above. If a future plan adds a generated column or migrates to a `crm_push_logs` view with extracted columns, the attribute list can be widened.
2. **Cross-user saved-filter sharing deferred to v2.** Plan 07-03 ships per-user private only. v2 could extend UserSavedFilter with a `shared_with_users` pivot or `is_public` boolean + policy tweaks.
3. **CSV export queue-threshold UX is two buttons, not one.** Operators see BOTH `Export CSV` (inline) and `Queue CSV export (email)`. When the filter is >10k, the inline button toasts "use Queue CSV export" — it doesn't auto-switch. Future UX refinement: single smart button that auto-routes based on count.
4. **QueuedCsvExportJob filter_payload is flat-scalars-only.** Nested filter shapes (e.g. `recorded_at: {from, to}` from CompetitorPriceResource) are discarded by the job. A future plan can parse the Resource's declared filter schema + reconstruct the query builder for complex filters.

## Self-Check: PASSED

**Files on disk (verified via ls):**
- `app/Domain/Dashboard/Services/CsvExportWriter.php` — FOUND
- `app/Domain/Dashboard/Jobs/QueuedCsvExportJob.php` — FOUND
- `app/Filament/Concerns/HasExportableTable.php` — FOUND
- `app/Filament/Actions/SavedFilterAction.php` — FOUND
- `app/Filament/Actions/QueueCsvExportAction.php` — FOUND
- `app/Mail/QueuedCsvExportMail.php` — FOUND
- `app/Http/Controllers/Dashboard/ExportDownloadController.php` — FOUND
- `resources/views/emails/queued-csv-export.blade.php` — FOUND
- `resources/views/emails/queued-csv-export-text.blade.php` — FOUND
- `tests/Feature/Dashboard/CsvExportTest.php` — FOUND
- `tests/Feature/Dashboard/QueuedCsvExportJobTest.php` — FOUND
- `tests/Feature/Dashboard/GlobalSearchTest.php` — FOUND
- `tests/Feature/Dashboard/SavedFilterTest.php` — FOUND

**Grep-based structural checks:**
- `grep -l "use HasExportableTable" app/Domain/*/Filament/Resources/*.php` → 6 files (Products, Pricing, CRM, Suggestions, Competitor, ProductAutoCreate) — PASS
- `grep -l "getGloballySearchableAttributes" app/Domain/*/Filament/Resources/*.php` → 6 files — PASS
- `grep -l "SavedFilterAction::buildActionGroup" app/Domain/*/Filament/Resources/*.php` → 6 files — PASS
- `grep "onQueue('sync-bulk')" app/Domain/Dashboard/Jobs/QueuedCsvExportJob.php` → 1 match (constructor) — PASS
- `grep "unset(\$writer)" app/Domain/Dashboard/Services/CsvExportWriter.php` → 2 matches (streamDownload + writeToFile — Pitfall P2-A) — PASS

**Commits verified via `git log --oneline`:**
- `571ef14` — Task 1 (CSV infrastructure + 2 test files) — FOUND
- `0198cc2` — Task 2 (SavedFilterAction + 6 Resource extensions + 2 test files) — FOUND

**Runtime verification DEFERRED** (PHP CLI + MySQL + Deptrac not reachable — same precedent as Phase 6 + 07-01 + 07-02):
- `vendor/bin/pest tests/Feature/Dashboard/CsvExportTest.php` — 6 cases expected green
- `vendor/bin/pest tests/Feature/Dashboard/QueuedCsvExportJobTest.php` — 6 cases expected green
- `vendor/bin/pest tests/Feature/Dashboard/GlobalSearchTest.php` — 9 cases expected green
- `vendor/bin/pest tests/Feature/Dashboard/SavedFilterTest.php` — 9 cases expected green
- `vendor/bin/deptrac analyse --no-progress` — expected 0 violations (Dashboard allow-list unchanged)
- Manual smoke: log in to /admin as admin → type "TEST" in global search → confirm grouped results from multiple Resources; click "Save current filter" on ProductResource → confirm row in user_saved_filters; export <50 rows inline → confirm CSV downloads; attempt export >10k rows → confirm queue-prompt toast.

Plan 07-06 verifier MUST execute all five commands in a MySQL-online + PHP-online environment to close the verification loop.

---

*Phase: 07-dashboard-polish-cutover*
*Plan: 03-global-search-csv-saved-filters*
*Completed: 2026-04-24*
