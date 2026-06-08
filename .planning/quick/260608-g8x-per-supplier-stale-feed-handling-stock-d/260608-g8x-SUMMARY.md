---
quick_id: 260608-g8x
type: quick
status: complete
description: Per-supplier stale-feed handling — stock-decay policy uniformly applied across three downstream consumers (AdCandidateScanner, CompetitorPositionScanner supplier resolver, SupplierDbSyncCommand buy-price resolver). Mirrors the existing competitor-staleness shape but at the supplier-offer level.
tags: [suppliers, sync, pricing, ads, dashboard, stale-feed, drift-prevention]
commits:
  - a9115bd # Task 1
  - ebd1a00 # Task 2
  - 91e2088 # Task 3
  - 2c84923 # Task 4
  - d4e4caf # Task 5
  - 5e42fb6 # Task 6
  - bf8f85a # Task 7
  - 23a2a37 # Task 8
files_created:
  - database/migrations/2026_06_08_120000_create_suppliers_table.php
  - database/migrations/2026_06_08_120100_create_supplier_freshness_snapshots_table.php
  - config/supplier.php
  - app/Domain/Sync/Models/Supplier.php
  - app/Domain/Sync/Models/SupplierFreshnessSnapshot.php
  - app/Domain/Sync/Services/SupplierFreshnessResolver.php
  - app/Domain/Sync/Console/Commands/CheckStaleSuppliersCommand.php
  - app/Filament/Widgets/SupplierFreshnessWidget.php
  - tests/Unit/Domain/Sync/Services/SupplierFreshnessResolverTest.php
  - tests/Feature/Console/CheckStaleSuppliersCommandTest.php
  - tests/Feature/Domain/Pricing/AdCandidateScannerStaleSupplierTest.php
  - tests/Feature/Domain/Pricing/CompetitorPositionScannerStaleSupplierTest.php
  - tests/Feature/Domain/Sync/SupplierDbSyncStaleSupplierTest.php
  - tests/Feature/Domain/Products/SupplierOfferSnapshotScopeTest.php
files_modified:
  - app/Domain/Products/Models/SupplierOfferSnapshot.php # +scopeFreshOnly
  - app/Domain/Pricing/Services/AdCandidateScanner.php # constructor + fresh-id filter
  - app/Domain/Pricing/Services/CompetitorPositionScanner.php # constructor + stale-id NOT IN filter
  - app/Domain/Sync/Commands/SupplierDbSyncCommand.php # constructor + buildBestOfferMap row pre-filter
  - app/Domain/Dashboard/Services/SnapshotAggregator.php # +computeSupplierFreshness
  - app/Domain/Dashboard/Services/NotificationCentreAggregator.php # +staleSuppliers
  - app/Filament/Pages/HomeDashboardPage.php # widget registered in Row 1 + section
  - app/Providers/Filament/AdminPanelProvider.php # widget registered in panel
  - app/Providers/AppServiceProvider.php # SupplierFreshnessResolver singleton + command registration
  - routes/console.php # +45 7 * * 1-5 cron (Mon-Fri 07:45 London)
  - tests/Feature/Sync/SupplierDbSyncCommandTest.php # factory updated for new ctor arg
  - tests/Feature/Dashboard/HomeDashboardPageTest.php # widget count 14 → 15 + new entry in expected list
---

# Quick 260608-g8x: Per-supplier stale-feed handling Summary

**One-liner:** Central `SupplierFreshnessResolver` classifies every supplier as fresh/amber/stale/unknown from `supplier_offer_snapshots.recorded_at`; three downstream consumers (AdCandidateScanner stock predicate, CompetitorPositionScanner popup supplier-name resolver, SupplierDbSyncCommand buy-price selector) opt in via constructor flag defaulting TRUE so a single-file policy edit propagates across all wirings — stops Nuvias-shape silent suppliers from contaminating ad targets, pricing decisions, and buy_price.

## What shipped

### Drift-prevention foundation
- **`config/supplier.php`** — plain-literal `default_stale_after_days=7` + `amber_warning_ratio=0.7` (env() guardrail satisfied).
- **`suppliers` table** created from scratch (was no such table; `supplier_offer_snapshots.supplier_id` is a raw VARCHAR(16) — kept as raw text key, NOT FK).
- **`supplier_freshness_snapshots` table** — ULID `run_id` per `suppliers:check-stale` invocation; TRUNCATE-and-replace semantics mirroring 260607-t6w `category_audit_findings`.
- **`SupplierFreshnessResolver` singleton** with per-request cache: `freshSupplierIds()`, `staleSupplierIds()`, `amberSupplierIds()`, `classify()`, `thresholdDaysFor()`, `latestRecordedAtFor()`, `daysSinceFor()`. Driver-aware SQL (MySQL `DATEDIFF` vs SQLite `julianday`). Per-request memoisation pinned by Case F (`DB::enableQueryLog()` — second call adds zero queries).
- **`SupplierFreshnessSnapshot::scopeCurrent()`** — sub-select on latest `run_id` for dashboard reads.

### The command
- **`suppliers:check-stale [--dry-run]`** — discovers distinct supplier_ids in snapshots, upserts `suppliers` rows (so operator gets a per-supplier override slot), TRUNCATEs `supplier_freshness_snapshots`, INSERTs one row per known supplier under a fresh ULID `run_id`. Mon-Fri 07:45 London via `routes/console.php` — slotted between `suggestions:auto-apply` (07:30) and `reports:supplier-sync-digest` (08:00); 45 min after `supplier:db-sync` (07:00) so today's sync had a chance to write fresh `recorded_at`.

### Three downstream wirings (all opt-in via ctor flag defaulting TRUE)
1. **`AdCandidateScanner`** — `latestSupplierByProductId()` appends `AND supplier_id IN (...fresh...)` when flag ON; short-circuits to `[]` when no fresh suppliers. Stops the Ad-Candidates page recommending Google Ads spend on parts only "in stock" at a silent supplier.
2. **`CompetitorPositionScanner.cheapestSupplierNameByProductId()`** — appends `AND supplier_id NOT IN (...stale...)` when flag ON; OMITS the clause entirely when stale set is empty (MySQL safety). Popup's "Our cost (ex): £X from {supplier}" now reflects the cheapest FRESH supplier; falls through to `null` when all suppliers stale.
3. **`SupplierDbSyncCommand::buildBestOfferMap()`** — drops offers from stale suppliers BEFORE the cheapest-in-stock reduction. Cleanest cut: once `products.buy_price` stops absorbing stale prices, downstream Pricing/Ads/Merchant Feed heal because they all read `products.buy_price`.

### Dashboard + notifications
- **`SnapshotAggregator::computeSupplierFreshness()`** reads `supplier_freshness_snapshots` (NOT the resolver — D-02 truth: never live aggregation on dashboard render). Returns fresh/amber/stale/unknown counts + top-20 stale_suppliers list. Defensive try/catch returns zero payload if the table is absent.
- **`SupplierFreshnessWidget`** — StatsOverviewWidget with 4 Stats (fresh / amber / stale / unknown / dormant). 60s polling. Inherits `DashboardSnapshot::viewAny` policy gate. Registered in `HomeDashboardPage::getWidgets()` between CompetitorFreshnessWidget and PendingReviewsWidget; also added to "Today's sync" section.
- **`NotificationCentreAggregator::staleSuppliers()`** — mirrors `staleFeeds()` shape, returns up to 10 latest-run stale suppliers. NotificationCentre Filament page isn't edited this quick — the bucket is exposed for the next page edit to consume.

### `SupplierOfferSnapshot::scopeFreshOnly()`
- Canonical "exclude stale-supplier rows" filter. Uses string sentinel `'__NO_FRESH_SUPPLIERS__'` for empty-whereIn safety (supplier_id is VARCHAR(16) — int sentinel would silently cast).

## Smoke-run output (`suppliers:check-stale --dry-run` on seeded local DB)

```
Correlation: 0649d3e8-ab03-43f2-abb9-438bb9d00f62
suppliers:check-stale — DRY-RUN
Discovered 3 distinct supplier_id(s) in supplier_offer_snapshots.
  suppliers table: 0 row(s) (dry-run — upsert skipped).
[dry-run] — would TRUNCATE supplier_freshness_snapshots + INSERT 3 row(s).
------------------------------------------------------------
fresh: 1 | amber: 1 | stale: 1 | unknown: 0
Stale suppliers (downstream stock-decayed):
  - supplier_id=MIDWICH name=Midwich days_since=22 threshold=7
```

Seeded fixture (NUVIAS today / INGRAM today-5 / MIDWICH today-22) classifies correctly: NUVIAS=fresh, INGRAM=amber (5 ≥ floor(7×0.7)=4), MIDWICH=stale (22 ≥ 7). Cleaned up post-run.

## Test results

**New cases:** 18 passing (resolver 6 / command 3 / adcand-stale 2 / comppos-stale 4 / supdbsync-stale 2 / scope 1). Plan estimated 16; I added 2 extra back-compat-inverse cases to CompetitorPositionScannerStaleSupplierTest for completeness.

**Focused regression filters (CompetitorPositionScannerTest, AdCandidatesPageTest, HomeDashboardPageTest, EnvUsageTest, SupplierDbSyncCommandTest, SourcingGapScannerTest):** 39 + 6 = 45/46 GREEN. The single failure (`HomeDashboardPageTest > renders 9 widget class names somewhere in the /admin HTML`) is PRE-EXISTING — verified by re-running the same test on the unmodified baseline. It is the same assertion that has been failing since pre-260608-g8x and is part of the 222 baseline failures.

**Full Pest suite delta vs 260607-v5g baseline (1,935 pass / 222 fail / 3 skip):**

```
Tests: 222 failed, 3 skipped, 1953 passed (9684 assertions)
Duration: 1427.96s
```

**Delta: +18 pass / 0 new fails / 0 new skips.** Exactly the 18 new cases.

## Adherence to the 3 planner-flagged corrections

1. **`suppliers` table created from scratch (not ALTER)** — confirmed via `Schema::hasTable("suppliers")` probe before this quick: false. New migration is a `Schema::create`. `supplier_offer_snapshots.supplier_id` (VARCHAR(16)) used as raw text key throughout; NO FK.
2. **Buy-price resolver wired in `SupplierDbSyncCommand::buildBestOfferMap`** (NOT `BackfillMerchantFeedCommand`). `BackfillMerchantFeedCommand` untouched — verified via `grep`.
3. **Cron slot = Mon-Fri 07:45 London** (NOT 06:30, NOT 07:30 — both contended). `schedule:list` confirms `45 6 * * 1-5` UTC = 07:45 BST, slotted between suggestions:auto-apply (07:30) and reports:supplier-sync-digest (08:00).

## Drift-prevention proof

- **One classifier, three consumers:** All freshness logic centralised in `SupplierFreshnessResolver`. Grep `stale_after_days` across `app/` outside the resolver / model / migration / config:
  ```
  $ grep -rn "stale_after_days" app/ | grep -v "Resolver\|Migration\|Model\|Test\|config\|Supplier.php"
  ```
  Only the supporting infrastructure references it.
- **Empty-whereIn safety:** Three distinct patterns — `freshOnly()` scope uses the string sentinel `'__NO_FRESH_SUPPLIERS__'`; `AdCandidateScanner` short-circuits to `return []` when fresh ids are empty; `CompetitorPositionScanner` SKIPS the `NOT IN` clause when stale ids are empty (MySQL parse safety).
- **Env() guardrail respected:** Zero new `env()` calls outside `config/`. `EnvUsageTest` GREEN.
- **Driver-aware SQL:** Resolver switches MySQL `DATEDIFF` vs SQLite `julianday` via `DB::connection()->getDriverName()`. All 18 new tests run on in-memory SQLite + would run on prod MySQL.
- **Per-request memoisation:** Resolver is singleton-bound; Case F pins zero-query second call.
- **TRUNCATE-and-replace semantics:** matches 260607-t6w `category_audit_findings`.
- **Schedule lands AFTER `supplier:db-sync`:** 07:45 > 07:00 by 45 min.

## Follow-up notes

- **Future Filament resource for `suppliers`:** operator currently edits `stale_after_days` via tinker. A small Filament Resource (`/admin/suppliers`) listing all rows with editable `stale_after_days` + `is_active` toggle would close the loop — deliberately out of scope this quick (named "out of scope" in PLAN.md Task 1 + Task 7).
- **NotificationCentre Filament page consumption:** `NotificationCentreAggregator::staleSuppliers()` is exposed but the Filament NotificationCentrePage isn't edited to render a new tab here — deferred to a follow-up quick.
- **Prod cron readiness:** On first prod run, `suppliers:check-stale` will discover every supplier observed in supplier_offer_snapshots and upsert a `suppliers` row each. Initial classification will be all-fresh because every supplier has a same-day snapshot from the just-completed 07:00 `supplier:db-sync`. The Nuvias-like case (silent 3+ weeks) will only emerge after a supplier actually misses the threshold.

## Self-Check: PASSED

All commit hashes resolve in `git log`; all created files exist on disk; all new test files referenced are present and GREEN.
