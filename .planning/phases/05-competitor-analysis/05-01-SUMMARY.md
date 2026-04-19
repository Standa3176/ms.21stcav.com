---
phase: 05-competitor-analysis
plan: 01
subsystem: competitor
tags: [data-model, migrations, eloquent, factories, policies, comp-07-dedup, d-03-mapping, d-04-quarantine, config-thresholds]

requires:
  - phase: 01-foundation
    provides: "Auditor (LogsActivity on Competitor/CompetitorCsvMapping/CompetitorIngestRun); meetingstore_ops_testing MySQL DB; Pest Feature + Architecture test harness"
  - phase: 02-supplier-sync
    provides: "PolicyTemplateIntegrityTest (tests/Architecture) — extended to scan app/Domain/Competitor/Policies; rollback round-trip test pattern"
  - phase: 03-pricing-engine
    provides: "Deptrac Pricing layer allow-list ready for Phase 5 Competitor layer dependency (VAT-strip reads coming in Plan 05-02)"
  - phase: 04-bitrix24-crm-sync
    provides: "Most-recent receives_*_alerts boolean pattern — Phase 5 mirrors for receives_competitor_alerts; latest data-model-plan shape; AppServiceProvider Gate::policy registration pattern"

provides:
  - "7 migrations at 2026_04_21_090000..090600 — 5 new tables + 2 additive columns"
  - "competitors table: id, slug(64 unique), name, website_url nullable, map_policy_notes text nullable, status enum pending|active|inactive default pending indexed, is_active bool default true, last_ingest_at timestamp nullable, timestamps"
  - "competitor_csv_mappings table: competitor_id FK unique cascadeOnDelete (D-03 one-mapping-per-competitor), sku_column_index + price_column_index unsignedSmallInteger, decimal_format enum dot|comma default dot, detected_at timestamp, timestamps"
  - "competitor_ingest_runs table: competitor_id FK nullOnDelete, filename, 4 row counters (total/written/errored/orphaned), status enum started|completed|failed default started, started_at + completed_at nullable, correlation_id VARCHAR(36) indexed, error_message nullable, timestamps + (competitor_id, started_at) composite index"
  - "competitor_prices table: competitor_id FK cascadeOnDelete, sku string(128), mpn string(128) nullable, price_pennies_ex_vat + price_pennies_gross BIGINT UNSIGNED, recorded_at timestamp, ingest_run_id FK nullable cascadeOnDelete, UNIQUE(competitor_id, sku, recorded_at) COMP-07 dedup, index(sku), (competitor_id, recorded_at) + (recorded_at)"
  - "csv_parse_errors table: ingest_run_id + competitor_id FKs nullOnDelete, filename, issue_type enum (ambiguous_mapping|encoding_failure|unparseable_price|invalid_sku_format|invalid_filename|orphan_sku), line_number unsignedInt nullable, raw_line text nullable, context json nullable, resolved_at timestamp nullable, (issue_type, resolved_at) composite index"
  - "alert_recipients.receives_competitor_alerts bool default false AFTER receives_crm_alerts — seeded ops@meetingstore.co.uk force-updated to true by migration"
  - "products.last_sales_count_90d unsignedInt nullable + products.last_sales_count_computed_at timestamp nullable AFTER sell_price"
  - "config/competitor.php — 9 keys: margin_delta_threshold_bps=800, min_margin_floor_bps=500, consecutive_scrapes_required=3, sales_threshold_90d=10, beat_by_pennies=1, csv_retention_days=90, stale_feed_hours=48, csv_chunk_size=100, filename_regex"
  - "App\\Domain\\Competitor\\Models\\Competitor — HasFactory + LogsActivity + STATUS_* constants + isActive() + scopeActive + prices/ingestRuns/csvMapping relations"
  - "App\\Domain\\Competitor\\Models\\CompetitorPrice — HasFactory NO LogsActivity (high-volume table precedent) + int casts + competitor/ingestRun belongsTo"
  - "App\\Domain\\Competitor\\Models\\CompetitorCsvMapping — HasFactory + LogsActivity + FORMAT_* constants + competitor belongsTo"
  - "App\\Domain\\Competitor\\Models\\CompetitorIngestRun — HasFactory + LogsActivity + STATUS_* constants + prices/parseErrors hasMany + competitor belongsTo"
  - "App\\Domain\\Competitor\\Models\\CsvParseError — HasFactory NO LogsActivity (self-audit) + TYPE_* 6 constants + scopeUnresolved/ofType + isResolved() helper"
  - "5 hand-written policies under app/Domain/Competitor/Policies/ — CompetitorPolicy (admin+pricing_manager view; admin write), CompetitorPricePolicy (admin+pricing_manager+sales view, no writes), CompetitorCsvMappingPolicy (D-04 pricing_manager can update quarantined mappings), CompetitorIngestRunPolicy (admin+pricing_manager+sales view, no writes), CsvParseErrorPolicy (admin+pricing_manager view+update, admin delete)"
  - "5 factories at database/factories/Domain/Competitor/ — CompetitorFactory (pending/inactive/stale states), CompetitorPriceFactory (forSku/recordedAt state helpers — NO unique() on SKU so trend tests can reuse it), CompetitorCsvMappingFactory (commaDecimal), CompetitorIngestRunFactory (completed/failed; Str::uuid() 36-char correlation_id), CsvParseErrorFactory (ambiguousMapping/orphanSku/resolved)"
  - "27 Pest tests under tests/Feature/Competitor/ (99 assertions)"
  - "AppServiceProvider::boot appended 5 new Gate::policy bindings for all 5 Competitor models"
  - "AlertRecipientSeeder appended receives_competitor_alerts=true to Pitfall M fallback row"

affects:
  - "05-02-csv-ingest-pipeline (ColumnHeuristicDetector writes CompetitorCsvMapping; IngestCompetitorCsvJob writes CompetitorPrice + CompetitorIngestRun; CsvParseError captures all failure modes; no schema left to design)"
  - "05-03-margin-analyser (reads config/competitor.php thresholds; reads products.last_sales_count_90d; reads competitor_prices for delta computation; emits margin_change Suggestion)"
  - "05-04-filament-ui (CompetitorResource + CompetitorPriceResource + CompetitorIngestRunResource + CsvParseErrorResource bind against these 5 models + policies; CsvIngestIssuesPage filters csv_parse_errors by (issue_type, resolved_at))"
  - "05-05-retention-alerts (PruneCompetitorCsvsJob consumes config('competitor.csv_retention_days'); CompetitorCheckStaleCommand uses stale_feed_hours; AlertDistribution scopes by receives_competitor_alerts)"

tech-stack:
  added:
    - "Domain-scoped factory namespace Database\\Factories\\Domain\\Competitor\\ — PSR-4 autoloaded via existing composer.json map"
  patterns:
    - "Hand-written hasRole policies (Pitfall K + P2-H + P5-F) — identical shape to Phase 1/2/3/4 policies; restore-protocol docblock on every file."
    - "Phase 5 avoids the `{{ ` literal in docblocks (caught during first full test run) — language of explanatory comments tweaked to say 'Shield placeholder stub literals' instead of the actual placeholder syntax. PolicyTemplateIntegrityTest is a content-level grep so even docblocks count."
    - "AlertRecipientSeeder gained a third receives_* row (Phase 2 D-08 + Phase 4 D-12 + Phase 5 receives_competitor_alerts) — pattern is compounding nicely."
    - "CompetitorPrice NO LogsActivity mirrors Phase 2 ProductVariant decision: high-volume ingest tables should not write 1 audit_log row per persist."

key-files:
  created:
    - "database/migrations/2026_04_21_090000_create_competitors_table.php"
    - "database/migrations/2026_04_21_090100_create_competitor_csv_mappings_table.php"
    - "database/migrations/2026_04_21_090200_create_competitor_ingest_runs_table.php"
    - "database/migrations/2026_04_21_090300_create_competitor_prices_table.php"
    - "database/migrations/2026_04_21_090400_create_csv_parse_errors_table.php"
    - "database/migrations/2026_04_21_090500_add_receives_competitor_alerts_to_alert_recipients.php"
    - "database/migrations/2026_04_21_090600_add_sales_count_90d_to_products.php"
    - "config/competitor.php"
    - "app/Domain/Competitor/Models/Competitor.php"
    - "app/Domain/Competitor/Models/CompetitorPrice.php"
    - "app/Domain/Competitor/Models/CompetitorCsvMapping.php"
    - "app/Domain/Competitor/Models/CompetitorIngestRun.php"
    - "app/Domain/Competitor/Models/CsvParseError.php"
    - "app/Domain/Competitor/Policies/CompetitorPolicy.php"
    - "app/Domain/Competitor/Policies/CompetitorPricePolicy.php"
    - "app/Domain/Competitor/Policies/CompetitorCsvMappingPolicy.php"
    - "app/Domain/Competitor/Policies/CompetitorIngestRunPolicy.php"
    - "app/Domain/Competitor/Policies/CsvParseErrorPolicy.php"
    - "database/factories/Domain/Competitor/CompetitorFactory.php"
    - "database/factories/Domain/Competitor/CompetitorPriceFactory.php"
    - "database/factories/Domain/Competitor/CompetitorCsvMappingFactory.php"
    - "database/factories/Domain/Competitor/CompetitorIngestRunFactory.php"
    - "database/factories/Domain/Competitor/CsvParseErrorFactory.php"
    - "tests/Feature/Competitor/CompetitorModelTest.php"
    - "tests/Feature/Competitor/CompetitorPriceModelTest.php"
    - "tests/Feature/Competitor/CompetitorIngestRunModelTest.php"
    - "tests/Feature/Competitor/AlertRecipientReceivesCompetitorAlertsTest.php"
    - "tests/Feature/Competitor/ProductSalesCountColumnTest.php"
  modified:
    - "app/Domain/Alerting/Models/AlertRecipient.php — receives_competitor_alerts added to $fillable + $casts + scopeReceivesCompetitorAlerts"
    - "app/Domain/Products/Models/Product.php — last_sales_count_90d + last_sales_count_computed_at added to $fillable + $casts"
    - "app/Providers/AppServiceProvider.php — 5 new Gate::policy bindings appended (Competitor/CompetitorPrice/CompetitorCsvMapping/CompetitorIngestRun/CsvParseError)"
    - "database/seeders/AlertRecipientSeeder.php — receives_competitor_alerts=true appended to Pitfall M fallback row"
    - "tests/Architecture/PolicyTemplateIntegrityTest.php — scan path + Gate pair list extended; positive-control floor 16→21"
    - "tests/Feature/Phase02DataModelTest.php — rollback step 20→27; assertions extended to cover 5 new Phase 5 tables + 2 additive columns"

key-decisions:
  - "Config key `margin_delta_threshold_bps` uses basis points (800 = 8%) not percent or decimal — matches Phase 3's basis_points convention for PricingRule.margin_basis_points so downstream analyser code reads one integer type across the pipeline."
  - "`min_margin_floor_bps` (500 = 5%) shipped as a NEW config key even though Pitfall P5-E only surfaced it as a guardrail — cheaper to ship the config key in Plan 05-01 than to retrofit in 05-03 when the MarginAnalyser lands. Plan truth-list already locks it."
  - "CompetitorPriceFactory deliberately does NOT use `->unique()` on SKU — Plan 05-02 trend-chart + orphan tests need multiple rows per SKU across dates. Tests needing distinctness pass `->forSku($sku)` explicitly; the unique constraint is enforced at the DB layer by (competitor_id, sku, recorded_at) anyway."
  - "`ingest_run_id` on competitor_prices is nullable with cascadeOnDelete — forward-compat for Woo-import backfills that land rows without a run row (similar to Phase 2 SyncRun::findResumable pattern). Phase 5 production path always writes a run first, so the nullable column is insurance not design shortcut."
  - "CompetitorIngestRun has no `aborted` state (unlike Phase 2 SyncRun) — COMP-07 dedup via the unique index makes re-ingest idempotent, so crashed-then-rerun is a completed state-transition not an aborted one. Simpler state machine = less Plan 05-02 surface."
  - "`config/competitor.php` and migrations shipped in a single Task 1 commit — factory smoke tests in Task 2's TDD RED need both in place for model class resolution + config()->env() calls. Same split-hazard lesson as Phase 2 Plan 01."
  - "Policies registered in AppServiceProvider::boot (not AuthServiceProvider) — this project doesn't have AuthServiceProvider; existing Phase 1-4 Gate::policy bindings all live in AppServiceProvider. Plan truth-list deviates from plan prose; existing convention wins."

requirements-completed:
  - COMP-07

duration: ~17 min
completed: 2026-04-19
---

# Phase 05 Plan 01: Data Model + Admin CRUD Foundation Summary

**7 Phase-5 migrations + 5 Competitor Eloquent models (LogsActivity on 3, high-volume-skip on 2) + 5 hand-written policies (D-04 pricing_manager quarantine resolution) + 5 factories with state helpers + config/competitor.php with 9 locked thresholds including the Pitfall P5-E `min_margin_floor_bps=500` safety floor + AppServiceProvider wiring for all 5 Gate bindings + AlertRecipientSeeder third-receives_* extension. 27 new Pest tests green; full suite 623 passed / 0 failed (5523 assertions); Deptrac 0 violations; PolicyTemplateIntegrityTest floor 16→21; Phase 2 rollback round-trip updated 20→27 steps and extended to assert Phase 5 table lifecycle.**

## Performance

- **Duration:** ~17 min (2 tasks, both tdd="true")
- **Started:** 2026-04-19T20:32Z
- **Completed:** 2026-04-19T20:49Z
- **Tasks:** 2
- **Commits:** 2 (+ 1 final metadata commit)
- **Files created:** 28 (7 migrations + 5 models + 5 policies + 5 factories + 5 test files + 1 config)
- **Files modified:** 6 (2 existing models + AppServiceProvider + 2 tests + 1 seeder)

## Accomplishments

### 7 migrations apply clean on meetingstore_ops_testing MySQL

Shipped under the `2026_04_21_090*` timestamp slot (Phase 4 used `2026_04_20_*`). `php artisan migrate --env=testing --force` ran in ~2.8s total; rollback test (Phase 2's round-trip harness, extended) ran step=27 in under 25s and re-migrated cleanly.

Schema highlights:

| Table | Critical index |
|-------|----------------|
| `competitors` | `slug` UNIQUE + `status` indexed for stale-feed queries |
| `competitor_csv_mappings` | `competitor_id` UNIQUE (D-03 one-per-competitor) |
| `competitor_ingest_runs` | `correlation_id` indexed + `(competitor_id, started_at)` composite |
| `competitor_prices` | **UNIQUE(competitor_id, sku, recorded_at)** — COMP-07 dedup |
| `csv_parse_errors` | `(issue_type, resolved_at)` — drives Filament triage tabs |
| `alert_recipients` | `receives_competitor_alerts` bool default false |
| `products` | `last_sales_count_90d` + `last_sales_count_computed_at` |

### config/competitor.php — 9 locked keys

Every threshold Plan 05-02+ will read is in this file from day one:

| Key | Value | Locked by |
|-----|-------|-----------|
| `margin_delta_threshold_bps` | 800 (=8%) | REQUIREMENTS default |
| `min_margin_floor_bps` | 500 (=5%) | Pitfall P5-E — never recommend below this |
| `consecutive_scrapes_required` | 3 | REQUIREMENTS default |
| `sales_threshold_90d` | 10 orders | D-05 |
| `beat_by_pennies` | 1p | D-07 default |
| `csv_retention_days` | 90 | COMP-12 default |
| `stale_feed_hours` | 48 | Claude's Discretion |
| `csv_chunk_size` | 100 | Plan 05-02 chunk-size default |
| `filename_regex` | `/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/` | D-01 |

### 5 Competitor models with pragmatic LogsActivity split

- **LogsActivity ON:** Competitor (admin-edited config), CompetitorCsvMapping (audit-worthy — changes parser behaviour), CompetitorIngestRun (tracks state-machine transitions).
- **LogsActivity OFF:** CompetitorPrice (high-volume — ~3.6M rows/year projected, Phase 2 ProductVariant precedent), CsvParseError (self-audit — this table IS the triage log).

### 5 hand-written policies with D-04 quarantine resolution path

| Policy | viewAny | update | delete |
|--------|---------|--------|--------|
| CompetitorPolicy | admin + pricing_manager | admin | admin |
| CompetitorPricePolicy | admin + pricing_manager + sales | (false) | (false) |
| CompetitorCsvMappingPolicy | admin + pricing_manager | admin + **pricing_manager (D-04)** | admin |
| CompetitorIngestRunPolicy | admin + pricing_manager + sales | (false) | (false) |
| CsvParseErrorPolicy | admin + pricing_manager | admin + pricing_manager | admin |

All 5 policies pass the tests/Architecture/PolicyTemplateIntegrityTest — zero `{{ ` literal leaks, Gate::policy bindings resolve correctly.

### 5 factories with state helpers that Plan 05-02+ will consume

- `CompetitorFactory::pending()` / `::inactive()` / `::stale()` — D-02 lifecycle + stale-feed test fixtures.
- `CompetitorPriceFactory::forSku($sku)` / `::recordedAt($date)` — trend-chart test fixtures; deliberately NO `->unique()` on `sku` so multiple rows per SKU-per-day work.
- `CompetitorIngestRunFactory::completed()` / `::failed()` — status state machine fixtures with `Str::uuid()` 36-char correlation_id.
- `CsvParseErrorFactory::ambiguousMapping()` / `::orphanSku()` / `::resolved()` — drives CsvIngestIssuesPage tab tests.

### PolicyTemplateIntegrityTest + Phase 2 rollback test extended

- Scan path added: `app/Domain/Competitor/Policies`
- Gate-pair list extended with 5 new pairs
- Positive-control floor raised 16 → 21
- Phase 2 rollback step count 20 → 27; assertion body extended to cover the 5 new Phase 5 tables + `receives_competitor_alerts` / `last_sales_count_90d` columns

### 27 Pest tests green (99 assertions)

| File | Tests |
|------|-------|
| `CompetitorModelTest` | 8 — schema + factory + constants + relations + 3 policy assertions + Gate binding |
| `CompetitorPriceModelTest` | 5 — schema + UNIQUE index + multi-date same-SKU + relations + casts |
| `CompetitorIngestRunModelTest` | 6 — 36-char UUID + constants + relations + D-03 unique + enum rejection + error scopes |
| `AlertRecipientReceivesCompetitorAlertsTest` | 4 — default false + seeder true + scope + cast |
| `ProductSalesCountColumnTest` | 4 — schema + int cast + datetime cast + NULL default |

## Task Commits

1. **Task 1:** 7 Phase-5 migrations + config/competitor.php + Product/AlertRecipient fillable updates — `03076ad` (feat)
2. **Task 2:** 5 models + 5 policies + 5 factories + AppServiceProvider wiring + AlertRecipientSeeder extension + PolicyTemplateIntegrityTest extension + Phase02DataModelTest round-trip update + 5 Pest test files — `7068550` (feat)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — Missing Critical] AlertRecipientSeeder missing `receives_competitor_alerts => true` for Pitfall M fallback row**

- **Found during:** Task 2 first full Pest run — `AlertRecipientReceivesCompetitorAlertsTest::it seeds ops@meetingstore.co.uk with receives_competitor_alerts=true via migration` failed.
- **Issue:** The migration's `UPDATE alert_recipients SET receives_competitor_alerts=true WHERE email='ops@meetingstore.co.uk'` only catches the ROW at migration time. Under `RefreshDatabase`, migrations run fresh on an empty DB, then the test calls `->seed(AlertRecipientSeeder)` — the seeder's `firstOrCreate` inserted `ops@meetingstore.co.uk` with the column's default (false), bypassing the backfill entirely.
- **Fix:** Extended `AlertRecipientSeeder::run()` to pass `receives_competitor_alerts => true` in the `firstOrCreate` attributes — mirrors the Plan 04-03 pattern for `receives_crm_alerts`. Now both the migration backfill (for pre-existing prod rows) AND the seeder (for fresh deploys / test RefreshDatabase) populate the column correctly.
- **Files modified:** `database/seeders/AlertRecipientSeeder.php`
- **Verification:** All 4 `AlertRecipientReceivesCompetitorAlertsTest` tests pass; Phase 2/4 receives_* tests still green.
- **Committed in:** `7068550` (Task 2)

**2. [Rule 1 — Bug] `{{ Placeholder }}` literal string in CompetitorPolicy docblock trip PolicyTemplateIntegrityTest**

- **Found during:** Task 2 Architecture suite run — PolicyTemplateIntegrityTest went red with "Shield placeholder literal leaked into: CompetitorPolicy.php".
- **Issue:** The docblock on `CompetitorPolicy` described the anti-pattern as `"would emit `{{ Placeholder }}` literal stubs"` — the integrity test grep is content-level and catches the literal `{{ ` string regardless of whether it's in executable code or a comment.
- **Fix:** Rephrased the docblock sentence to say "would emit Shield placeholder stub literals" — same semantic meaning, no literal `{{ ` substring. Other 4 policies used identical docblock boilerplate but without the specific `{{ ` example; they passed unchanged.
- **Files modified:** `app/Domain/Competitor/Policies/CompetitorPolicy.php`
- **Verification:** `grep -rn '{{ ' app/Domain/Competitor/Policies/ | wc -l` → 0; full Architecture suite 18/18 pass.
- **Committed in:** `7068550` (Task 2)

**3. [Rule 2 — Missing Critical] Phase 2 rollback round-trip test hardcodes `step=20` — would break after 7 new Phase 5 migrations**

- **Found during:** Task 2 plan-reading — Phase 4 Plan 01 had caught the analogous 11→17 update; Phase 5 inherits the same hazard.
- **Issue:** `tests/Feature/Phase02DataModelTest.php::'rolls back the 6 Phase-2 migrations...'` calls `migrate:rollback --step 20`. After Phase 5 Plan 01 adds 7 migrations, the step no longer reaches the Phase 2 products table.
- **Fix:** Updated step count 20→27; extended the rollback-assertion block + re-migrate-assertion block to cover the 5 new Phase 5 tables + `receives_competitor_alerts` / `last_sales_count_90d` columns. Comment-block documentation kept in sync.
- **Files modified:** `tests/Feature/Phase02DataModelTest.php`
- **Verification:** Test passes (41 assertions); full suite 623/0.
- **Committed in:** `7068550` (Task 2)

**4. [Rule 2 — Missing Critical] PolicyTemplateIntegrityTest must scan new Competitor Policies + gain 5 Gate pairs — plan truth-list calls for this but hadn't been wired**

- **Found during:** Task 2 plan-reading + Phase 4's Plan 01 precedent.
- **Issue:** Without extending the Architecture test, Shield `{{ Placeholder }}` leaks into any future Competitor policy would silently pass CI — defeating the guardrail's purpose. Plan truth-list's "Bump floor count" + Warning block flagged this.
- **Fix:** Added `app_path('Domain/Competitor/Policies')` to both the placeholder scanner AND the positive-control glob; extended the Gate pair array with 5 new Competitor models; raised positive-control floor 16→21 (9 pre-Phase-4 + 5 CRM + 2 Plan 04/05 extras + 5 Competitor).
- **Files modified:** `tests/Architecture/PolicyTemplateIntegrityTest.php`
- **Verification:** 3 integrity tests still green; floor assertion passes at exactly 21.
- **Committed in:** `7068550` (Task 2)

**5. [Rule 3 — Blocking] Plan truth-list says "AuthServiceProvider"; this project only has AppServiceProvider**

- **Found during:** Task 2 plan-reading.
- **Issue:** Plan explicitly references `app/Providers/AuthServiceProvider.php — APPEND to existing $policies array` — but this Laravel 12 project doesn't use AuthServiceProvider at all. Every existing policy (Phase 1-4) registers via `Gate::policy()` inside `AppServiceProvider::boot`.
- **Fix:** Registered all 5 new Gate::policy bindings in AppServiceProvider::boot after the existing Phase 4 Plan 05 GdprErasureLog binding. Documented the deviation in frontmatter key-decisions so future phases see the precedent stays put.
- **Files modified:** `app/Providers/AppServiceProvider.php`
- **Verification:** All 5 Gate::policy bindings resolve correctly via `Gate::getPolicyFor(new $model)` in the integrity test; 8 CompetitorModelTest policy assertions pass.
- **Committed in:** `7068550` (Task 2)

---

**Total deviations:** 5 auto-fixed (3× Rule 2 missing-critical, 1× Rule 1 bug, 1× Rule 3 blocking). All required for correctness + guardrail coverage + test-harness integrity. No Rule 4 architectural asks. Plan contract (7 migrations + 5 models + 5 policies + 5 factories + config + 5 tests + AuthServiceProvider wiring — adapted to AppServiceProvider) shipped in full. COMP-07 requirement is now data-model-backed.

## Authentication Gates

None — this plan is pure schema + Eloquent + policy + config.

## Factory recyclable configuration (for Plan 05-02 fixture seeding)

- **`CompetitorPriceFactory::definition()` does NOT mark `sku` unique.** Tests that need cross-row SKU distinctness should pass `sku` explicitly. Same-SKU-across-dates is valid (trend tests require it); the DB-level UNIQUE(competitor_id, sku, recorded_at) is the actual uniqueness guarantee.
- **`CompetitorIngestRunFactory` uses `Str::uuid()` — never prefix in tests.** Correlation_id column is VARCHAR(36) (Plan 02-02 lesson — prefixing trips SQLSTATE 22001 truncation).
- **`CompetitorFactory::stale()` sets `last_ingest_at = now()->subHours(72)` — matches the 48h stale-feed threshold for Plan 05-05 alerting tests.**

## Issues Encountered

- **Full test suite duration creeping up:** 472s (7m52s) for 625 tests on Windows dev (Herd PHP 8.4). Phase 5+ parallel-execution or DB isolation refactor is worth tracking as a concern; CI target of <60s is long gone. Not a blocker for Plan 05-02 — listed in Deferred Ideas.
- **`grep -rn '{{ ' app/Domain/*/Policies/` caught a literal in my own docblock** (Deviation #2). The guardrail is content-level so even explanatory comments that quote the anti-pattern trip it. Worth documenting for Phase 6+ planners: describe Shield placeholder leaks in prose, never quote the literal.

## User Setup Required

None — schema changes are forward-compatible. Plan 05-02+ operators need:

- `php artisan migrate --force` after deploy (routine)
- No new env vars (all `COMPETITOR_*` env vars have safe defaults in `config/competitor.php`)
- No new queue supervisors (Phase 1 FOUND-09 pre-allocated `competitor-csv` queue name)

## Next Phase Readiness

### Plan 05-02 (watcher + parser + chunk jobs) can assume

- `Competitor::factory()->pending()` + `Competitor::factory()->stale()` produce valid fixtures
- `CompetitorPrice::factory()->forSku('X')->recordedAt($date)->create()` is the trend-chart fixture pattern
- `CompetitorIngestRun::factory()->create()` ships with a valid 36-char `correlation_id`
- Unique constraint on `competitor_prices` enforces COMP-07 at DB layer — parser can `insert()` without `INSERT IGNORE` and let QueryException surface dedup
- `config('competitor.csv_chunk_size')` = 100 is the default chunk row count for `CompetitorCsvChunkJob`
- `config('competitor.filename_regex')` is the D-01 filename validator
- `csv_parse_errors` table is ready for `invalid_filename` / `encoding_failure` / `unparseable_price` writes

### Plan 05-03 (MarginAnalyser) can assume

- `config('competitor.margin_delta_threshold_bps')` = 800 is the noise-suppression gate
- `config('competitor.min_margin_floor_bps')` = 500 is the P5-E guard
- `config('competitor.sales_threshold_90d')` = 10 is the demand gate
- `products.last_sales_count_90d` is the column to read (populated daily by Plan 05-03's SalesCounterService)
- `CompetitorPrice::where('competitor_id',...)->where('sku',...)->orderByDesc('recorded_at')` is the "last 3 scrapes" query for `consecutive_scrapes_required=3`

### Plan 05-04 (Filament UI) can assume

- 5 Gate::policy bindings already live — `CompetitorResource` / `CompetitorPriceResource` / `CompetitorIngestRunResource` / `CsvParseErrorResource` + `CompetitorCsvMappingResource` bind directly
- **CRITICAL:** `shield:generate --all --panel=admin` will overwrite ALL 5 Competitor policies with Shield stubs. Plan 05-04 Task 2b MUST restore the hasRole overrides from git (every policy file has explicit docblock restore-protocol). PolicyTemplateIntegrityTest catches leaks in <2s.
- `csv_parse_errors` tabs filter on `(issue_type, resolved_at IS NULL)` — composite index is in place
- `CompetitorCsvMapping::$fillable` + casts ready for Filament form bindings

### Plan 05-05 (retention + stale-feed alerts) can assume

- `config('competitor.csv_retention_days')` = 90 is the default prune age
- `config('competitor.stale_feed_hours')` = 48 is the stale-feed alert threshold
- `AlertRecipient::receivesCompetitorAlerts()` scope filters opted-in rows for `StaleFeedAlertNotification`
- `competitor_prices` rows are NEVER pruned per COMP-07 — prune command MUST target raw CSV files under `storage/app/competitors/archive/` only

### Known concerns for later plans

1. **`new_product_opportunity` suggestion applier** — Plan 05-02 will fire this kind (D-08/D-09). No applier registration yet in AppServiceProvider — Plan 05-02 Task that introduces orphan-detection must register a no-op stub (Phase 4 `crm_push_failed` pattern) so the kind is recognised.
2. **Deptrac Competitor layer not yet added** — Plan 05-01 Competitor models have zero cross-domain imports. Plan 05-02 or 05-03 (when analyser reads Products.last_sales_count_90d + Pricing.PriceCalculator::stripVat) MUST extend `depfile.yaml` with a new `Competitor` layer allowed to depend on `Foundation`, `Products`, `Pricing`, `Suggestions`.
3. **Shield regen hazard** — as in every prior phase, any `shield:generate --all` run regenerates our 5 Competitor policies with stubs. Plan 05-04 must restore via git.
4. **CompetitorPrice write amplification at scale** — at 5 competitors × 2000 SKUs × daily = 10k inserts/day. `insertOrIgnore`-pattern with ingest chunk job is a Plan 05-02 concern but `UNIQUE(...)` constraint at DB level is in place.

## Self-Check: PASSED

- Created files verified:
  - 7 migrations at `2026_04_21_090*` FOUND
  - 5 models under `app/Domain/Competitor/Models/` FOUND
  - 5 policies under `app/Domain/Competitor/Policies/` FOUND
  - 5 factories under `database/factories/Domain/Competitor/` FOUND
  - 5 test files under `tests/Feature/Competitor/` FOUND
  - `config/competitor.php` FOUND
- Commits verified via `git log --oneline`:
  - `03076ad feat(05-01): 7 Phase-5 migrations + config/competitor.php + additive model casts` FOUND
  - `7068550 feat(05-01): 5 Competitor models + 5 policies + 5 factories + 5 Pest tests + AppServiceProvider wiring` FOUND
- `php artisan migrate --env=testing --force` — all 7 new migrations applied; `Schema::hasColumn(alert_recipients, receives_competitor_alerts)` = true; `Schema::hasColumn(products, last_sales_count_90d)` = true
- `php artisan tinker --env=testing --execute="echo config('competitor.min_margin_floor_bps');"` → `500`
- `vendor/bin/pest tests/Feature/Competitor/` — 27 passed, 0 failed, 99 assertions
- `vendor/bin/pest tests/Architecture/` — 18 passed, 0 failed (PolicyTemplateIntegrityTest green at floor=21)
- `vendor/bin/pest` (full suite) — 623 passed, 2 skipped (same 2 pre-existing skips), 0 failed, 5523 assertions
- `vendor/bin/deptrac analyse --no-progress` — 0 violations, 0 warnings, 0 errors
- `grep -rn '{{ ' app/Domain/Competitor/Policies/` → 0 matches

---

*Phase: 05-competitor-analysis*
*Plan: 01-data-model-admin-crud*
*Completed: 2026-04-19*
