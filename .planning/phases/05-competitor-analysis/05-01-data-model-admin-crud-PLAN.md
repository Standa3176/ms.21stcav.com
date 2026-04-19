---
phase: 05-competitor-analysis
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_04_21_090000_create_competitors_table.php
  - database/migrations/2026_04_21_090100_create_competitor_csv_mappings_table.php
  - database/migrations/2026_04_21_090200_create_competitor_ingest_runs_table.php
  - database/migrations/2026_04_21_090300_create_competitor_prices_table.php
  - database/migrations/2026_04_21_090400_create_csv_parse_errors_table.php
  - database/migrations/2026_04_21_090500_add_receives_competitor_alerts_to_alert_recipients.php
  - database/migrations/2026_04_21_090600_add_sales_count_90d_to_products.php
  - config/competitor.php
  - app/Domain/Competitor/Models/Competitor.php
  - app/Domain/Competitor/Models/CompetitorPrice.php
  - app/Domain/Competitor/Models/CompetitorCsvMapping.php
  - app/Domain/Competitor/Models/CompetitorIngestRun.php
  - app/Domain/Competitor/Models/CsvParseError.php
  - app/Domain/Competitor/Policies/CompetitorPolicy.php
  - app/Domain/Competitor/Policies/CompetitorPricePolicy.php
  - app/Domain/Competitor/Policies/CompetitorCsvMappingPolicy.php
  - app/Domain/Competitor/Policies/CompetitorIngestRunPolicy.php
  - app/Domain/Competitor/Policies/CsvParseErrorPolicy.php
  - app/Domain/Alerting/Models/AlertRecipient.php
  - app/Domain/Products/Models/Product.php
  - database/factories/CompetitorFactory.php
  - database/factories/CompetitorPriceFactory.php
  - database/factories/CompetitorCsvMappingFactory.php
  - database/factories/CompetitorIngestRunFactory.php
  - database/factories/CsvParseErrorFactory.php
  - app/Providers/AuthServiceProvider.php
  - tests/Feature/Competitor/CompetitorModelTest.php
  - tests/Feature/Competitor/CompetitorPriceModelTest.php
  - tests/Feature/Competitor/CompetitorIngestRunModelTest.php
  - tests/Feature/Competitor/AlertRecipientReceivesCompetitorAlertsTest.php
  - tests/Feature/Competitor/ProductSalesCountColumnTest.php
autonomous: true
requirements:
  - COMP-07

must_haves:
  truths:
    - "`Competitor::factory()->create(['slug' => 'acme', 'status' => 'active'])` persists; a second insert with identical slug raises UniqueConstraintViolationException (slug unique index enforced)"
    - "`CompetitorPrice::factory()->create(['competitor_id' => $c->id, 'sku' => 'X', 'recorded_at' => today()])->save()` succeeds; a second insert with identical (competitor_id, sku, recorded_at) raises UniqueConstraintViolationException (COMP-07 dedup guarantee)"
    - "`CompetitorCsvMapping::factory()->create(['competitor_id' => $c->id])` persists; a second insert with same competitor_id raises UniqueConstraintViolationException (one mapping per competitor per D-03)"
    - "`CompetitorIngestRun::factory()->create()->correlation_id` returns a 36-char string (UUID shape preserved through factory)"
    - "`CsvParseError::factory()->create(['issue_type' => 'ambiguous_mapping'])` persists; inserting `issue_type = 'not_a_valid_enum'` raises QueryException (enum constraint enforced)"
    - "`AlertRecipient::create(['email' => 'x@y.z', 'name' => 'x', 'receives_competitor_alerts' => true])->fresh()->receives_competitor_alerts === true`; default value is false when omitted"
    - "`Product::factory()->create(['last_sales_count_90d' => 42])->fresh()->last_sales_count_90d === 42`; `Product::factory()->create(['last_sales_count_computed_at' => now()])->fresh()->last_sales_count_computed_at instanceof \Carbon\CarbonInterface`"
    - "`config('competitor.margin_delta_threshold_bps') === 800 && config('competitor.min_margin_floor_bps') === 500 && config('competitor.sales_threshold_90d') === 10 && config('competitor.consecutive_scrapes_required') === 3 && config('competitor.beat_by_pennies') === 1 && config('competitor.csv_retention_days') === 90 && config('competitor.stale_feed_hours') === 48`"
    - "`Gate::forUser($admin)->allows('viewAny', Competitor::class) === true` AND `Gate::forUser($readOnly)->allows('create', Competitor::class) === false` AND `Gate::forUser($pricingManager)->allows('update', $csvMapping) === true` (D-04 pricing_manager resolves quarantined mappings)"
    - "`$competitorPrice->competitor` returns a Competitor instance (belongsTo); `$competitorPrice->ingestRun` returns a CompetitorIngestRun instance (belongsTo); `$competitor->prices` returns a Collection of CompetitorPrice (hasMany)"
  artifacts:
    - path: "database/migrations/2026_04_21_090000_create_competitors_table.php"
      provides: "competitors(id, slug unique, name, website_url nullable, map_policy_notes text nullable, status enum pending|active|inactive, is_active bool default true, last_ingest_at timestamp nullable, timestamps)"
    - path: "database/migrations/2026_04_21_090300_create_competitor_prices_table.php"
      provides: "competitor_prices(id, competitor_id FK, sku, mpn nullable, price_pennies_ex_vat int, price_pennies_gross int, recorded_at timestamp, ingest_run_id FK nullable, timestamps) + unique(competitor_id, sku, recorded_at) + index(sku)"
    - path: "database/migrations/2026_04_21_090100_create_competitor_csv_mappings_table.php"
      provides: "competitor_csv_mappings(id, competitor_id FK unique, sku_column_index int, price_column_index int, decimal_format enum dot|comma default dot, detected_at timestamp, timestamps)"
    - path: "database/migrations/2026_04_21_090200_create_competitor_ingest_runs_table.php"
      provides: "competitor_ingest_runs(id, competitor_id FK, filename, rows_total int, rows_written int, rows_errored int, rows_orphaned int, status enum started|completed|failed, started_at, completed_at nullable, correlation_id varchar(36), timestamps)"
    - path: "database/migrations/2026_04_21_090400_create_csv_parse_errors_table.php"
      provides: "csv_parse_errors(id, ingest_run_id FK nullable, competitor_id FK nullable, filename, issue_type enum ambiguous_mapping|encoding_failure|unparseable_price|invalid_sku_format|invalid_filename|orphan_sku, line_number int nullable, raw_line text nullable, context json nullable, resolved_at timestamp nullable, timestamps)"
    - path: "config/competitor.php"
      provides: "Centralised thresholds + retention + beat-by-pennies config"
      contains: "min_margin_floor_bps"
    - path: "app/Domain/Competitor/Models/Competitor.php"
      provides: "Competitor Eloquent with LogsActivity + HasFactory + policy binding"
      contains: "LogsActivity"
  key_links:
    - from: "app/Providers/AuthServiceProvider.php"
      to: "app/Domain/Competitor/Policies/"
      via: "$policies array registration"
      pattern: "Competitor::class\\s*=>\\s*CompetitorPolicy::class"
    - from: "app/Domain/Competitor/Models/CompetitorPrice.php"
      to: "app/Domain/Competitor/Models/Competitor.php"
      via: "belongsTo relationship"
      pattern: "belongsTo\\(Competitor::class\\)"
    - from: "app/Domain/Competitor/Models/CompetitorPrice.php"
      to: "app/Domain/Competitor/Models/CompetitorIngestRun.php"
      via: "belongsTo relationship"
      pattern: "belongsTo\\(CompetitorIngestRun::class"
---

<objective>
Foundation-only plan: ship the 5 Competitor domain tables + 2 additive columns on existing tables + config/competitor.php + 5 Eloquent models + 5 factories + 5 policies. NO ingest logic, NO Filament resources, NO analyser code in this plan. Downstream plans (05-02..05-05) consume the schema, not construct it.

Purpose: Every Phase 5 requirement either writes to one of these tables (COMP-05 csv_parse_errors, COMP-07 competitor_prices, COMP-09 suggestions payload references) or reads from them (COMP-08 margin thresholds, COMP-10 trend charts). Data model MUST land first so later plans can run in parallel against stable schema.

Output: 7 migrations + 5 models + 5 policies + 5 factories + config file. Tests: 5 factory smoke tests asserting every model saves without exception, plus 2 schema assertion tests for the additive columns.
</objective>

<execution_context>
@C:/Users/sonny.tanda/.claude/get-shit-done/workflows/execute-plan.md
@C:/Users/sonny.tanda/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/REQUIREMENTS.md
@.planning/phases/05-competitor-analysis/05-CONTEXT.md
@.planning/phases/05-competitor-analysis/05-RESEARCH.md

# Prior-phase patterns to replicate (Phase 2 data-model shape; Phase 4 additive boolean)
@.planning/phases/02-supplier-sync/02-01-SUMMARY.md
@.planning/phases/04-bitrix24-crm-sync/04-01-SUMMARY.md

# Existing files Phase 5 extends (read to preserve existing column order / casts)
@app/Domain/Alerting/Models/AlertRecipient.php
@app/Domain/Products/Models/Product.php
@app/Providers/AuthServiceProvider.php
@app/Domain/Foundation/Models/HasCorrelationId.php

<interfaces>
<!-- Phase 1–4 contracts the new models extend. Executor uses these verbatim. -->

From app/Domain/Foundation/Events/DomainEvent.php (Phase 1 Plan 03):
```php
abstract class DomainEvent implements ShouldDispatchAfterCommit
{
    public readonly string $correlationId;
    public function __construct() { $this->correlationId = Context::get('correlation_id') ?? Str::uuid()->toString(); }
}
```

From app/Domain/Alerting/Models/AlertRecipient.php (Phase 1 Plan 05 / Phase 2 D-08 / Phase 4 D-12 pattern):
```php
// Existing columns include: id, email, name, receives_sync_reports, receives_crm_alerts, is_active, timestamps.
// Phase 5 adds: receives_competitor_alerts bool default false AFTER receives_crm_alerts.
```

From app/Domain/Products/Models/Product.php (Phase 2 Plan 01):
```php
// Existing columns include: id, sku, name, brand_id, category_id, supplier_price_pennies, sell_price_pennies, ...
// Phase 5 adds: last_sales_count_90d unsigned int nullable, last_sales_count_computed_at timestamp nullable.
```

Shield permission name pattern (Phase 1 Plan 02 — enforced):
- Format: `{action}_{resource_snake_singular}` underscore separator (NOT `::`)
- Examples: `view_any_competitor`, `update_competitor_csv_mapping`, `delete_competitor_ingest_run`
- RolePermissionSeeder already uses LIKE patterns (`%_competitor`, `%_competitor_price`, `%_competitor_csv_mapping`, `%_competitor_ingest_run`, `%_csv_parse_error`) — seeder update is in 05-04, NOT this plan.
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Migrations + config/competitor.php (7 migrations shipped together)</name>
  <read_first>
    - @.planning/phases/05-competitor-analysis/05-CONTEXT.md §D-02 §Claude's Discretion "Competitor schema"
    - @.planning/phases/05-competitor-analysis/05-RESEARCH.md §3 (products sales count columns) §10 (alert_recipients column) §12 (plan 05-01 scope)
    - @.planning/phases/02-supplier-sync/02-01-SUMMARY.md (SyncRun shape — competitor_ingest_runs mirrors it)
    - @.planning/phases/04-bitrix24-crm-sync/04-03-SUMMARY.md (`add_receives_crm_alerts_to_alert_recipients` migration shape — Phase 5 mirrors for competitor alerts)
    - @database/migrations/ — pick next free timestamp after 2026_04_20_* (Phase 4) — starts 2026_04_21_090000
    - @app/Domain/Alerting/Models/AlertRecipient.php — confirm current $fillable + $casts for additive column
    - @app/Domain/Products/Models/Product.php — confirm current $fillable + $casts for additive columns
  </read_first>
  <behavior>
    - Test: database/migrations exist and `php artisan migrate --pretend --path=database/migrations/2026_04_21_*` emits SQL for all 7 migrations without errors
    - Test: running migrations creates tables with correct columns, indexes, and foreign keys
    - Test: `config('competitor.margin_delta_threshold_bps')` returns 800
    - Test: `config('competitor.min_margin_floor_bps')` returns 500
    - Test: `config('competitor.sales_threshold_90d')` returns 10
    - Test: `config('competitor.consecutive_scrapes_required')` returns 3
    - Test: `config('competitor.beat_by_pennies')` returns 1
    - Test: `config('competitor.csv_retention_days')` returns 90
    - Test: `config('competitor.stale_feed_hours')` returns 48
    - Test: unique index `(competitor_id, sku, recorded_at)` on competitor_prices rejects same-day duplicate INSERT
    - Test: `competitor_csv_mappings.competitor_id` has unique index (one row per competitor)
  </behavior>
  <action>
Create 7 migration files with timestamps starting `2026_04_21_090000` (Phase 4 used 2026_04_20_*):

1. **`2026_04_21_090000_create_competitors_table.php`**
   ```
   id (bigIncrements), slug (string 64, unique), name (string 255),
   website_url (string 255, nullable), map_policy_notes (text, nullable),
   status (enum: ['pending','active','inactive'], default 'pending'),
   is_active (boolean, default true),
   last_ingest_at (timestamp, nullable),
   timestamps()
   ```
   Add index on `status` (stale-feed query filters on it).

2. **`2026_04_21_090100_create_competitor_csv_mappings_table.php`**
   ```
   id, competitor_id (foreignId, constrained competitors, cascadeOnDelete, unique),
   sku_column_index (unsignedSmallInteger),
   price_column_index (unsignedSmallInteger),
   decimal_format (enum: ['dot','comma'], default 'dot'),
   detected_at (timestamp),
   timestamps()
   ```
   Unique index enforces "one mapping per competitor" per D-03.

3. **`2026_04_21_090200_create_competitor_ingest_runs_table.php`** (mirrors Phase 2 sync_runs shape)
   ```
   id, competitor_id (foreignId, constrained, nullable), filename (string 255),
   rows_total (unsignedInteger default 0),
   rows_written (unsignedInteger default 0),
   rows_errored (unsignedInteger default 0),
   rows_orphaned (unsignedInteger default 0),
   status (enum: ['started','completed','failed'], default 'started'),
   started_at (timestamp), completed_at (timestamp, nullable),
   correlation_id (string 36, indexed),
   error_message (text, nullable),
   timestamps()
   ```
   Index: `(competitor_id, started_at)` for per-competitor run history.

4. **`2026_04_21_090300_create_competitor_prices_table.php`**
   ```
   id (bigIncrements),
   competitor_id (foreignId, constrained, cascadeOnDelete),
   sku (string 128), mpn (string 128, nullable),
   price_pennies_ex_vat (integer),
   price_pennies_gross (integer),
   recorded_at (timestamp),
   ingest_run_id (foreignId, constrained competitor_ingest_runs, nullable, cascadeOnDelete),
   timestamps()
   ```
   Indexes:
   - **unique (competitor_id, sku, recorded_at)** — COMP-07 dedup
   - (sku) — orphan detection lookups
   - (competitor_id, recorded_at) — trend chart queries
   - (recorded_at) — stale-feed + retention

5. **`2026_04_21_090400_create_csv_parse_errors_table.php`**
   ```
   id, ingest_run_id (foreignId, constrained, nullable, nullOnDelete),
   competitor_id (foreignId, constrained, nullable, nullOnDelete),
   filename (string 255),
   issue_type (enum: ['ambiguous_mapping','encoding_failure','unparseable_price','invalid_sku_format','invalid_filename','orphan_sku']),
   line_number (unsignedInteger, nullable),
   raw_line (text, nullable),
   context (json, nullable),
   resolved_at (timestamp, nullable),
   timestamps()
   ```
   Index: `(issue_type, resolved_at)` for the Filament Ingest Issues page tabs (Plan 05-04).

6. **`2026_04_21_090500_add_receives_competitor_alerts_to_alert_recipients.php`**
   ```php
   Schema::table('alert_recipients', function (Blueprint $table) {
       $table->boolean('receives_competitor_alerts')->default(false)->after('receives_crm_alerts');
   });
   ```
   Down: `$table->dropColumn('receives_competitor_alerts');`

7. **`2026_04_21_090600_add_sales_count_90d_to_products.php`**
   ```php
   Schema::table('products', function (Blueprint $table) {
       $table->unsignedInteger('last_sales_count_90d')->nullable()->after('sell_price_pennies');
       $table->timestamp('last_sales_count_computed_at')->nullable()->after('last_sales_count_90d');
   });
   ```

**`config/competitor.php`** — create new file:
```php
<?php

return [
    'margin_delta_threshold_bps' => env('COMPETITOR_MARGIN_DELTA_BPS', 800),    // 8% in basis points
    'consecutive_scrapes_required' => env('COMPETITOR_SCRAPES_REQUIRED', 3),
    'sales_threshold_90d' => env('COMPETITOR_SALES_THRESHOLD_90D', 10),
    'min_margin_floor_bps' => env('COMPETITOR_MIN_MARGIN_FLOOR_BPS', 500),      // P5-E guard (5%)
    'beat_by_pennies' => env('COMPETITOR_BEAT_BY_PENNIES', 1),                  // default: 1p lower than competitor
    'csv_retention_days' => env('COMPETITOR_CSV_RETENTION_DAYS', 90),
    'stale_feed_hours' => env('COMPETITOR_STALE_FEED_HOURS', 48),
    'csv_chunk_size' => env('COMPETITOR_CSV_CHUNK_SIZE', 100),
    'filename_regex' => '/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/',
];
```

Update AlertRecipient model `$fillable` to include `receives_competitor_alerts`; `$casts` to add `'receives_competitor_alerts' => 'boolean'`.
Update Product model `$fillable` to add `last_sales_count_90d`, `last_sales_count_computed_at`; `$casts` to add `'last_sales_count_90d' => 'int'`, `'last_sales_count_computed_at' => 'datetime'`.

Run `php artisan migrate` on the `meetingstore_ops_testing` database (Phase 1 P03 pattern — MySQL not sqlite) to confirm schema applies clean.

Run `php artisan config:clear` after writing config/competitor.php.
  </action>
  <verify>
    <automated>php artisan migrate:fresh --env=testing --seed --force && php artisan tinker --env=testing --execute="echo Schema::hasColumn('alert_recipients','receives_competitor_alerts') ? '1' : '0';" | grep -q 1 && php artisan tinker --env=testing --execute="echo config('competitor.min_margin_floor_bps');" | grep -q 500</automated>
  </verify>
  <done>All 7 migrations apply clean on `meetingstore_ops_testing`; config/competitor.php returns expected values via `config()` helper; `Schema::hasColumn()` confirms additive columns exist on alert_recipients + products; unique index on competitor_prices rejects duplicate (competitor_id, sku, recorded_at) inserts.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Models + Policies + Factories + AuthServiceProvider wiring</name>
  <read_first>
    - @app/Domain/Suggestions/Models/Suggestion.php (Phase 1 Plan 04 — example of LogsActivity + casts + ULID)
    - @app/Domain/Pricing/Models/PricingRule.php (Phase 3 Plan 01 — example of Phase-3-era model with policy + factory)
    - @app/Domain/CRM/Models/BitrixEntityMap.php (Phase 4 Plan 01 — most recent model shape)
    - @app/Domain/CRM/Policies/BitrixEntityMapPolicy.php (Phase 4 — most recent policy pattern, gated to admin via Shield permissions)
    - @app/Domain/Sync/Policies/SyncRunPolicy.php (Phase 2 — mirrors what CompetitorIngestRunPolicy must look like: admin + pricing_manager view)
    - @database/factories/ProductFactory.php (Phase 2 Plan 01 — factory conventions)
    - @app/Providers/AuthServiceProvider.php — current `$policies` array entries (must not clobber existing; append 5 new)
    - @.planning/phases/02-supplier-sync/02-05-SUMMARY.md — PolicyTemplateIntegrityTest expectations (NO `{{ ` literal strings in any policy)
  </read_first>
  <behavior>
    - Test: `Competitor::factory()->create()` returns a persisted row with non-null slug + name + status='pending'
    - Test: `CompetitorPrice::factory()->for(Competitor::factory())->for(CompetitorIngestRun::factory(), 'ingestRun')->create()` persists with FK integrity
    - Test: `CompetitorCsvMapping::factory()->for(Competitor::factory())->create()` persists with unique (competitor_id) constraint preserved
    - Test: `CompetitorIngestRun::factory()->create()` persists with correlation_id as 36-char UUID
    - Test: `CsvParseError::factory()->create(['issue_type' => 'ambiguous_mapping'])` persists
    - Test: `$admin->can('viewAny', Competitor::class)` returns true (admin has permission via Shield `view_any_competitor`)
    - Test: `$readOnly->can('create', Competitor::class)` returns false (read_only role lacks create permission)
    - Test: `$pricingManager->can('update', $csvMapping)` returns true (pricing_manager CAN resolve quarantined mappings per D-04)
    - Test: `grep -rn "{{ " app/Domain/Competitor/Policies/` returns zero matches (Pitfall P5-F guard — no template literal strings)
  </behavior>
  <action>
**Models (all 5 under `app/Domain/Competitor/Models/`):**

- **Competitor.php** — `HasFactory`, `LogsActivity` trait (`->logAll()->logOnlyDirty()->dontSubmitEmptyLogs()` per Phase 1 pattern), `$fillable = ['slug','name','website_url','map_policy_notes','status','is_active','last_ingest_at']`, `$casts = ['is_active' => 'boolean', 'last_ingest_at' => 'datetime']`, relationships: `prices()` → hasMany(CompetitorPrice), `ingestRuns()` → hasMany(CompetitorIngestRun), `csvMapping()` → hasOne(CompetitorCsvMapping).
  - Status constants: `const STATUS_PENDING = 'pending'; const STATUS_ACTIVE = 'active'; const STATUS_INACTIVE = 'inactive';`
  - Helper: `isActive(): bool { return $this->status === self::STATUS_ACTIVE && $this->is_active; }`

- **CompetitorPrice.php** — `HasFactory`. NO LogsActivity (high-volume writes — would bloat audit_log; Phase 2 ProductVariant established this precedent). `$fillable = ['competitor_id','sku','mpn','price_pennies_ex_vat','price_pennies_gross','recorded_at','ingest_run_id']`, `$casts = ['price_pennies_ex_vat' => 'int', 'price_pennies_gross' => 'int', 'recorded_at' => 'datetime']`. Relationships: `competitor()` → belongsTo, `ingestRun()` → belongsTo(CompetitorIngestRun).

- **CompetitorCsvMapping.php** — `HasFactory`, `LogsActivity`. `$fillable = ['competitor_id','sku_column_index','price_column_index','decimal_format','detected_at']`, `$casts = ['sku_column_index' => 'int', 'price_column_index' => 'int', 'detected_at' => 'datetime']`. Relationships: `competitor()` → belongsTo. Decimal-format constants: `const FORMAT_DOT = 'dot'; const FORMAT_COMMA = 'comma';`

- **CompetitorIngestRun.php** — `HasFactory`, `LogsActivity`. `$fillable = ['competitor_id','filename','rows_total','rows_written','rows_errored','rows_orphaned','status','started_at','completed_at','correlation_id','error_message']`, `$casts = ['started_at' => 'datetime', 'completed_at' => 'datetime']`. Relationships: `competitor()` → belongsTo, `prices()` → hasMany(CompetitorPrice, 'ingest_run_id'), `parseErrors()` → hasMany(CsvParseError, 'ingest_run_id'). Status constants mirror Phase 2 SyncRun: `STATUS_STARTED`, `STATUS_COMPLETED`, `STATUS_FAILED`.

- **CsvParseError.php** — `HasFactory`. NO LogsActivity. `$fillable = ['ingest_run_id','competitor_id','filename','issue_type','line_number','raw_line','context','resolved_at']`, `$casts = ['context' => 'array', 'line_number' => 'int', 'resolved_at' => 'datetime']`. Issue type constants: `TYPE_AMBIGUOUS_MAPPING`, `TYPE_ENCODING_FAILURE`, `TYPE_UNPARSEABLE_PRICE`, `TYPE_INVALID_SKU_FORMAT`, `TYPE_INVALID_FILENAME`, `TYPE_ORPHAN_SKU`. Helper: `isResolved(): bool { return $this->resolved_at !== null; }`.

**Policies (all 5 under `app/Domain/Competitor/Policies/`):**

Follow the Phase 4 `BitrixEntityMapPolicy` shape. Each policy has: `before`, `viewAny`, `view`, `create`, `update`, `delete`. Uses `$user->can('{action}_{resource_snake_singular}')` checks against Shield-generated permissions.

- **CompetitorPolicy** — viewAny/view allow admin + pricing_manager; create/update/delete admin only
- **CompetitorPricePolicy** — viewAny/view allow admin + pricing_manager + sales; create/update/delete NONE (read-only from UI — ingest jobs write directly without policy check)
- **CompetitorCsvMappingPolicy** — viewAny/view/update allow admin + pricing_manager (D-04: pricing_manager resolves quarantined mappings); create NONE (auto-created by ingest); delete admin only
- **CompetitorIngestRunPolicy** — viewAny/view allow admin + pricing_manager + sales; create/update/delete NONE (runs are append-only from jobs)
- **CsvParseErrorPolicy** — viewAny/view allow admin + pricing_manager; update (mark resolved) allow admin + pricing_manager; delete admin only

**CRITICAL**: No `{{ Placeholder }}` literal strings anywhere. Write policies BY HAND — do NOT run `shield:generate` in this plan (that's deferred to Plan 05-04 which has the restoration protocol per Pitfall P5-F).

**Factories (all 5 under `database/factories/`):**

- `CompetitorFactory` — `slug` = `fake()->unique()->slug(2)` lowercased, `name` = `fake()->company()`, `status` = `Competitor::STATUS_ACTIVE`, `is_active` = true, `last_ingest_at` = `now()->subHours(rand(1,47))`.
- `CompetitorPriceFactory` — `competitor_id` via `Competitor::factory()`, `sku` = `fake()->unique()->bothify('SKU-####-???')`, `price_pennies_ex_vat` = `rand(1000, 200000)`, `price_pennies_gross` = computed as `ex_vat * 1.2` rounded, `recorded_at` = `now()`, `ingest_run_id` = null. **Override unique: use `recyclable sku within competitor ID`** so factory can produce multiple prices per SKU across dates (trend testing).
- `CompetitorCsvMappingFactory` — `sku_column_index` = 0, `price_column_index` = 1, `decimal_format` = 'dot', `detected_at` = `now()`.
- `CompetitorIngestRunFactory` — `filename` = `fake()->regexify('[a-z]{5}_2026-04-\\d{2}.csv')`, `rows_total/written/errored/orphaned` = `0`, `status` = STARTED, `started_at` = `now()`, `correlation_id` = `Str::uuid()->toString()` (36 chars — critical per Phase 2 Plan 02 lesson).
- `CsvParseErrorFactory` — `filename` = fake string, `issue_type` = `CsvParseError::TYPE_UNPARSEABLE_PRICE`, `line_number` = `rand(1, 100)`, `raw_line` = `fake()->sentence()`, `context` = `[]`.

**Register policies in `app/Providers/AuthServiceProvider.php`** — APPEND to existing `$policies` array (do NOT rewrite). Add 5 lines:
```php
\App\Domain\Competitor\Models\Competitor::class => \App\Domain\Competitor\Policies\CompetitorPolicy::class,
\App\Domain\Competitor\Models\CompetitorPrice::class => \App\Domain\Competitor\Policies\CompetitorPricePolicy::class,
\App\Domain\Competitor\Models\CompetitorCsvMapping::class => \App\Domain\Competitor\Policies\CompetitorCsvMappingPolicy::class,
\App\Domain\Competitor\Models\CompetitorIngestRun::class => \App\Domain\Competitor\Policies\CompetitorIngestRunPolicy::class,
\App\Domain\Competitor\Models\CsvParseError::class => \App\Domain\Competitor\Policies\CsvParseErrorPolicy::class,
```

**Feature tests** (`tests/Feature/Competitor/`):
- `CompetitorModelTest` — factory smoke + status state helpers + relationships
- `CompetitorPriceModelTest` — factory smoke + unique index enforcement (assertThrowsOn duplicate insert)
- `CompetitorIngestRunModelTest` — factory smoke + correlation_id is 36 chars
- `AlertRecipientReceivesCompetitorAlertsTest` — asserts `AlertRecipient::factory()->create(['receives_competitor_alerts' => true])->receives_competitor_alerts === true`
- `ProductSalesCountColumnTest` — asserts `Product::factory()->create(['last_sales_count_90d' => 42])->fresh()->last_sales_count_90d === 42`
  </action>
  <verify>
    <automated>php vendor/bin/pest tests/Feature/Competitor/ --stop-on-failure && grep -rn "{{ " app/Domain/Competitor/Policies/ | wc -l | grep -q "^0$"</automated>
  </verify>
  <done>All 5 Competitor models exist with correct traits + fillable + casts + relationships; all 5 policies registered in AuthServiceProvider with admin/pricing_manager gating; all 5 factories produce valid rows; tests/Feature/Competitor/ Pest tests all pass; grep for `{{ ` in policies returns zero (P5-F pre-emptive guard — policies written by hand).</done>
</task>

</tasks>

<verification>
- All 7 migrations apply on `meetingstore_ops_testing` MySQL DB without errors
- `php artisan tinker --env=testing` can instantiate each of the 5 models and save factory instances
- `AuthServiceProvider::$policies` contains all 5 new mappings (grep check)
- `config/competitor.php` returns the 9 configured keys via `config()` helper
- `Competitor.php` model has LogsActivity trait (grep check) — CompetitorPrice.php does NOT (high-volume table)
- Policies contain ZERO `{{ ` literal strings (Pitfall P5-F pre-check)
- PolicyTemplateIntegrityTest floor count bumped to include the new 5 policies
</verification>

<success_criteria>
- `tests/Feature/Competitor/` directory has 5 passing test files
- `php artisan migrate:fresh --env=testing --seed --force` runs clean
- `php artisan config:clear && php artisan tinker --execute="dd(config('competitor'));"` shows all 9 keys
- Zero regressions in Phase 1–4 test suites (`php vendor/bin/pest --exclude tests/Feature/Competitor` still green)
- `grep -rn "{{ " app/Domain/Competitor/Policies/ | wc -l` outputs `0`
- `php artisan tinker --execute="echo \App\Domain\Competitor\Models\Competitor::STATUS_PENDING;"` outputs `pending`
</success_criteria>

<output>
After completion, create `.planning/phases/05-competitor-analysis/05-01-SUMMARY.md` documenting:
- Exact migration timestamps shipped (for downstream plans to reference)
- Any deviations from the schema spec in CONTEXT (e.g., if an enum value changed)
- Factory recyclable configuration choices (for Plan 05-02 orphan-test fixtures)
- Confirmation the 7 migrations run on `meetingstore_ops_testing` clean
- Any policy hand-write decisions worth replicating in future phases
</output>