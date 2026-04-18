---
phase: 02-supplier-sync
plan: 04-reporting-ui
subsystem: reporting,filament,shield,rbac
tags: [d-08-opt-in, d-10-csv, p2-a-writer-flush, p2-g-n-plus-one, p2-h-shield-restore, sync-08, sync-11, sync-12, d-01-product-resource]

requires:
  - phase: 02-01-data-model
    provides: "SyncRun + SyncRunItem + ImportIssue + Product + ProductVariant models + factories + 5 hand-written policies; AppServiceProvider Gate::policy bindings"
  - phase: 02-02-external-clients
    provides: "spatie/simple-excel ^3.9 installed (Plan 02-04's CSV generator depends on it); WooClient + SupplierClient functional"
  - phase: 02-03-orchestration
    provides: "SyncSupplierCommand finalise+abort paths (Plan 02-04 wires CSV + email at both exit points); 4 domain events + stub listener; 192-test baseline + 0 Deptrac violations"
  - phase: 01-foundation
    provides: "AlertRecipient model + CRUD Resource (Plan 02-04 extends with receives_sync_reports); 7 Horizon supervisors; 4-role RBAC seeder with LIKE patterns; Pitfall K hand-written-policy pattern"

provides:
  - "database/migrations/2026_04_18_200600_add_receives_sync_reports_to_alert_recipients.php — D-08 opt-in column (BOOLEAN nullable default TRUE, applied to dev + testing DBs)"
  - "AlertRecipient model: $fillable + $casts updated for receives_sync_reports; active() + receivesSyncReports() scopes"
  - "AlertRecipientResource form: Toggle field + IconColumn for receives_sync_reports (D-08 opt-in UX)"
  - "AlertRecipientSeeder: seeded fallback row sets receives_sync_reports=true (Pitfall M equivalent for sync reports)"
  - "App\\Domain\\Sync\\Reports\\SyncReportCsvGenerator: writes 11-column D-10 CSV via spatie/simple-excel chunked at 500 rows; unset(\$writer) before return (Pitfall P2-A)"
  - "App\\Domain\\Sync\\Mail\\SupplierSyncReportMail: Mailable with envelope subject that distinguishes aborted vs completed; attaches CSV as text/csv MIME"
  - "resources/views/emails/supplier-sync-report.blade.php: plain-HTML report body with 6 aggregate counts + correlation_id + resume hint"
  - "SyncSupplierCommand: private emailReport(SyncRun, bool \$aborted) hooked into finalise + JWT-abort + SyncAbort paths; recipients filtered via active().receivesSyncReports(); warn-and-skip when empty"
  - "App\\Domain\\Sync\\Filament\\Resources\\SyncRunResource (SYNC-11): table with status/dry_run/counters + 2 RelationManagers (SyncErrorsRelationManager, SyncRunItemsRelationManager); getEloquentQuery()->withCount(['errors','items']) for P2-G N+1 prevention; admin-only 'Retry aborted run' header action (Pitfall K: ->authorize() AND ->visible())"
  - "ViewSyncRun page: Infolist with Run Details + Counts + Abort Details (conditional) + Cursor sections"
  - "App\\Domain\\Sync\\Filament\\Resources\\ImportIssueResource (SYNC-12): 4 issue_type filter + TernaryFilter resolved/unresolved + BulkAction markResolved gated via authorize + visible hasAnyRole (Warning 9 defence-in-depth)"
  - "App\\Domain\\Products\\Filament\\Resources\\ProductResource (D-01): all 4 roles view; pricing_manager+admin edit price/cost/status; woo_product_id + sku + type disabled (Woo canonical)"
  - "ProductResource\\VariantsRelationManager: canViewForRecord() gates on ownerRecord->type==='variable' (D-01 variant drill-down only when relevant)"
  - "AdminPanelProvider: discoverResources for Sync + Products domains (Suggestions + Alerting from Phase 1 unchanged)"
  - "RolePermissionSeeder: BOTH underscore-style AND :: style LIKE patterns (Shield 3.9.10 emits view_product + view_sync::run — forward-compat for both)"
  - "tests/Feature/PolicyTemplateIntegrityTest.php: grep guardrail — zero `{{ ` literals in any shipped Policy (Pitfall P2-H regression catcher)"
  - "22 new Pest tests: ReceivesSyncReports 5, CSV generator 5, Mail 6, SyncRunResource 6, ImportIssue 4, Product 4, PolicyTemplateIntegrity 1 — net +31 after shield:generate (some tests overlapped) — full suite 223 pass + 2 skipped"
  - "Deptrac ruleset: Sync → Alerting added (SyncSupplierCommand reads AlertRecipient for D-08 distribution — legitimate cross-domain)"

affects:
  - "02-05-guardrails: PolicyTemplateIntegrityTest foundation laid — Plan 02-05 promotes to the Architecture suite with broader coverage (policy role-assignment drift, stub grep, etc.)"
  - "Phase 3+: pricing_manager has 26 perms now (Product 12 + ImportIssue 12 + view-only sync::run 2) — PricingRule Resource will auto-include via `%_pricing_rule` LIKE"
  - "Phase 7 cutover runbook: post-cutover `Schedule::command('sync:supplier --live')` uncomment will trigger the CSV + email on daily schedule; ops runbook references the new /admin/sync-runs URL"

tech-stack:
  added:
    - "spatie/simple-excel ^3.9 — already installed by Plan 02-02; this plan is the first consumer"
    - "Filament Infolist component (Section + TextEntry) — first use in this codebase; read-only drill-down for ViewSyncRun"
  patterns:
    - "Pitfall P2-A CSV flush: unset(\$writer) before returning the path — SimpleExcelWriter's __destruct flush happens only on GC; async Mail::attach() on queued mail would read a partial file otherwise."
    - "Warning 9 defence-in-depth on Filament Actions: BOTH ->authorize() AND ->visible() closures; the former enforces at POST dispatch even if UI visibility is bypassed via crafted Livewire calls."
    - "Shield permission-name separator-agnostic seeder LIKE patterns: admit BOTH `%_foo` (Phase 1 style) and `%foo::bar` (Phase 2 observed) so regenerating Shield later with different config does not silently drop perms."
    - "Post-shield:generate restore protocol: `git checkout HEAD -- <policy-paths>` for the 6 policies Shield regenerates; ProductVariantPolicy survives because there's no ProductVariantResource (variants are a RelationManager)."
    - "Mail::assertSent callback takes ONE argument (\$mail). Inspect recipients via \$mail->to — an array of ['address' => ..., 'name' => ...] entries. Laravel 12 MailFake removed the multi-arg form."
    - "Filament multi-select filter in tests: pass values as positional array `['value1', 'value2']`, NOT `['values' => [...]]` (Filament 3.3 Livewire contract)."
    - "SupplierClient is `final`; test stubs use `app()->instance(SupplierClient::class, new class { ... })` anonymous-class pattern (not Mockery::mock which rejects final classes)."

key-files:
  created:
    - "database/migrations/2026_04_18_200600_add_receives_sync_reports_to_alert_recipients.php"
    - "app/Domain/Sync/Reports/SyncReportCsvGenerator.php"
    - "app/Domain/Sync/Mail/SupplierSyncReportMail.php"
    - "resources/views/emails/supplier-sync-report.blade.php"
    - "app/Domain/Sync/Filament/Resources/SyncRunResource.php"
    - "app/Domain/Sync/Filament/Resources/SyncRunResource/Pages/ListSyncRuns.php"
    - "app/Domain/Sync/Filament/Resources/SyncRunResource/Pages/ViewSyncRun.php"
    - "app/Domain/Sync/Filament/Resources/SyncRunResource/RelationManagers/SyncErrorsRelationManager.php"
    - "app/Domain/Sync/Filament/Resources/SyncRunResource/RelationManagers/SyncRunItemsRelationManager.php"
    - "app/Domain/Sync/Filament/Resources/ImportIssueResource.php"
    - "app/Domain/Sync/Filament/Resources/ImportIssueResource/Pages/ListImportIssues.php"
    - "app/Domain/Sync/Filament/Resources/ImportIssueResource/Pages/EditImportIssue.php"
    - "app/Domain/Products/Filament/Resources/ProductResource.php"
    - "app/Domain/Products/Filament/Resources/ProductResource/Pages/ListProducts.php"
    - "app/Domain/Products/Filament/Resources/ProductResource/Pages/ViewProduct.php"
    - "app/Domain/Products/Filament/Resources/ProductResource/Pages/EditProduct.php"
    - "app/Domain/Products/Filament/Resources/ProductResource/RelationManagers/VariantsRelationManager.php"
    - "tests/Feature/ReceivesSyncReportsColumnTest.php"
    - "tests/Feature/SyncReportCsvGeneratorTest.php"
    - "tests/Feature/SyncReportMailTest.php"
    - "tests/Feature/SyncRunResourceTest.php"
    - "tests/Feature/ImportIssueResourceTest.php"
    - "tests/Feature/ProductResourceTest.php"
    - "tests/Feature/PolicyTemplateIntegrityTest.php"
  modified:
    - "app/Domain/Alerting/Models/AlertRecipient.php — + receives_sync_reports fillable/cast + active()/receivesSyncReports() scopes"
    - "app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php — + receives_sync_reports Toggle + IconColumn"
    - "database/seeders/AlertRecipientSeeder.php — set receives_sync_reports=true on seeded fallback"
    - "app/Domain/Sync/Commands/SyncSupplierCommand.php — + emailReport() hook at finalise + abort paths; Mail + CSV generator wiring"
    - "app/Providers/Filament/AdminPanelProvider.php — + discoverResources for Domain/Sync + Domain/Products"
    - "database/seeders/RolePermissionSeeder.php — + underscore-style and :: style LIKE patterns for product_variant + import_issue; + view_sync::run/view_any_sync::run explicit entries"
    - "depfile.yaml + deptrac.yaml — Sync ruleset now [Foundation, Products, Alerting] (Plan 02-04 adds Alerting)"
    - "tests/Feature/Phase02DataModelTest.php — migrate:rollback step 6 → 7 (captures the new additive column migration at 200600)"
    - ".gitignore — + /.tmp for shield:generate logs and policy hash snapshots"

key-decisions:
  - "Pitfall P2-A CSV flush via explicit unset(\$writer) — spatie/simple-excel's SimpleExcelWriter flushes buffer on __destruct, but PHP's GC timing is non-deterministic. If a queued Mail job attaches the file before the writer object is GC'd, the attached CSV can be partial. Explicit unset() forces immediate destruct + flush."
  - "Shield generated permission names with `::` separator for multi-word Resources (e.g., `view_sync::run`, `update_import::issue`, `view_any_alert::recipient`). Phase 1's single-word Resources (Product, Role, Suggestion) kept underscore style. Seeder updated to query BOTH styles via LIKE patterns — survives a future Shield config change that flips the separator convention."
  - "SupplierClient is declared `final` in Phase 02-02 — test mocks cannot use Mockery (rejects final). Use `app()->instance(SupplierClient::class, new class { public function fetchAllProducts(): array { ... } })` — same pattern as DryRunModeTest from Plan 02-03. A future Phase 02-05 hardening PR could introduce a `SupplierClientContract` interface, but not needed for Plan 02-04 tests which only require fetchAllProducts + JWT-throwing behavior."
  - "ProductVariantPolicy survived shield:generate unharmed because there's NO ProductVariantResource (variants are a RelationManager under ProductResource). Shield only regenerates policies for discovered Resources. Our hand-written ProductVariantPolicy remains canonical via AppServiceProvider's Gate::policy binding."
  - "VariantsRelationManager's canViewForRecord() returns true only when ownerRecord->type === 'variable' — D-01 heuristic keeps the drill-down tab hidden for simple products (no variants exist; tab would be empty and confusing)."
  - "Filament multi-select filter test syntax: Filament 3.3 Livewire contract accepts positional array `->filterTable('status', ['aborted'])`, NOT `['values' => [...]]` (documented in docs as 'select' shape, but Livewire wiring expects positional). Tracked as a gotcha for future test authors."
  - "Phase02DataModelTest's migrate:rollback step=6 → step=7 because the new 200600 column migration now shares batch with the Phase 2 table migrations. Bumping the step ensures the rollback sweeps it too, keeping the round-trip assertion valid."

requirements-completed:
  - SYNC-08
  - SYNC-11
  - SYNC-12

duration: ~30 min
completed: 2026-04-18
---

# Phase 02 Plan 04: Reporting UI Summary

**Phase 2 observability + triage shipped: D-08 sync-report distribution (migration + CSV generator with D-10 11-column contract + Mailable + SyncSupplierCommand wiring at both finalise + abort exit points); SYNC-11 SyncRunResource Filament page with 2 RelationManagers (errors + items drill-down); SYNC-12 ImportIssueResource with 4-type filter + admin/pricing_manager resolve bulk action; D-01 ProductResource with VariantsRelationManager (variable-only drill-down). Shield regeneration handled per Pitfall P2-H: 6 policies restored from HEAD post-generate; RolePolicy's recurring `{{ Placeholder }}` leak fixed; PolicyTemplateIntegrityTest added as regression catcher. Seeder LIKE patterns now admit BOTH `_foo` and `foo::bar` name styles — separator-agnostic + forward-compat. 31 new Pest tests; full suite 223 pass + 2 skipped (was 192 baseline + 31 net new = 223); Deptrac 0 violations (+Alerting allowed under Sync ruleset for D-08 distribution). Zero regressions across Phase 1's 92 + Phase 2's 100 Plans 01-03 baselines.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-04-18T22:15Z
- **Completed:** 2026-04-18T22:45Z
- **Tasks:** 3 (Task 1 reporting, Task 2a non-destructive Filament, Task 2b destructive shield:generate)
- **Commits:** 3 task commits + 1 forthcoming metadata commit
- **Files created:** 24 (6 production CSV/Mail/migration + 5 Resources + 4 Pages + 2 RelationManagers + 7 tests)
- **Files modified:** 9 (AlertRecipient model + Resource, AlertRecipientSeeder, SyncSupplierCommand, AdminPanelProvider, RolePermissionSeeder, depfile + deptrac, Phase02DataModelTest, .gitignore)

## Task Commits

1. **Task 1** — `bd5f82c` `feat(02-04): D-08 sync-report distribution — migration + CSV generator + Mailable + SyncSupplierCommand wire-in` — 14 files, +697 / -5
2. **Task 2a** — `0da1ab2` `feat(02-04): Filament Resources for SyncRun + ImportIssue + Product (SYNC-11/12 + D-01)` — 18 files, +986 / -1
3. **Task 2b** — `5db84b5` `feat(02-04): shield:generate for Phase 2 Resources + policy restoration (Pitfall P2-H)` — 3 files, +78 / -15

## Accomplishments

### Reporting pipeline (Task 1, SYNC-08)

- **D-08 migration** — `alert_recipients.receives_sync_reports` BOOLEAN nullable default TRUE, applied to dev + testing DBs.
- **AlertRecipient model** — `receives_sync_reports` added to `$fillable` + `$casts`; `active()` + `receivesSyncReports()` scopes chainable.
- **AlertRecipientResource form** — opt-out Toggle with helper text + table IconColumn ("Reports?").
- **AlertRecipientSeeder** — seeded fallback gets `receives_sync_reports=true` (Pitfall M equivalent for sync reports — opt-in by default).
- **SyncReportCsvGenerator** — writes exactly 11 D-10 columns in order via `SimpleExcelWriter::create($path)->noHeaderRow()`, chunked 500 rows for constant memory; **Pitfall P2-A mitigated** by explicit `unset($writer)` before return.
- **SupplierSyncReportMail** — envelope subject distinguishes `[ABORTED] ... {abort_reason}` from `Supplier sync {id} — {updated_count} updated`; attachment uses `Attachment::fromPath(...)->withMime('text/csv')`.
- **Blade email view** — plain HTML body with the 6 aggregate counts + correlation_id + resume hint pointing to `/admin/sync-runs/{id}`.
- **SyncSupplierCommand** — private `emailReport(SyncRun, bool $aborted)` hooked into finalise (completed path), JwtRefreshFailedException catch (JWT abort path), and SyncAbortException catch (consecutive_failures / error_rate / manual paths). Recipients filtered via `active()->receivesSyncReports()`; warn-and-skip when empty; wrapped in try/catch so CSV/mail failures don't mask sync results.

### Filament Resources (Task 2a, SYNC-11/12 + D-01)

- **SyncRunResource (SYNC-11)**:
  - Table columns: `id`, `started_at`, `duration` (computed from started/completed), `status` (badge), `dry_run` (icon), `updated_count`, `failed_count`, `errors_count`, `items_count`, `abort_reason`, `correlation_id` (copyable, mono font, truncated).
  - Filters: `SelectFilter::multiple` on status + `TernaryFilter` on dry_run.
  - `getEloquentQuery()->withCount(['errors', 'items'])->latest('started_at')` — **Pitfall P2-G N+1 prevention** + newest-first sort.
  - Admin-only "Retry aborted run" header action — `->visible(...)` AND `->authorize(...)` (Pitfall K belt-and-braces), disabled stub pending Phase 7 cutover wiring.
  - 2 RelationManagers: SyncErrorsRelationManager (read-only per-SKU error log) + SyncRunItemsRelationManager (11 D-10 columns + action filter).
  - ViewSyncRun page: Infolist with 4 Sections (Run Details + Counts + Abort Details conditional + Cursor).
- **ImportIssueResource (SYNC-12)**:
  - Table columns: sku (searchable), issue_type (colored badge), woo_product_id, detected_at, last_seen_at, resolved_at (placeholder "Unresolved"), notes (truncated), correlation_id.
  - Filters: 4-option SelectFilter on issue_type + TernaryFilter "resolved" (whereNotNull/whereNull).
  - Edit form: sku + issue_type disabled unless admin; notes free-edit for pricing_manager.
  - BulkAction "Mark resolved": `->visible(...)` AND `->authorize(...)` on `hasAnyRole(['admin', 'pricing_manager'])` — Warning 9 defence-in-depth.
- **ProductResource (D-01)**:
  - Table columns: woo_product_id, sku, name, type (colored badge), status, stock_status, buy_price (GBP money), sell_price (GBP money), is_custom_ms (icon), exclude_from_auto_update (icon), last_synced_at.
  - Filters: type, status, is_custom_ms, exclude_from_auto_update.
  - Edit form: identity fields (woo_product_id, sku, name, type) disabled — Woo canonical; status + stock_status + buy/sell/cost prices editable (pricing_manager + admin); exclude_from_auto_update editable.
  - canCreate() returns false — Products are synced from Woo, no UI creation in Phase 2.
- **VariantsRelationManager**:
  - `canViewForRecord()` gates on `ownerRecord->type === 'variable'` — D-01 heuristic keeps the tab hidden for simple products.
  - Columns: sku (searchable), woo_variation_id, buy_price, sell_price, stock_quantity, status, last_synced_at.
- **AdminPanelProvider**: `discoverResources(in: app_path('Domain/Sync/Filament/Resources'), ...)` + same for `Domain/Products`. Filament 4-domain discovery path now: Suggestions + Alerting (Phase 1) + Sync + Products (Phase 2).
- **RolePermissionSeeder**: unconditional additions of `%_product_variant`, `%product::variant`, `%_import_issue`, `%import::issue` (both separator styles).

### Shield regeneration + policy restoration (Task 2b, Pitfall P2-H)

- **Pre-flight**: clean working tree verified; SHA256 hashes of 7 hand-written policies captured to `.tmp/policy-hashes-pre.txt`.
- **Ran** `php artisan shield:generate --all --panel=admin --no-interaction` — generated 60 new permissions covering Sync + Products Resources + existing Role/Suggestion/AlertRecipient.
- **Damage detection**: `grep -r '{{ ' app/Policies/ app/Domain/*/Policies/` returned 6 matches in `RolePolicy.php` (`{{ ForceDelete }}`, `{{ ForceDeleteAny }}`, `{{ Restore }}`, `{{ RestoreAny }}`, `{{ Replicate }}`, `{{ Reorder }}` — same bug Phase 1 Plan 02 fixed, Phase 1 Plan 04 re-fixed). `git status` showed 6 policies modified (RolePolicy + Suggestion + AlertRecipient + Product + SyncRun + ImportIssue).
- **Restoration**: `git checkout HEAD -- <6 paths>` — clean-restored all 6 policies byte-identical to pre-shield. ProductVariantPolicy untouched (no ProductVariantResource — variants are a RelationManager only).
- **Permission-name discovery**: Shield 3.9.10 generates TWO styles in this codebase:
  - Single-word PascalCase (Product, Role, Suggestion): `view_product`, `create_role`, `update_suggestion` (underscore-separated).
  - Multi-word PascalCase (SyncRun, ImportIssue, AlertRecipient): `view_sync::run`, `update_import::issue`, `view_any_alert::recipient` (`::` separator between words).
- **Seeder LIKE-pattern split**: added `%product::variant`, `%import::issue`, `view_sync::run`, `view_any_sync::run` alongside the Phase 1 underscore patterns. Seeder is now separator-agnostic — any future Shield config flip won't silently drop pricing_manager perms.
- **Seeder re-run**: `php artisan db:seed --class=RolePermissionSeeder --force` twice produces identical output `admin=66 perms, pricing_manager=26, sales=0, read_only=12` (idempotent).
- **PolicyTemplateIntegrityTest**: Feature-level guardrail — 1 test grepping every `app/Policies/*.php` + `app/Domain/*/Policies/*.php` for `{{ ` literal. Zero matches required. Catches future shield:generate regressions at CI.
- **Full Pest suite**: 223 passed + 2 skipped (was 192 baseline + 31 net). Deptrac 0 violations.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Phase02DataModelTest rollback step=6 → step=7**

- **Found during:** Task 1 full-suite run.
- **Issue:** Plan 02-01's migrate:rollback test asserts `--step=6` rolls back all 6 Phase-2 table creates. Plan 02-04's additive `200600_add_receives_sync_reports_to_alert_recipients` migration shares the same batch (ran in the same RefreshDatabase pass), so step=6 only rolls back 5 of the 6 Phase-2 tables, leaving `products` intact and failing the `Schema::hasTable('products')->toBeFalse()` assertion.
- **Fix:** Bumped `--step` to 7. Inline comment documents the rationale so future migrations in the same batch know to increment further.
- **Files modified:** `tests/Feature/Phase02DataModelTest.php`
- **Committed in:** `bd5f82c` (Task 1)
- **Verification:** Test passes on 3 consecutive runs.

**2. [Rule 3 — Blocking] Deptrac Sync → Alerting not yet allowed**

- **Found during:** Task 1 full-suite run (DeptracTest failed with 2 violations referencing `SyncSupplierCommand.php:8` + `:201`).
- **Issue:** SyncSupplierCommand now imports `App\Domain\Alerting\Models\AlertRecipient` (line 8) and queries it via `AlertRecipient::query()->active()->receivesSyncReports()->get()` (line 201 → emailReport method). Deptrac ruleset `Sync: [Foundation, Products]` does not permit this cross-domain call.
- **Fix:** Updated both `depfile.yaml` and `deptrac.yaml` to `Sync: [Foundation, Products, Alerting]`. Justification: SyncSupplierCommand legitimately orchestrates D-08 report distribution; reading AlertRecipient is the central use case, not an architecture smell. Matches Phase 1 D-12's expectation that Sync fires failed-job alerts through the Alerting domain.
- **Files modified:** `depfile.yaml`, `deptrac.yaml`
- **Committed in:** `bd5f82c` (Task 1)
- **Verification:** `vendor/bin/deptrac analyse --no-progress` — 0 violations post-fix.

**3. [Rule 3 — Blocking] Shield 3.9.10's regenerated RolePolicy leaked `{{ Placeholder }}` literals AGAIN**

- **Found during:** Task 2b post-shield:generate grep.
- **Issue:** Same Shield 3.9.10 stub bug Plan 02 + Plan 04 already fixed — the RolePolicy generator leaves 6 unrendered template literals (`{{ ForceDelete }}`, `{{ ForceDeleteAny }}`, `{{ Restore }}`, `{{ RestoreAny }}`, `{{ Replicate }}`, `{{ Reorder }}`). Task 2b's shield:generate re-introduced them.
- **Fix:** `git checkout HEAD -- app/Policies/RolePolicy.php` — byte-identical restore of the version committed in Phase 1 Plan 02/04 (which had the literals correctly replaced with real permission names). Added `tests/Feature/PolicyTemplateIntegrityTest.php` as an ongoing guardrail so the next plan's shield:generate cascade will fail CI fast.
- **Files modified:** `app/Policies/RolePolicy.php` (restore only), `tests/Feature/PolicyTemplateIntegrityTest.php` (new test file)
- **Committed in:** `5db84b5` (Task 2b)
- **Verification:** `grep -r '{{ ' app/Policies/ app/Domain/*/Policies/` → zero matches. PolicyTemplateIntegrityTest passes.

**4. [Rule 2 — Missing Critical] Shield generates `::`-separator permissions for multi-word Resources**

- **Found during:** Task 2b — after shield:generate + seeder re-run, pricing_manager had 0 sync_run permissions despite the seeder having `view_sync_run` in its whereIn list.
- **Issue:** Plan text + Phase 1 01-02-SUMMARY both asserted Shield emits underscore-separated names (e.g., `view_sync_run`). Observed reality in this codebase (Shield 3.9.10 on Filament 3.3): multi-word Resource class names (SyncRun, ImportIssue, AlertRecipient) produce `view_sync::run`, `view_import::issue`, `view_alert::recipient` (`::` between PascalCase words). Single-word classes (Product, Role, Suggestion) still use underscore style.
  - LIKE `%_sync_run` will NOT match `view_sync::run` (`_` is single-char wildcard, but `::` isn't a single char).
  - Plan 02-04 Task 2b's seeder additions were all underscore-style, so they didn't catch these new perms.
- **Fix:** Added parallel `%product::variant`, `%import::issue`, `view_sync::run`, `view_any_sync::run` entries to the seeder alongside the underscore variants. Docblock rewritten to describe both styles. pricing_manager's post-seed permission count went from 12 (product only) to 26 (product + import_issue + view-only sync_run).
- **Files modified:** `database/seeders/RolePermissionSeeder.php`
- **Committed in:** `5db84b5` (Task 2b)
- **Verification:** `php artisan tinker` — `User::factory()->create()->assignRole('pricing_manager')` now has `can('view_sync::run') === true` AND `can('update_import::issue') === true`. Seeder is idempotent across runs.

**5. [Rule 3 — Blocking] Test mocking pattern — SupplierClient is `final`**

- **Found during:** Task 1 first SyncReportMailTest run — 3 of 6 tests failed with "The class SupplierClient is marked final and its methods cannot be replaced."
- **Issue:** Initial tests used `$this->mock(SupplierClient::class, ...)` which Mockery rejects for final classes. Also had the `$this->mock(WooProductIterator::class)` case same reason.
- **Fix:** Rewrote tests to use the `app()->instance()` + anonymous-class stub pattern matching DryRunModeTest (Plan 02-03). Stubs have `fetchAllProducts(): array` / `pages(int $fromPage = 1): \Generator` methods matching the real classes' signatures; Laravel's container substitutes the stub. No composer changes, no interface extraction — tests adapt to existing `final` conventions.
- **Files modified:** `tests/Feature/SyncReportMailTest.php`
- **Committed in:** `bd5f82c` (Task 1)
- **Verification:** All 6 SyncReportMailTest cases pass.

**6. [Rule 1 — Bug] CSV header test failed on UTF-8 BOM**

- **Found during:** Task 1 SyncReportCsvGeneratorTest first run.
- **Issue:** spatie/simple-excel prepends a UTF-8 BOM (`\xEF\xBB\xBF`) to the output file for Excel compatibility. The test's `expect($header)->toBe(['sku', 'woo_product_id', ...])` failed because the first column read as `\xEF\xBB\xBFsku`.
- **Fix:** Test strips the BOM from the first header field before asserting. Left the BOM in production output (it's correct for downstream Excel users).
- **Files modified:** `tests/Feature/SyncReportCsvGeneratorTest.php`
- **Committed in:** `bd5f82c` (Task 1)

**7. [Rule 3 — Blocking] Mail::assertSent callback signature changed**

- **Found during:** Task 1 SyncReportMailTest run.
- **Issue:** Initial test used `Mail::assertSent(Class, function ($mail, $addresses) { ... })` (2-arg form common in older Laravel docs). Laravel 12's MailFake::sent() only passes `$mail`, not `$addresses`. Caused `ArgumentCountError`.
- **Fix:** Switched to single-arg callback + inspect `$mail->to` array (each entry: `['address' => ..., 'name' => ...]`).
- **Files modified:** `tests/Feature/SyncReportMailTest.php`
- **Committed in:** `bd5f82c` (Task 1)

**8. [Rule 3 — Blocking] Filament multi-select filter syntax**

- **Found during:** Task 2a resource tests.
- **Issue:** Initial test used `->filterTable('status', ['values' => [SyncRun::STATUS_ABORTED]])` — blade view crashed with `array_flip(): Can only flip string and integer values, entry skipped`.
- **Fix:** Switched to positional-array `->filterTable('status', [SyncRun::STATUS_ABORTED])`. Filament 3.3's Livewire contract for SelectFilter(multiple) wants positional, not keyed.
- **Files modified:** `tests/Feature/SyncRunResourceTest.php`, `tests/Feature/ImportIssueResourceTest.php`
- **Committed in:** `0da1ab2` (Task 2a)

---

**Total deviations:** 8 auto-fixed (2 bugs, 3 blockers, 2 missing-critical + 1 bug covering CSV BOM). No Rule 4 architectural asks. All 3 SYNC requirements (SYNC-08, SYNC-11, SYNC-12) backed by passing tests + working Filament UI.

## Authentication Gates

None encountered. The plan is pure infrastructure + UI — no external credentials required during execution. Tests use `Mail::fake()` + anonymous-class supplier/iterator stubs throughout.

For Phase 7 cutover runbook: operators must populate `SUPPLIER_API_USERNAME`/`PASSWORD` + `WOO_URL`/`CONSUMER_KEY`/`CONSUMER_SECRET` in `.env` (same set Phase 02-02 documented). No new env vars in this plan.

## Issues Encountered

1. **Shield separator style inconsistency between single-word and multi-word Resources** — not a blocker but worth the Rule 2 fix above. A future hardening PR could either (a) configure Shield to use a single consistent separator, or (b) add a Feature test listing all expected permissions per role so drift is caught without running the whole suite.

2. **Mockery's rejection of `final` classes** — a pattern that's likely to recur in Phase 3+ tests (pricing engine, competitor CSV). Consider documenting the anonymous-class stub pattern as a testing-convention note in a future plan, or introducing a `Supplier/Woo/Pricing*Contract` interface set if/when external swapping becomes a real requirement.

3. **Plan 02-04's written LIKE patterns were all underscore-style** — the `::` discovery happened only after Task 2b's shield:generate. Plan 02-05 (the guardrail plan) should include a `SeederPermissionCoverageTest` that asserts each role has at least N expected permissions matching each Resource's expected prefixes — catches "seeder LIKE patterns don't match Shield output" drift earlier.

## User Setup Required

None for Plan 02-04 execution. For production ops:

- Visit `/admin/alert-recipients` post-deploy, review recipients, toggle `receives_sync_reports` off for any who shouldn't receive the CSV.
- Daily sync cron currently disabled (commented in `routes/console.php`); Phase 7 runbook enables.

## Next Plan Readiness

### Plan 02-05 (guardrails + retention) can assume

- `PolicyTemplateIntegrityTest` already exists as a Feature test (1 file, grep for `{{ ` literal). Plan 02-05 can either: (a) leave it where it is (Feature suite), or (b) migrate to `tests/Architecture/PolicyTemplateIntegrityTest.php` for symmetry with DeptracTest + future SeederPermissionCoverageTest.
- `sync_errors` + `sync_run_items` inherit FK cascadeOnDelete from `sync_runs`; a `sync-errors:prune` command deleting `sync_runs` older than N days automatically sweeps both child tables (Phase 2-01 contract).
- Shield regeneration damage points are known: RolePolicy (always re-leaks `{{ Placeholder }}`), SuggestionPolicy + AlertRecipientPolicy + ProductPolicy + SyncRunPolicy + ImportIssuePolicy (all get permission-based stubs; need restoration). ProductVariantPolicy is safe (no Resource to discover).
- Seeder LIKE patterns now cover `_` + `::` styles. Any Plan 02-05 extensions (e.g., adding a Resource with another separator style) should follow the same dual-pattern convention.
- Deptrac `Sync → Alerting` is now legitimate — Plan 02-05's architecture ruleset does NOT need to revert it.

### Phase 3+ (pricing engine) can assume

- pricing_manager has 26 Shield-granted permissions (product 12 + import_issue 12 + view_sync::run 2). Phase 3's PricingRule Resource will auto-include via `%_pricing_rule` LIKE (or the `::` variant Shield chooses).
- Product editing in Filament is functional for pricing_manager — price fields (buy_price, sell_price, cost_price) are editable; identity fields disabled.
- `SupplierPriceChanged` domain event wired since Plan 02-03 — Phase 3 MarginRecomputeListener subscribes without touching sync code.

### Phase 6 (auto-create product) can assume

- ImportIssueResource's `TYPE_UNKNOWN_SKU` filter surfaces supplier-only SKUs awaiting auto-create decision.
- NewSupplierSkuDetected event (Plan 02-03) fires per unknown SKU; stub listener registered via EventServiceProvider — Phase 6 replaces the stub with CreateWooProductJob.

### Known concerns for later plans

1. **Shield permission separator drift** — if a future Shield upgrade or config change flips all perms to a single style, the dual-pattern seeder will still catch them. But any code that hardcodes a specific name (e.g., `@can('view_sync_run')` vs `@can('view_sync::run')`) will break. Search the codebase for `->can(` or `$user->can(` ahead of any Shield upgrade.

2. **VariantsRelationManager's canViewForRecord is a runtime per-row check** — fine for typical ops drill-downs (~1 row view at a time), but would be inefficient if Phase 3+ builds a bulk-variants dashboard listing many products. Consider a custom Filament Page for that use case instead of repurposing the RelationManager.

3. **SyncSupplierCommand emailReport is synchronous** — the Mailable uses `Queueable` but the command calls `Mail::to(...)->send(...)` which sends immediately. For 15k-SKU reports with large CSV attachments, this could add 2-5s to the command's exit. If runtime-impact testing shows an issue, switch to `->queue(...)` and ensure the `default` Horizon supervisor has capacity — Plan 02-05 could profile this.

4. **Mailable attaches file from storage path — not inline** — attachments resolve at Mail send-time. If the CSV is deleted between generate + send (e.g., retention prune), the attachment silently fails. Mitigation: retention prune should NOT delete sync-reports/ CSVs for runs whose email hasn't been dispatched. Track as a Plan 02-05 retention concern.

## Threat Flags

No new trust boundaries introduced beyond the plan's documented threat model:

- **T-02-04-01** (CSV info disclosure) — mitigated: stored at `storage/app/private/`, never web-served. Plan 02-05 will add retention prune.
- **T-02-04-02** (email distribution tampering) — mitigated: D-08 opt-in toggle + AlertRecipientResource admin-only CRUD.
- **T-02-04-03** (receives_sync_reports toggle abuse) — spatie/activitylog on AlertRecipient not yet wired (Phase 1 LogsActivity trait not added to model); future hardening opportunity.
- **T-02-04-04** (markResolved bulk action privilege) — mitigated: `->authorize(... hasAnyRole(['admin', 'pricing_manager']))` + `->visible(...)` same closure. Warning 9 defence-in-depth verified in test.
- **T-02-04-05** (shield:generate damages hand-written policies) — mitigated: documented restore protocol executed; PolicyTemplateIntegrityTest guards against regression.
- **T-02-04-06** (VariantsRelationManager shows prices to read_only) — accept per D-02: read_only sees reports; prices are on the Woo storefront anyway.
- **T-02-04-07** (CSV memory blowup for 15k SKU runs) — mitigated: chunk(500) test asserts <30MB peak growth for 1500 rows.
- **T-02-04-08** (attachment path manipulation) — mitigated: integer run->id only, never user input.

## Self-Check: PASSED

- Created files verified (24 files):
  - 6 production (migration + CSV generator + Mailable + blade view + 5 Filament Resources + 5 Pages + 3 RelationManagers = correctly counted as ~17 new production files) ALL FOUND
  - 7 test files FOUND (Receives 5, CSV 5, Mail 6, SyncRunResource 6, ImportIssue 4, Product 4, PolicyIntegrity 1)
- Modified files verified:
  - `app/Domain/Alerting/Models/AlertRecipient.php` has `receives_sync_reports` in $fillable + $casts + scope methods FOUND
  - `app/Domain/Alerting/Filament/Resources/AlertRecipientResource.php` has Toggle + IconColumn FOUND
  - `database/seeders/AlertRecipientSeeder.php` sets `receives_sync_reports=true` on seeded row FOUND
  - `app/Domain/Sync/Commands/SyncSupplierCommand.php` has `emailReport()` method + hooks at finalise + JWT-abort + SyncAbort paths FOUND
  - `app/Providers/Filament/AdminPanelProvider.php` has 4 discoverResources calls (Suggestions, Alerting, Sync, Products) FOUND
  - `database/seeders/RolePermissionSeeder.php` has both `%_foo` and `%foo::bar` patterns FOUND
  - `depfile.yaml` + `deptrac.yaml` have `Sync: [Foundation, Products, Alerting]` FOUND (grep-verified)
  - `tests/Feature/Phase02DataModelTest.php` has `--step => 7` FOUND
  - `.gitignore` has `/.tmp` FOUND
- Commits verified via `git log --oneline -5`:
  - `bd5f82c` Task 1 FOUND
  - `0da1ab2` Task 2a FOUND
  - `5db84b5` Task 2b FOUND
- Test results:
  - `vendor/bin/pest --filter="ReceivesSyncReports|SyncReportCsvGenerator|SyncReportMail"` — 16 pass
  - `vendor/bin/pest --filter="SyncRunResource|ImportIssueResource|ProductResource"` — 14 pass
  - `vendor/bin/pest --filter="PolicyTemplateIntegrity"` — 1 pass
  - **Full suite: 223 passed, 2 skipped** (same pre-existing Phase 1 designed skips; net +31 from baseline)
- `vendor/bin/deptrac analyse --no-progress` — 0 violations, 33 allowed, 688 uncovered (framework)
- `php artisan route:list | grep -E "sync-runs|import-issues|products"` — all three resource routes registered
- `php artisan db:seed --class=RolePermissionSeeder --force` — runs idempotently; `admin=66, pricing_manager=26, sales=0, read_only=12`
- `grep -r '{{ ' app/Policies/ app/Domain/*/Policies/` — zero matches (policy integrity clean post-shield:generate)
- `php artisan migrate --force` on both dev + testing DBs: `receives_sync_reports` column present on alert_recipients

---

*Phase: 02-supplier-sync*
*Plan: 04-reporting-ui*
*Completed: 2026-04-18*
