---
phase: 260710-j2m-fix-18-deterministic-test-rot-from-full-
plan: 01
subsystem: testing + cutover
tags: [test-rot, sqlite-mysql, constructor-drift, prod-crash-fix]
requires: []
provides:
  - "OverridePopulator sets margin_basis_points=0 on pin-only overrides (no NOT-NULL crash on `cutover:populate-overrides --live`)"
  - "15 deterministic test-rot files green (constructor drift, FQN drift, driver-agnostic index checks, drifted counts, API-shape fixes)"
affects:
  - app/Domain/Cutover/Services/OverridePopulator.php
tech-stack:
  added: []
  patterns:
    - "Driver-agnostic index assertions via \\Schema::getIndexes() instead of MySQL-only SHOW INDEX"
    - "MySQL-only rollback/schema tests guarded with getDriverName()!=='mysql' skip"
key-files:
  created:
    - .planning/quick/260710-j2m-fix-18-deterministic-test-rot-from-full-/260710-j2m-SUMMARY.md
  modified:
    - app/Domain/Cutover/Services/OverridePopulator.php
    - tests/Feature/CRM/BitrixClientExceptionClassificationTest.php
    - tests/Feature/CRM/BitrixClientShadowModeTest.php
    - tests/Feature/CRM/SyncDiffsProviderColumnTest.php
    - tests/Feature/Competitor/NewProductOpportunityApproveActionTest.php
    - tests/Feature/Console/BackfillMerchantFeedCommandTest.php
    - tests/Feature/Cutover/PopulateOverridesCommandTest.php
    - tests/Feature/Dashboard/DashboardRefreshCommandTest.php
    - tests/Feature/Dashboard/GlobalSearchTest.php
    - tests/Feature/Dashboard/NotificationCentrePageTest.php
    - tests/Feature/Dashboard/QueuedCsvExportJobTest.php
    - tests/Feature/Dashboard/WidgetDataSourceTest.php
    - tests/Feature/Domain/Quotes/PushQuoteToBitrixDealJobTest.php
    - tests/Feature/DryRunModeTest.php
    - tests/Feature/ExampleTest.php
    - tests/Feature/FailedJobAlertTest.php
    - tests/Feature/HorizonSupervisorTest.php
    - tests/Feature/IntegrationLoggerTest.php
    - tests/Feature/Phase02DataModelTest.php
    - tests/Feature/ShadowModeTest.php
decisions:
  - "PushQuoteToBitrixDealJobTest: fixed the documented L269 (line-shape) + the shadow-mode test via app(EntityDeduper); STOPPED on the 3 live-path tests (first-push / second-push / quote_push_failed) — they are NOT simple test-rot and need an architectural decision. Reported, not forced."
  - "OverridePopulator real fix ships pins-only margin_basis_points=0; no migration."
metrics:
  duration: ~55m
  completed: 2026-07-10
---

# Phase 260710-j2m Plan 01: Fix 18 Deterministic Test-Rot + OverridePopulator Crash Summary

Greened 18 of the plan's 19 test files (all deterministic, order-independent test-rot) and fixed the one genuine
prod-crash bug (`OverridePopulator` omitting a NOT-NULL `margin_basis_points`). The 19th file
(`PushQuoteToBitrixDealJobTest`) had its two documented/simple-test-rot cases greened, but 3 live-path tests were
found to be broken beyond simple test-rot and are reported (not forced) — see "STOPPED / Still Owed".

## Verification

- **Combined run of the 19 files:** `Tests: 3 failed, 1 skipped, 138 passed (559 assertions)`.
  - The `1 skipped` is the intentional MySQL-only guard added to `Phase02DataModelTest`'s rollback test.
  - The `3 failed` are all in `PushQuoteToBitrixDealJobTest` (live-path; see below). Every other file is fully GREEN.
- **`pint app/Domain/Cutover/Services/OverridePopulator.php`** → `{"result":"pass"}`.
- Driver-portable: all index/rollback checks now work on both sqlite (tests) and MySQL (prod).

## The REAL Fix (#13 / #19) — OverridePopulator NOT-NULL crash

`app/Domain/Cutover/Services/OverridePopulator.php` (pin-only create, ~L118-126) created `ProductOverride` rows
WITHOUT `margin_basis_points`. That column is `$t->integer('margin_basis_points')` with **no default** and NOT NULL
(`create_product_overrides` migration), so on MySQL strict `cutover:populate-overrides --live` **crashes** the moment
it creates any pin-only override.

- **Before:** `array_merge(['product_id' => $productId], array_fill_keys(...pins..., true))` — no margin key.
- **After:** `array_merge(['product_id' => $productId, 'margin_basis_points' => 0], ...)` — pins-only convention
  (0 = "no margin change, pins only", matching FieldPinManager). Stale "nullable by design" comment corrected.
- Greens 3 of `PopulateOverridesCommandTest`'s create-path cases; the 2 merge-path cases needed the same margin key
  added to their **test arrange** `ProductOverride::create([...])` calls (the populator's merge branch never creates).

## The 18 Test-Rot Fixes (grouped by cause)

### A. Constructor-signature drift (5 files)
- **BitrixClientExceptionClassificationTest** — anon `extends BitrixClient` now passes `IntegrationCredentialResolver`
  as ctor arg 2 and threads it to `parent::__construct($logger, $resolver)`.
- **BitrixClientShadowModeTest** — anon subclass instantiation passes the resolver as arg 2.
- **ShadowModeTest** — `new WooClient(logger, mockInner)` → 3-arg `(logger, resolver, mockInner)`.
- **BackfillMerchantFeedCommandTest** — both `new class(...) extends BackfillMerchantFeedCommand` stubs (2 of them)
  gained `app(WooGtinPublisher::class)` after `AdCandidateScanner`, ctor param `WooGtinPublisher $gtinPublisher`, and
  `parent::__construct(..., $gtinPublisher)`.

### B. MySQL-only `SHOW INDEX` → driver-agnostic (2 files)
- **SyncDiffsProviderColumnTest** — `DB::select('SHOW INDEX FROM sync_diffs …')` → `collect(\Schema::getIndexes('sync_diffs'))->filter(columns contain 'provider')`.
- **IntegrationLoggerTest** — `SHOW INDEX FROM integration_events` → `Schema::getIndexes('integration_events')->pluck('name')`.

### C. Wrong class FQNs (2 files)
- **NewProductOpportunityApproveActionTest** — `App\Domain\Competitor\Appliers\…` → `App\Domain\ProductAutoCreate\Appliers\NewProductOpportunityApplier`. (Also updated stale assertions — see D.)
- **QueuedCsvExportJobTest** — all 3 `App\Domain\Dashboard\Services\CsvExportWriter` → `App\Filament\Exports\CsvExportWriter`.

### D. Drifted counts / stale API-shape (5 files)
- **DashboardRefreshCommandTest** — 11 → 15 rows + the 15 sorted metric keys (added ad_candidates_health,
  category_audit_health, stock_divergence, supplier_freshness — **confirmed against `SnapshotAggregator::computeAll()`**).
- **WidgetDataSourceTest** — `refreshAll()` count 11 → 15.
- **HorizonSupervisorTest** — 7 → 8 supervisors (+ `agents-supervisor`) and the `agents` queue added to the expected
  set — **confirmed against `config/horizon.php` production block**.
- **NewProductOpportunityApproveActionTest** — the applier is now Phase-6 live (dispatches `CreateWooProductJob`,
  returns `phase_6_live`), not the old `phase_5_stub`. Updated assertions to the live shape + `Queue::fake()` so the
  real dispatch doesn't run synchronously and trip failed-job-monitor.
- **GlobalSearchTest** — `toHaveKey(Class, msg)` 2nd-arg misuse dropped.

### E. Test-double / API misuse (4 files)
- **NotificationCentrePageTest** — `new \Livewire\Component()` (abstract) → `new class extends \Livewire\Component implements \Filament\Forms\Contracts\HasForms { use InteractsWithForms; }` (a bare Component doesn't satisfy `Form::make(HasForms)`).
- **DryRunModeTest** — SupplierClient stub `new class($feed)` → `new class($feed) extends SupplierClient`.
- **FailedJobAlertTest** — config assert `->toBeNull()` → `->toBe(NullNotifiable::class)` (config now returns the no-op sink class).
- **QueuedCsvExportJobTest** (`$queue` guard) — the reflection test wrongly assumed trait properties report the trait
  as declaring class; PHP 8.4 flattens them into the using class. Rewrote to assert the inherited `Queueable::$queue`
  is UNTYPED (a colliding re-declaration would be typed `string`).

### F. sqlite-only divergence guard (1 file)
- **Phase02DataModelTest** — the drop-indexed-column rollback round-trip now `markTestSkipped` unless the driver is
  MySQL (sqlite cannot drop an indexed column on rollback; prod is MySQL).

## STOPPED / Still Owed

### PushQuoteToBitrixDealJobTest — 3 live-path tests (NOT simple test-rot — reported, not forced)
`first push`, `second push`, `emits quote_push_failed` remain RED. They were **already red at HEAD** (verified: the
full file is 5-failed at HEAD; my fixes greened the line-shape + shadow-mode tests). They are broken for TWO reasons,
both beyond mechanical test-rot:
1. **`Mockery::mock(EntityDeduper::class)` on a `final` class** — EntityDeduper has been `final` since 04-02
   (predates these Phase-11 tests). These tests `->shouldReceive('findOrCreateContact')->andReturn(...)`, so they
   cannot use the plan's `app(EntityDeduper)` trick (which only works when the deduper isn't stubbed, as in the
   shadow/line-shape tests). Fixing them needs EntityDeduper made testable (un-`final` or extract an interface) — an
   **architectural (Rule 4) decision**, not test-rot.
2. **sqlite CHECK-constraint on `entity_type='quote_deal'`** — the Plan-11 migration only extends the
   `bitrix_entity_map.entity_type` ENUM to include `quote_deal` on **MySQL**; on sqlite the original
   `enum('deal','contact','company')` CHECK survives and rejects `quote_deal`. (Prod is MySQL and works; this is a
   sqlite/MySQL migration divergence.) Its migration comment ("SQLite … any value is already accepted") is incorrect.

Recommendation: treat like the other decision-pending items — either un-`final` EntityDeduper / extract an interface,
or convert these to true MySQL-integration tests with a real driver guard, and make the Plan-11 migration rebuild the
sqlite CHECK for `quote_deal`.

### Deliberately out of scope (untouched, as instructed)
- 8 test-ISOLATION-fragility files (tests/Unit/Domain/Quotes/*, tests/Unit/Pricing/GoldenFixtureV2TradeTest,
  tests/Unit/TradePricing/Services/TradeRuleResolverPurityTest, tests/Feature/Dashboard/HomeDashboardPageTest,
  tests/Feature/Domain/Quotes/ImportQuoteActionTest).
- #11 IntegrationEventPolicy (registered in AppServiceProvider; delete-vs-keep decision).
- #22 quote-PDF snapshot (needs a no-mutation control to confirm money-bug vs PDF non-determinism).

## Deviations from Plan

- **[Rule 1 - Bug] Real prod fix** — OverridePopulator margin_basis_points=0 (documented above; the plan's #19).
- **Plan under-specified 3 files** (assertion/setup drift the classifier's single-line fix didn't cover; all still
  simple test-rot, fixed as the failing test dictated):
  - NewProductOpportunityApproveActionTest — assertions were stale Phase-5 stub shape, not just the FQN.
  - QueuedCsvExportJobTest — a 2nd `$queue`-reflection test was broken on PHP 8.4 (trait-property flattening).
  - PopulateOverridesCommandTest — 2 merge-path tests needed `margin_basis_points=0` in their arrange step.
  - ShadowModeTest — 2 tests asserted the pre-260530-clv PUT verb; pinned `use_post_for_updates=false` in beforeEach.
  - BackfillMerchantFeedCommandTest — the WooGtinPublisher ctor fix applied to BOTH command stubs, not just one.
- **[STOP & report]** PushQuoteToBitrixDealJobTest's 3 live-path tests — see "STOPPED / Still Owed".

## Deploy notes (operator)
- Push main → `sudo -u stcav /home/stcav/ms.21stcav.com/deploy/deploy.sh` — **NO migration**.
- 17 pure test-rot fixes (no prod impact). ONE real fix: OverridePopulator now sets `margin_basis_points=0` on
  pin-only overrides — `cutover:populate-overrides --live` would otherwise crash (NOT NULL).
