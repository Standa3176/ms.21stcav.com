---
phase: 02-supplier-sync
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_04_18_200000_create_products_table.php
  - database/migrations/2026_04_18_200100_create_product_variants_table.php
  - database/migrations/2026_04_18_200200_create_sync_runs_table.php
  - database/migrations/2026_04_18_200300_create_sync_errors_table.php
  - database/migrations/2026_04_18_200400_create_import_issues_table.php
  - database/migrations/2026_04_18_200500_create_sync_run_items_table.php
  - app/Domain/Products/Models/Product.php
  - app/Domain/Products/Models/ProductVariant.php
  - app/Domain/Products/Observers/ProductVariantObserver.php
  - app/Domain/Products/Policies/ProductPolicy.php
  - app/Domain/Products/Policies/ProductVariantPolicy.php
  - app/Domain/Sync/Models/SyncRun.php
  - app/Domain/Sync/Models/SyncError.php
  - app/Domain/Sync/Models/SyncRunItem.php
  - app/Domain/Sync/Models/ImportIssue.php
  - app/Domain/Sync/Policies/SyncRunPolicy.php
  - app/Domain/Sync/Policies/ImportIssuePolicy.php
  - database/factories/Domain/Products/ProductFactory.php
  - database/factories/Domain/Products/ProductVariantFactory.php
  - database/factories/Domain/Sync/SyncRunFactory.php
  - database/factories/Domain/Sync/SyncErrorFactory.php
  - database/factories/Domain/Sync/ImportIssueFactory.php
  - database/factories/Domain/Sync/SyncRunItemFactory.php
  - app/Providers/AppServiceProvider.php
  - tests/Feature/Phase02DataModelTest.php
autonomous: true
requirements:
  - SYNC-03
  - SYNC-05
  - SYNC-06
  - SYNC-09
  - SYNC-12

must_haves:
  truths:
    - "Product + ProductVariant models support D-01 mixed simple/variable catalogue (parent-child via hasMany)"
    - "SyncRun model exposes a state machine (queued/running/completed/aborted/failed) with cursor_page + cursor_sku columns for SYNC-03 resume"
    - "ImportIssue model backs SYNC-12 catalogue-health view and D-09 unknown-SKU logging"
    - "SyncRunItem is the denormalised append-only per-SKU log that feeds the D-10 11-column CSV report"
    - "Every new model has a factory so downstream plans can write feature tests without raw SQL"
  artifacts:
    - path: "database/migrations/2026_04_18_200000_create_products_table.php"
      provides: "Woo-product mirror table with type/status/buy_price/sell_price/tags and last_synced_at"
    - path: "database/migrations/2026_04_18_200100_create_product_variants_table.php"
      provides: "Variation-level rows with unique woo_variation_id + unique sku + old_* delta columns"
    - path: "database/migrations/2026_04_18_200200_create_sync_runs_table.php"
      provides: "SyncRun table with 5-state enum, dry_run flag, aggregate counters, cursor_page + cursor_sku"
    - path: "database/migrations/2026_04_18_200300_create_sync_errors_table.php"
      provides: "Per-item failure log with FK sync_run_id + sku + woo_* ids + error_class/error_message + correlation_id"
    - path: "database/migrations/2026_04_18_200400_create_import_issues_table.php"
      provides: "Catalogue-health table with issue_type enum and resolved_at nullable (SYNC-12 + D-09)"
    - path: "database/migrations/2026_04_18_200500_create_sync_run_items_table.php"
      provides: "Append-only per-SKU log (11 CSV columns + sync_run_id FK) — CSV report source"
    - path: "app/Domain/Products/Models/Product.php"
      provides: "Eloquent model with hasMany(variants), LogsActivity, is_custom_ms + exclude_from_auto_update casts"
    - path: "app/Domain/Products/Models/ProductVariant.php"
      provides: "Eloquent model with belongsTo(Product) + observer bumping parent last_synced_at (Pitfall P2-C)"
    - path: "app/Domain/Sync/Models/SyncRun.php"
      provides: "Model with STATUS_* + ABORT_* constants, markRunning/abort/finalise/findResumable methods"
    - path: "app/Domain/Sync/Models/SyncError.php"
      provides: "Append-only Eloquent over sync_errors (no updated_at)"
    - path: "app/Domain/Sync/Models/SyncRunItem.php"
      provides: "Append-only Eloquent over sync_run_items with forRun() scope for CSV generation"
    - path: "app/Domain/Sync/Models/ImportIssue.php"
      provides: "Eloquent with issue_type enum constants + resolved/unresolved scopes"
    - path: "app/Domain/Products/Policies/ProductPolicy.php"
      provides: "admin + pricing_manager edit; sales + read_only view-only (Phase 1 D-02 role split)"
    - path: "app/Domain/Sync/Policies/SyncRunPolicy.php"
      provides: "view for all 4 roles; retry action admin-only via hasRole('admin') (Pitfall K pattern)"
    - path: "app/Domain/Sync/Policies/ImportIssuePolicy.php"
      provides: "admin + pricing_manager edit/resolve; sales + read_only view-only"
  key_links:
    - from: "app/Domain/Products/Models/ProductVariant.php"
      to: "app/Domain/Products/Models/Product.php"
      via: "BelongsTo + cascadeOnDelete FK product_id"
      pattern: "belongsTo\\(Product::class\\)"
    - from: "app/Domain/Products/Observers/ProductVariantObserver.php"
      to: "app/Domain/Products/Models/Product.php"
      via: "saved() → \\$variant->product->touch('last_synced_at')"
      pattern: "->touch\\('last_synced_at'\\)"
    - from: "app/Domain/Sync/Models/SyncRun.php"
      to: "app/Foundation/Audit/Services/Auditor.php"
      via: "markRunning/abort/finalise call Auditor::record('sync.run.{state}')"
      pattern: "Auditor::class.*->record\\('sync\\.run\\."
    - from: "app/Domain/Sync/Models/SyncRun.php"
      to: "app/Domain/Sync/Models/SyncError.php"
      via: "HasMany 'errors' for SYNC-11 RelationManager"
      pattern: "hasMany\\(SyncError::class"
    - from: "app/Domain/Sync/Models/SyncRun.php"
      to: "app/Domain/Sync/Models/SyncRunItem.php"
      via: "HasMany 'items' for SYNC-11 drill-down + CSV source"
      pattern: "hasMany\\(SyncRunItem::class"
---

<objective>
Ship Phase 2's data foundation: 6 migrations + 6 models + 5 policies + 6 factories covering (a) the D-01 variable-product expansion (Product + ProductVariant), (b) the sync pipeline state (SyncRun + SyncError + SyncRunItem), and (c) SYNC-12 catalogue health (ImportIssue). This plan ONLY lays the schema/Eloquent/factory surface — no API clients, no jobs, no orchestrator, no Filament Resources. Downstream Plans 02–05 consume these contracts.

Purpose: Build the schema spine first so Plans 02 (external clients), 03 (orchestration), 04 (reporting + UI), and 05 (guardrails) can all run in parallel against a stable model API. Without this plan, every downstream plan would need to re-derive the column shape and model relationships, cascading errors.

Output: 6 new tables + 6 Eloquent models + 5 policies + 6 factories; `php artisan migrate --force` clean on both dev and testing DBs; 1 feature test proving schema/model contracts.

Scope additions beyond REQUIREMENTS.md (explicit per CONTEXT.md):
- D-01 — `products` + `product_variants` tables (REQUIREMENTS mention SKUs but not Product model)
- D-09 — `import_issues` table
- CSV source — `sync_run_items` append-only log (research §9, option (b))
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/STATE.md
@.planning/phases/02-supplier-sync/02-CONTEXT.md
@.planning/phases/02-supplier-sync/02-RESEARCH.md
@.planning/phases/01-foundation/01-01-SUMMARY.md
@.planning/phases/01-foundation/01-02-SUMMARY.md
@.planning/phases/01-foundation/01-03-SUMMARY.md
@.planning/phases/01-foundation/01-04-SUMMARY.md
@.planning/phases/01-foundation/01-05-SUMMARY.md

<interfaces>
<!-- Phase 1 contracts this plan consumes. Executor must not re-explore. -->

From app/Foundation/Audit/Services/Auditor.php (Phase 1 Plan 03):
```php
final class Auditor
{
    public function record(string $action, array $context = []): void;  // writes to log_name='system' + attaches Context::get('correlation_id')
}
```

From spatie/laravel-activitylog 4.12 (installed):
```php
// Models that need audit use:
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly([...])
        ->logOnlyDirty();
}
```

From Phase 1 migration convention (01-03-SUMMARY + 01-04-SUMMARY + 01-05-SUMMARY):
- Last Phase 1 migration timestamp: 2026_04_18_190000_create_alert_recipients_table.php
- Phase 2 timestamps: START at 2026_04_18_200000 (documented in RESEARCH §Migration Ordering)

From app/Providers/AppServiceProvider.php (Phase 1 Plan 04):
```php
// Gate::policy(Suggestion::class, SuggestionPolicy::class) pattern
// Extend in boot() with 3 new policies: Gate::policy(Product::class, ProductPolicy::class) etc.
```

From Phase 1 D-02 role split (01-02-SUMMARY frontmatter):
- admin: everything
- pricing_manager: %_product, %_product_variant, %_pricing_rule, %_import_issue (edit) + view %_sync_run
- sales: view-only on everything except self-owned data
- read_only: view_any_% / view_% on reports (%_sync_run, etc.)

From Phase 1 Pitfall K pattern (01-02-SUMMARY + 01-04-SUMMARY + 01-05-SUMMARY):
- Policies for admin-only-gated domains use `hasRole('admin')` directly (NOT permission-based)
- Resources shipped by pricing_manager-scoped domains (Product, ImportIssue) use Shield permission patterns
- ALL policies must be manually audited for `{{ Placeholder }}` literals after shield:generate — Plan 05 of this phase ships the permanent PolicyTemplateIntegrityTest guardrail
</interfaces>

</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Ship 6 migrations + 6 factories (D-01 Product+ProductVariant, SyncRun/Error/RunItem, ImportIssue, SYNC-12)</name>
  <files>
    database/migrations/2026_04_18_200000_create_products_table.php,
    database/migrations/2026_04_18_200100_create_product_variants_table.php,
    database/migrations/2026_04_18_200200_create_sync_runs_table.php,
    database/migrations/2026_04_18_200300_create_sync_errors_table.php,
    database/migrations/2026_04_18_200400_create_import_issues_table.php,
    database/migrations/2026_04_18_200500_create_sync_run_items_table.php,
    database/factories/Domain/Products/ProductFactory.php,
    database/factories/Domain/Products/ProductVariantFactory.php,
    database/factories/Domain/Sync/SyncRunFactory.php,
    database/factories/Domain/Sync/SyncErrorFactory.php,
    database/factories/Domain/Sync/ImportIssueFactory.php,
    database/factories/Domain/Sync/SyncRunItemFactory.php,
    tests/Feature/Phase02DataModelTest.php
  </files>
  <read_first>
    - 02-CONTEXT.md — lines 17-56 (D-01 variable products), lines 152-160 (import_issues shape), lines 154-155 (sync_runs enum), RESEARCH §1 Product/ProductVariant schema (lines 487-546), RESEARCH §4 SyncRun state machine (lines 727-756), RESEARCH §9 sync_run_items decision (line 947)
    - 02-RESEARCH.md §Migration Ordering (lines 1121-1139) — exact timestamps
    - 01-05-SUMMARY.md (last Phase 1 migration timestamp = 2026_04_18_190000; Phase 2 starts at 200000)
    - 01-04-SUMMARY.md (sync_diffs migration pattern — timestamps=false append-only)
    - 01-03-SUMMARY.md (integration_events nullableUlidMorphs pattern — not needed here but shows the correlation_id CHAR(36) column convention)
    - database/migrations/2026_04_18_170000_create_integration_events_table.php (correlation_id indexed column pattern)
    - database/migrations/2026_04_18_180200_create_sync_diffs_table.php (column naming conventions to match)
    - .planning/research/PITFALLS.md — Pitfalls 1 (resumable cursor), 17 (variants)
  </read_first>
  <behavior>
    Before writing any migration, add tests to tests/Feature/Phase02DataModelTest.php asserting:
    - Test 1 (products schema): After migrate, `products` table has columns {id, woo_product_id (unsigned BIGINT unique), sku (VARCHAR(100) nullable indexed), name, type (enum: simple/variable/grouped/external indexed default simple), status (enum: publish/pending/draft/private indexed default publish), stock_status (enum: instock/outofstock/onbackorder default instock), buy_price (DECIMAL(12,4) nullable), sell_price (DECIMAL(12,4) nullable), cost_price (DECIMAL(12,4) nullable), is_custom_ms (bool default false indexed), exclude_from_auto_update (bool default false indexed), tags (json nullable), last_synced_at (timestamp nullable indexed), last_sync_run_id (unsignedBigInt nullable), created_at, updated_at, deleted_at}. Assert via `Schema::hasColumn` for each.
    - Test 2 (product_variants schema): Columns {id, product_id (FK constrained cascadeOnDelete), woo_variation_id (unsigned BIGINT unique), sku (VARCHAR(100) unique), name (nullable), buy_price, sell_price, old_buy_price, old_sell_price (all DECIMAL(12,4) nullable), stock_quantity (INT default 0), old_stock_quantity (INT nullable), status (enum publish/private indexed default publish), attributes (json nullable), last_synced_at (timestamp nullable indexed), timestamps}. Assert FK constraint via `DB::select("SHOW CREATE TABLE product_variants")` OR via Eloquent `Product::factory()->create()->variants()->count()` returning 0.
    - Test 3 (sync_runs schema): Columns {id, started_at (indexed), completed_at (nullable), status (enum queued/running/completed/aborted/failed indexed default queued), dry_run (bool default true), total_skus/updated_count/skipped_count/failed_count/missing_count/unknown_sku_count (all INT default 0), abort_reason (enum error_rate/consecutive_failures/jwt_refresh/manual nullable), abort_message (text nullable), cursor_page (INT default 0), cursor_sku (VARCHAR(100) nullable), correlation_id (UUID indexed — use char(36) via $table->uuid), timestamps}.
    - Test 4 (sync_errors schema): Columns {id, sync_run_id (FK constrained), sku, woo_product_id (nullable unsigned BIGINT), woo_variation_id (nullable unsigned BIGINT), error_class (VARCHAR(255)), error_message (TEXT), correlation_id (UUID indexed), created_at}. No updated_at (append-only).
    - Test 5 (import_issues schema): Columns {id, sku (VARCHAR(100) indexed), woo_product_id (nullable), woo_variation_id (nullable), issue_type (enum missing_at_supplier/unknown_sku/missing_cost_price/exclude_flag_no_metadata indexed), detected_at (indexed), last_seen_at, resolved_at (nullable indexed), notes (text nullable), correlation_id (UUID indexed), timestamps}.
    - Test 6 (sync_run_items schema): Columns {id, sync_run_id (FK constrained indexed), sku (VARCHAR(100)), woo_product_id (nullable), woo_variation_id (nullable), action (enum updated/skipped/failed/missing/unknown_sku), reason (VARCHAR(255) nullable), old_price/new_price (VARCHAR(32) nullable — 2dp strings per D-discretion exact match), old_stock/new_stock (INT nullable), error_message (text nullable), correlation_id (UUID indexed), created_at}. No updated_at.
    - Test 7 (factory smoke): Each of the 6 factories produces a valid Eloquent instance that persists (`->create()` returns a model with a non-null id). ProductFactory with `->variable()->hasVariants(3)` produces a parent + 3 ProductVariant children.
    - Test 8 (migration rollback): `php artisan migrate:rollback --step=6` successfully drops all 6 new tables in reverse FK order (sync_run_items → sync_errors → import_issues → sync_runs → product_variants → products); `php artisan migrate` re-runs clean. Exercise via `$this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0)`.
  </behavior>
  <action>
Create 6 migrations at the exact timestamps in <files>. Every migration MUST start with `declare(strict_types=1);` and follow Laravel 12 anonymous class pattern (`return new class extends Migration { ... };`).

**Migration 1 — `2026_04_18_200000_create_products_table.php`:**
```php
Schema::create('products', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('woo_product_id')->unique();
    $t->string('sku', 100)->nullable()->index();
    $t->string('name');
    $t->enum('type', ['simple', 'variable', 'grouped', 'external'])->default('simple')->index();
    $t->enum('status', ['publish', 'pending', 'draft', 'private'])->default('publish')->index();
    $t->enum('stock_status', ['instock', 'outofstock', 'onbackorder'])->default('instock');
    $t->decimal('buy_price', 12, 4)->nullable();
    $t->decimal('sell_price', 12, 4)->nullable();
    $t->decimal('cost_price', 12, 4)->nullable();
    $t->boolean('is_custom_ms')->default(false)->index();
    $t->boolean('exclude_from_auto_update')->default(false)->index();
    $t->json('tags')->nullable();
    $t->timestamp('last_synced_at')->nullable()->index();
    $t->unsignedBigInteger('last_sync_run_id')->nullable();
    $t->timestamps();
    $t->softDeletes();
});
```

**Migration 2 — `2026_04_18_200100_create_product_variants_table.php`:**
```php
Schema::create('product_variants', function (Blueprint $t) {
    $t->id();
    $t->foreignId('product_id')->constrained()->cascadeOnDelete();
    $t->unsignedBigInteger('woo_variation_id')->unique();
    $t->string('sku', 100)->unique();
    $t->string('name')->nullable();
    $t->decimal('buy_price', 12, 4)->nullable();
    $t->decimal('sell_price', 12, 4)->nullable();
    $t->decimal('old_buy_price', 12, 4)->nullable();
    $t->decimal('old_sell_price', 12, 4)->nullable();
    $t->integer('stock_quantity')->default(0);
    $t->integer('old_stock_quantity')->nullable();
    $t->enum('status', ['publish', 'private'])->default('publish')->index();
    $t->json('attributes')->nullable();
    $t->timestamp('last_synced_at')->nullable()->index();
    $t->timestamps();
});
```

**Migration 3 — `2026_04_18_200200_create_sync_runs_table.php`** (per RESEARCH §4):
```php
Schema::create('sync_runs', function (Blueprint $t) {
    $t->id();
    $t->timestamp('started_at')->index();
    $t->timestamp('completed_at')->nullable();
    $t->enum('status', ['queued', 'running', 'completed', 'aborted', 'failed'])
        ->default('queued')->index();
    $t->boolean('dry_run')->default(true);
    $t->integer('total_skus')->default(0);
    $t->integer('updated_count')->default(0);
    $t->integer('skipped_count')->default(0);
    $t->integer('failed_count')->default(0);
    $t->integer('missing_count')->default(0);
    $t->integer('unknown_sku_count')->default(0);
    $t->enum('abort_reason', ['error_rate', 'consecutive_failures', 'jwt_refresh', 'manual'])->nullable();
    $t->text('abort_message')->nullable();
    $t->integer('cursor_page')->default(0);
    $t->string('cursor_sku', 100)->nullable();
    $t->uuid('correlation_id')->index();
    $t->timestamps();
});
```

**Migration 4 — `2026_04_18_200300_create_sync_errors_table.php`** (append-only, no updated_at):
```php
Schema::create('sync_errors', function (Blueprint $t) {
    $t->id();
    $t->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
    $t->string('sku', 100)->index();
    $t->unsignedBigInteger('woo_product_id')->nullable();
    $t->unsignedBigInteger('woo_variation_id')->nullable();
    $t->string('error_class', 255);
    $t->text('error_message');
    $t->uuid('correlation_id')->index();
    $t->timestamp('created_at')->useCurrent();
    // NO updated_at — append-only log
});
```

**Migration 5 — `2026_04_18_200400_create_import_issues_table.php`** (SYNC-12 + D-09):
```php
Schema::create('import_issues', function (Blueprint $t) {
    $t->id();
    $t->string('sku', 100)->index();
    $t->unsignedBigInteger('woo_product_id')->nullable();
    $t->unsignedBigInteger('woo_variation_id')->nullable();
    $t->enum('issue_type', [
        'missing_at_supplier',
        'unknown_sku',
        'missing_cost_price',
        'exclude_flag_no_metadata',
    ])->index();
    $t->timestamp('detected_at')->index();
    $t->timestamp('last_seen_at')->nullable();
    $t->timestamp('resolved_at')->nullable()->index();
    $t->text('notes')->nullable();
    $t->uuid('correlation_id')->index();
    $t->timestamps();
});
```

**Migration 6 — `2026_04_18_200500_create_sync_run_items_table.php`** (CSV source — D-10 11 columns + FK):
```php
Schema::create('sync_run_items', function (Blueprint $t) {
    $t->id();
    $t->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete()->index();
    $t->string('sku', 100);
    $t->unsignedBigInteger('woo_product_id')->nullable();
    $t->unsignedBigInteger('woo_variation_id')->nullable();
    $t->enum('action', ['updated', 'skipped', 'failed', 'missing', 'unknown_sku']);
    $t->string('reason', 255)->nullable();
    $t->string('old_price', 32)->nullable();
    $t->string('new_price', 32)->nullable();
    $t->integer('old_stock')->nullable();
    $t->integer('new_stock')->nullable();
    $t->text('error_message')->nullable();
    $t->uuid('correlation_id')->index();
    $t->timestamp('created_at')->useCurrent();
});
```

**All 6 factories** — put under `database/factories/Domain/Products/` and `database/factories/Domain/Sync/`:

`ProductFactory.php`:
```php
namespace Database\Factories\Domain\Products;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'woo_product_id' => fake()->unique()->numberBetween(1_000, 999_999),
            'sku' => fake()->unique()->bothify('SIMPLE-####'),
            'name' => fake()->words(3, true),
            'type' => 'simple',
            'status' => 'publish',
            'stock_status' => 'instock',
            'buy_price' => fake()->randomFloat(2, 10, 500),
            'sell_price' => fake()->randomFloat(2, 20, 700),
            'is_custom_ms' => false,
            'exclude_from_auto_update' => false,
            'tags' => [],
        ];
    }

    public function variable(): static
    {
        return $this->state(fn () => ['type' => 'variable', 'sku' => null]);
    }

    public function customMs(): static
    {
        return $this->state(fn () => ['is_custom_ms' => true]);
    }
}
```

`ProductVariantFactory.php`:
```php
namespace Database\Factories\Domain\Products;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->variable(),
            'woo_variation_id' => fake()->unique()->numberBetween(10_000, 999_999),
            'sku' => fake()->unique()->bothify('VAR-####-##'),
            'name' => fake()->words(2, true),
            'buy_price' => fake()->randomFloat(2, 10, 500),
            'stock_quantity' => fake()->numberBetween(0, 50),
            'status' => 'publish',
            'attributes' => [['name' => 'Colour', 'option' => fake()->safeColorName()]],
        ];
    }
}
```

`SyncRunFactory.php`:
```php
namespace Database\Factories\Domain\Sync;

use App\Domain\Sync\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class SyncRunFactory extends Factory
{
    protected $model = SyncRun::class;

    public function definition(): array
    {
        return [
            'started_at' => now(),
            'completed_at' => null,
            'status' => SyncRun::STATUS_QUEUED,
            'dry_run' => true,
            'cursor_page' => 0,
            'correlation_id' => fake()->uuid(),
        ];
    }

    public function running(): static { return $this->state(fn () => ['status' => SyncRun::STATUS_RUNNING]); }
    public function aborted(): static { return $this->state(fn () => ['status' => SyncRun::STATUS_ABORTED, 'abort_reason' => 'error_rate', 'completed_at' => now()]); }
    public function completed(): static { return $this->state(fn () => ['status' => SyncRun::STATUS_COMPLETED, 'completed_at' => now()]); }
    public function live(): static { return $this->state(fn () => ['dry_run' => false]); }
}
```

Analogous factories for SyncError, ImportIssue, SyncRunItem — each factory defines a `definition()` returning the minimum valid row, plus an optional state method per enum value where useful.

**Register factories via the HasFactory trait on each model (Task 2 wires this). The factory `::newFactory()` resolver will use the PSR-4-matching path `Database\Factories\Domain\{SubNS}\{Name}Factory`.**

**Write tests/Feature/Phase02DataModelTest.php** containing tests 1-8 per <behavior> block. Use `Schema::hasColumn('products', 'buy_price')` assertions and `$this->artisan('migrate:rollback', ['--step' => 6])->assertExitCode(0)`. Run the test with `vendor/bin/pest --filter=Phase02DataModelTest` — MUST pass before proceeding.

**Migrate BEFORE running Phase 1 tests:**
```bash
php artisan migrate --force --database=mysql
# Also: the testing DB:
DB_DATABASE=meetingstore_ops_testing php artisan migrate --force
```

**DO NOT modify Phase 1 migrations. DO NOT add any `-v3.2+-woocommerce-only` fields — stick to the research schema exactly.** Use "per D-01" / "per D-09" / "per SYNC-03" / "per SYNC-12" inline comments at schema decision points so the checker sees intentional coverage.
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=Phase02DataModelTest</automated>
  </verify>
  <done>
    - 6 new migration files exist at the exact timestamps in <files>
    - `php artisan migrate --force` exits 0 on both dev (meetingstore_ops) and testing (meetingstore_ops_testing) databases
    - `php artisan migrate:rollback --step=6` + `php artisan migrate --force` round-trip clean
    - 6 factory classes exist and `Product::factory()->variable()->hasVariants(3)->create()` produces a parent row + 3 variant rows
    - tests/Feature/Phase02DataModelTest.php contains 8 tests, all green
    - `vendor/bin/pest` full suite ≥ 100 passing (Phase 1's 92 + Phase 2's 8+)
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Ship 6 models (Product, ProductVariant, SyncRun state machine, SyncError, SyncRunItem, ImportIssue) + ProductVariantObserver + 5 policies</name>
  <files>
    app/Domain/Products/Models/Product.php,
    app/Domain/Products/Models/ProductVariant.php,
    app/Domain/Products/Observers/ProductVariantObserver.php,
    app/Domain/Products/Policies/ProductPolicy.php,
    app/Domain/Products/Policies/ProductVariantPolicy.php,
    app/Domain/Sync/Models/SyncRun.php,
    app/Domain/Sync/Models/SyncError.php,
    app/Domain/Sync/Models/SyncRunItem.php,
    app/Domain/Sync/Models/ImportIssue.php,
    app/Domain/Sync/Policies/SyncRunPolicy.php,
    app/Domain/Sync/Policies/ImportIssuePolicy.php,
    app/Providers/AppServiceProvider.php,
    tests/Feature/Phase02DataModelTest.php
  </files>
  <read_first>
    - 02-CONTEXT.md — lines 17-25 (D-01/D-02 type branching), lines 153-160 (models + policy scope per Phase 1 D-02)
    - 02-RESEARCH.md §1 schema (lines 487-547), §4 SyncRun state machine (lines 760-808 — exact method signatures), §12 shield regen warning (lines 1048-1053), Pitfall P2-C (lines 1158-1164 — observer bumping parent last_synced_at), Pitfall P2-H (shield:generate damage)
    - 01-02-SUMMARY.md (Phase 1 D-02 role split + permission LIKE-pattern convention)
    - 01-03-SUMMARY.md (Auditor::record signature, DomainEvent base; SyncRun methods call Auditor)
    - 01-04-SUMMARY.md (SuggestionPolicy hardcoded hasRole('admin') pattern — Pitfall K)
    - 01-05-SUMMARY.md (AlertRecipientPolicy same pattern; AppServiceProvider::boot policy registration pattern)
    - app/Foundation/Audit/Services/Auditor.php (exact record() signature — already loaded in interfaces block)
    - app/Domain/Suggestions/Policies/SuggestionPolicy.php (template for hasRole-gated Policy)
  </read_first>
  <behavior>
    Extend tests/Feature/Phase02DataModelTest.php with:
    - Test 9 (Product relationships): `Product::factory()->variable()->hasVariants(3)->create()` yields a model whose `->variants` returns 3 ProductVariant rows (HasMany) and `$variant->product` returns the parent (BelongsTo).
    - Test 10 (ProductVariantObserver Pitfall P2-C): Given a Product with last_synced_at=null, saving a child ProductVariant (via `$variant->update(['stock_quantity' => 5])`) causes `$product->refresh()->last_synced_at` to be non-null (parent timestamp bumped).
    - Test 11 (SyncRun state machine — markRunning): `SyncRun::factory()->create(['status' => SyncRun::STATUS_QUEUED])->markRunning()` transitions to STATUS_RUNNING AND writes an activity_log row via Auditor with action='sync.run.running' and properties.correlation_id populated.
    - Test 12 (SyncRun state machine — abort): `$run->abort(SyncRun::ABORT_ERROR_RATE, 'test msg')` flips status=aborted, sets abort_reason=error_rate, abort_message='test msg', completed_at=non-null, AND writes activity row 'sync.run.aborted'.
    - Test 13 (SyncRun state machine — finalise): `$run->finalise()` flips to completed + stamps completed_at + Auditor row 'sync.run.completed'.
    - Test 14 (SyncRun resumable scope + findResumable): `SyncRun::factory()->aborted()->create()->id` → `SyncRun::findResumable($id)` returns the model and flips its status back to running.
    - Test 15 (ProductPolicy gates per Phase 1 D-02): admin viewAny=true + update=true; pricing_manager viewAny=true + update=true; sales viewAny=true + update=false; read_only viewAny=true + update=false.
    - Test 16 (SyncRunPolicy): viewAny=true for all 4 roles; only admin can `retry` (custom gate).
    - Test 17 (ImportIssuePolicy): admin + pricing_manager can `resolve`; sales + read_only cannot.
    - Test 18 (AppServiceProvider bindings): After Laravel boot, `Gate::getPolicyFor(Product::class)` returns ProductPolicy instance; `Gate::getPolicyFor(SyncRun::class)` returns SyncRunPolicy; `Gate::getPolicyFor(ImportIssue::class)` returns ImportIssuePolicy.
  </behavior>
  <action>
Build all 6 models + 1 observer + 5 policies.

**app/Domain/Products/Models/Product.php** — Eloquent with HasFactory, LogsActivity, boolean casts, relationship + soft deletes:
```php
namespace App\Domain\Products\Models;

use Database\Factories\Domain\Products\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class Product extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'woo_product_id', 'sku', 'name', 'type', 'status', 'stock_status',
        'buy_price', 'sell_price', 'cost_price',
        'is_custom_ms', 'exclude_from_auto_update', 'tags',
        'last_synced_at', 'last_sync_run_id',
    ];

    protected $casts = [
        'is_custom_ms' => 'bool',
        'exclude_from_auto_update' => 'bool',
        'tags' => 'array',
        'last_synced_at' => 'datetime',
        'buy_price' => 'decimal:4',
        'sell_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sku', 'name', 'type', 'status', 'buy_price', 'sell_price', 'is_custom_ms', 'exclude_from_auto_update'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
```

**app/Domain/Products/Models/ProductVariant.php** — same shape, belongsTo + observer registration:
```php
namespace App\Domain\Products\Models;

use App\Domain\Products\Observers\ProductVariantObserver;
use Database\Factories\Domain\Products\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[\Illuminate\Database\Eloquent\Attributes\ObservedBy([ProductVariantObserver::class])]
final class ProductVariant extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'product_id', 'woo_variation_id', 'sku', 'name',
        'buy_price', 'sell_price', 'old_buy_price', 'old_sell_price',
        'stock_quantity', 'old_stock_quantity',
        'status', 'attributes', 'last_synced_at',
    ];

    protected $casts = [
        'attributes' => 'array',
        'last_synced_at' => 'datetime',
        'buy_price' => 'decimal:4',
        'sell_price' => 'decimal:4',
        'old_buy_price' => 'decimal:4',
        'old_sell_price' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sku', 'buy_price', 'sell_price', 'stock_quantity', 'status'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
```

**app/Domain/Products/Observers/ProductVariantObserver.php** (Pitfall P2-C):
```php
namespace App\Domain\Products\Observers;

use App\Domain\Products\Models\ProductVariant;

final class ProductVariantObserver
{
    /**
     * Pitfall P2-C mitigation — bump parent Product's last_synced_at whenever
     * a child variation is saved so the SyncRunResource drill-down doesn't
     * show "parent last synced 3 days ago" when a variation was touched 5 minutes ago.
     *
     * DO NOT REMOVE — purpose documented in RESEARCH.md §Pitfall P2-C.
     */
    public function saved(ProductVariant $variant): void
    {
        $variant->product?->touch('last_synced_at');
    }
}
```

**app/Domain/Sync/Models/SyncRun.php** — state machine per RESEARCH §4 exact lines 760-808:
```php
namespace App\Domain\Sync\Models;

use App\Domain\Products\Models\Product;
use App\Foundation\Audit\Services\Auditor;
use Database\Factories\Domain\Sync\SyncRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class SyncRun extends Model
{
    use HasFactory, LogsActivity;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_FAILED = 'failed';

    public const ABORT_ERROR_RATE = 'error_rate';
    public const ABORT_CONSECUTIVE = 'consecutive_failures';
    public const ABORT_JWT_REFRESH = 'jwt_refresh';
    public const ABORT_MANUAL = 'manual';

    protected $fillable = [
        'started_at', 'completed_at', 'status', 'dry_run',
        'total_skus', 'updated_count', 'skipped_count', 'failed_count', 'missing_count', 'unknown_sku_count',
        'abort_reason', 'abort_message',
        'cursor_page', 'cursor_sku', 'correlation_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dry_run' => 'bool',
    ];

    public function errors(): HasMany
    {
        return $this->hasMany(SyncError::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncRunItem::class);
    }

    public function markRunning(): void
    {
        $this->update(['status' => self::STATUS_RUNNING]);
        app(Auditor::class)->record('sync.run.running', ['run_id' => $this->id]);
    }

    public function abort(string $reason, ?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_ABORTED,
            'abort_reason' => $reason,
            'abort_message' => $message,
            'completed_at' => now(),
        ]);
        app(Auditor::class)->record('sync.run.aborted', [
            'run_id' => $this->id,
            'reason' => $reason,
            'message' => $message,
        ]);
    }

    public function finalise(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        app(Auditor::class)->record('sync.run.completed', [
            'run_id' => $this->id,
            'stats' => $this->only([
                'total_skus', 'updated_count', 'skipped_count',
                'failed_count', 'missing_count', 'unknown_sku_count',
            ]),
        ]);
    }

    public function scopeResumable(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_ABORTED, self::STATUS_FAILED, self::STATUS_RUNNING]);
    }

    public static function findResumable(int $id): self
    {
        $run = static::query()->resumable()->findOrFail($id);
        $run->update(['status' => self::STATUS_RUNNING]);
        return $run;
    }

    public function incrementCounter(string $action): void
    {
        $column = match ($action) {
            'updated' => 'updated_count',
            'skipped' => 'skipped_count',
            'failed' => 'failed_count',
            'missing' => 'missing_count',
            'unknown_sku' => 'unknown_sku_count',
            default => throw new \InvalidArgumentException("Unknown counter: {$action}"),
        };
        $this->increment($column);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'abort_reason', 'updated_count', 'failed_count'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): SyncRunFactory
    {
        return SyncRunFactory::new();
    }
}
```

**app/Domain/Sync/Models/SyncError.php** — append-only:
```php
namespace App\Domain\Sync\Models;

use Database\Factories\Domain\Sync\SyncErrorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SyncError extends Model
{
    use HasFactory;

    public $timestamps = false;  // created_at only, set by useCurrent() in migration

    protected $fillable = [
        'sync_run_id', 'sku', 'woo_product_id', 'woo_variation_id',
        'error_class', 'error_message', 'correlation_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function run(): BelongsTo { return $this->belongsTo(SyncRun::class, 'sync_run_id'); }

    protected static function newFactory(): SyncErrorFactory { return SyncErrorFactory::new(); }
}
```

**app/Domain/Sync/Models/SyncRunItem.php** — append-only, CSV source:
```php
namespace App\Domain\Sync\Models;

use Database\Factories\Domain\Sync\SyncRunItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SyncRunItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'sync_run_id', 'sku', 'woo_product_id', 'woo_variation_id',
        'action', 'reason', 'old_price', 'new_price', 'old_stock', 'new_stock',
        'error_message', 'correlation_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function run(): BelongsTo { return $this->belongsTo(SyncRun::class, 'sync_run_id'); }

    public function scopeForRun(\Illuminate\Database\Eloquent\Builder $q, int $runId): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('sync_run_id', $runId);
    }

    protected static function newFactory(): SyncRunItemFactory { return SyncRunItemFactory::new(); }
}
```

**app/Domain/Sync/Models/ImportIssue.php** — SYNC-12 + D-09:
```php
namespace App\Domain\Sync\Models;

use Database\Factories\Domain\Sync\ImportIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ImportIssue extends Model
{
    use HasFactory;

    public const TYPE_MISSING_AT_SUPPLIER = 'missing_at_supplier';
    public const TYPE_UNKNOWN_SKU = 'unknown_sku';
    public const TYPE_MISSING_COST_PRICE = 'missing_cost_price';
    public const TYPE_EXCLUDE_FLAG_NO_METADATA = 'exclude_flag_no_metadata';

    protected $fillable = [
        'sku', 'woo_product_id', 'woo_variation_id',
        'issue_type', 'detected_at', 'last_seen_at', 'resolved_at',
        'notes', 'correlation_id',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved($q) { return $q->whereNull('resolved_at'); }
    public function scopeOfType($q, string $type) { return $q->where('issue_type', $type); }

    protected static function newFactory(): ImportIssueFactory { return ImportIssueFactory::new(); }
}
```

**Policies — use Pitfall K pattern (01-04-SUMMARY.md SuggestionPolicy template), hardcode `hasRole` + document "do not regenerate":**

**app/Domain/Products/Policies/ProductPolicy.php** (admin + pricing_manager edit; D-02 Phase 1 split):
```php
namespace App\Domain\Products\Policies;

use App\Domain\Products\Models\Product;
use App\Models\User;

/**
 * Per Phase 1 D-02 role split:
 * - admin + pricing_manager: edit
 * - sales + read_only: view only
 *
 * DO NOT regenerate via shield:generate — per Pitfall P2-H this policy is hand-written;
 * regenerating will replace with permission-based stubs (Plan 05 ships the grep guardrail).
 */
final class ProductPolicy
{
    public function viewAny(User $user): bool { return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']); }
    public function view(User $user, Product $product): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return $user->hasAnyRole(['admin', 'pricing_manager']); }
    public function update(User $user, Product $product): bool { return $user->hasAnyRole(['admin', 'pricing_manager']); }
    public function delete(User $user, Product $product): bool { return $user->hasRole('admin'); }
}
```

**app/Domain/Products/Policies/ProductVariantPolicy.php** — identical gates, model-typed on ProductVariant.

**app/Domain/Sync/Policies/SyncRunPolicy.php** (read-only for all; retry admin-only):
```php
namespace App\Domain\Sync\Policies;

use App\Domain\Sync\Models\SyncRun;
use App\Models\User;

/**
 * Per Phase 1 D-02 role split: view for all 4 roles (sync status visibility
 * is operational intel); retry action is admin-only (potentially expensive).
 *
 * DO NOT regenerate via shield:generate — per Pitfall P2-H.
 */
final class SyncRunPolicy
{
    public function viewAny(User $user): bool { return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']); }
    public function view(User $user, SyncRun $run): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return false; }  // producers only, not UI
    public function update(User $user, SyncRun $run): bool { return false; }  // state machine only
    public function delete(User $user, SyncRun $run): bool { return $user->hasRole('admin'); }
    public function retry(User $user, SyncRun $run): bool { return $user->hasRole('admin'); }
}
```

**app/Domain/Sync/Policies/ImportIssuePolicy.php** (admin + pricing_manager edit/resolve):
```php
namespace App\Domain\Sync\Policies;

use App\Domain\Sync\Models\ImportIssue;
use App\Models\User;

/**
 * Per Phase 1 D-02 + CONTEXT D-09: pricing_manager owns catalogue health triage.
 * DO NOT regenerate via shield:generate — per Pitfall P2-H.
 */
final class ImportIssuePolicy
{
    public function viewAny(User $user): bool { return $user->hasAnyRole(['admin', 'pricing_manager', 'sales', 'read_only']); }
    public function view(User $user, ImportIssue $issue): bool { return $this->viewAny($user); }
    public function create(User $user): bool { return false; }  // producers only
    public function update(User $user, ImportIssue $issue): bool { return $user->hasAnyRole(['admin', 'pricing_manager']); }
    public function delete(User $user, ImportIssue $issue): bool { return $user->hasRole('admin'); }
    public function resolve(User $user, ImportIssue $issue): bool { return $user->hasAnyRole(['admin', 'pricing_manager']); }
}
```

**app/Providers/AppServiceProvider.php — additive changes only:**

In the existing `boot()` method (after the Plan 04/05 Gate::policy calls), append:
```php
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Products\Policies\ProductPolicy;
use App\Domain\Products\Policies\ProductVariantPolicy;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Policies\SyncRunPolicy;
use App\Domain\Sync\Policies\ImportIssuePolicy;
use Illuminate\Support\Facades\Gate;
// ... existing imports ...

// Phase 2 policy registrations
Gate::policy(Product::class, ProductPolicy::class);
Gate::policy(ProductVariant::class, ProductVariantPolicy::class);
Gate::policy(SyncRun::class, SyncRunPolicy::class);
Gate::policy(ImportIssue::class, ImportIssuePolicy::class);
```

**Do NOT run `shield:generate` in this plan.** Filament Resources (and the associated permission generation) ship in Plan 04. The policies above use `hasRole` directly (Pitfall K), so they work independently of Shield permissions.

**Extend tests/Feature/Phase02DataModelTest.php** with tests 9-18 per <behavior> block. Factor out a `roleUser(string $roleName): User` helper at the top of the file that `firstOrCreates` the role + user and assigns. RefreshDatabase is already on Feature suite per Phase 1 phpunit.xml.

Run `vendor/bin/pest --filter=Phase02DataModelTest` — MUST be green. Run full suite `vendor/bin/pest` — MUST stay ≥ 100 passing + 0 failing + 2 skipped (Phase 1's pre-existing skips).
  </action>
  <verify>
    <automated>vendor/bin/pest --filter=Phase02DataModelTest &amp;&amp; vendor/bin/deptrac analyse --no-progress</automated>
  </verify>
  <done>
    - All 6 model files exist and load (`php -r "require 'vendor/autoload.php'; new App\\Domain\\Products\\Models\\Product();"` exits 0)
    - `ProductVariantObserver::saved()` fires on variant saves and bumps parent `last_synced_at` (Pitfall P2-C covered)
    - `SyncRun::markRunning/abort/finalise/findResumable/incrementCounter` each have tests proving behaviour
    - 5 policy files exist, all use `hasRole` directly (NO `{{ Placeholder }}` literals, grep confirms)
    - `AppServiceProvider::boot()` registers all 4 new policies via `Gate::policy(...)`
    - tests/Feature/Phase02DataModelTest.php total tests = 18, all green
    - Full Pest suite ≥ 110 passing (Phase 1's 92 + Phase 2 P01's 18)
    - `vendor/bin/deptrac analyse` exits 0 (Sync domain may import Products domain — Plan 05 wires the ruleset addition; current depfile allows Foundation only, so the Sync↔Products import may violate — if so, add `+Products` to Sync's allowed list in `depfile.yaml` AND `deptrac.yaml` as part of this task per research §12)
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| DB schema → Eloquent cast layer | Untrusted JSON (tags, attributes columns) arriving from Phase 2's WooClient.get() will hydrate through $casts |
| Policy gate → Filament Resource action | Plan 04 will build Resources against these policies; gate bypass here cascades |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-02-01-01 | Elevation of Privilege | ProductPolicy / ImportIssuePolicy | mitigate | Hardcode `hasRole` (Pitfall K); Plan 05 ships PolicyTemplateIntegrityTest grep guardrail. Tests 15/17 prove denial for sales/read_only. |
| T-02-01-02 | Tampering | SyncRun.cursor_page/cursor_sku | mitigate | findResumable() enforces status scope (resumable only); Plan 03 adds authenticated-CLI-only invocation of --resume; correlation_id acts as tamper-evident stamp. |
| T-02-01-03 | Information Disclosure | Product.tags JSON (may echo supplier payload including pricing metadata) | accept | Admin/pricing_manager scope only per policy; view is not exposed to sales/read_only in update path. If ops later require stricter redaction, add a hidden cast — deferred. |
| T-02-01-04 | Repudiation | SyncRun state transitions (markRunning/abort/finalise) | mitigate | Each transition writes Auditor::record with correlation_id; spatie/activitylog batch uuid threads back to origin request/command. |
| T-02-01-05 | Denial of Service | Missing FK cascade could orphan sync_errors/sync_run_items | mitigate | ->cascadeOnDelete() on both FK columns; Plan 05 retention prune drops runs older than N days, FK cascade cleans children. |
</threat_model>

<verification>
Plan-level checks before marking complete:

1. **Schema integrity:**
   ```bash
   php artisan migrate:fresh --force --database=mysql
   php artisan db:seed --class=DatabaseSeeder --force
   ```
   Exits 0 on both dev + testing DBs; all 6 new tables present via `php artisan db:table products && db:table product_variants && db:table sync_runs && db:table sync_errors && db:table import_issues && db:table sync_run_items`.

2. **Full Pest suite:**
   ```bash
   vendor/bin/pest
   ```
   ≥ 110 passing (Phase 1: 92 + Phase 2 P01: 18); 2 skipped-as-designed (Phase 1 scope-leak guards); 0 failures.

3. **Deptrac clean:**
   ```bash
   vendor/bin/deptrac analyse --no-progress
   ```
   Exits 0. If the Sync→Products import triggers a violation, add `- '+Products'` under `ruleset.Sync:` in both `depfile.yaml` and `deptrac.yaml` (Sync reads Product models per research §12).

4. **Policy grep (Pitfall P2-H preview):**
   ```bash
   grep -rn '{{ ' app/Policies/ app/Domain/ 2>/dev/null | grep Policies
   ```
   Returns empty — no template literal leakage.
</verification>

<success_criteria>
- 6 migrations executed clean on both dev + testing MySQL databases
- 6 Eloquent models (Product, ProductVariant, SyncRun, SyncError, SyncRunItem, ImportIssue) loaded, cast correctly, and factory-buildable
- ProductVariant observer fires on save and bumps parent Product's last_synced_at (Pitfall P2-C)
- SyncRun exposes the full state machine (markRunning, abort, finalise, findResumable, incrementCounter) with Auditor integration
- 5 Policies registered in AppServiceProvider::boot() and enforce D-02 Phase 1 role split
- 18+ feature tests green; full Pest suite ≥ 110 passing
- Deptrac 0 violations (Sync→Products allowance added if needed)
- No `{{ ` template literals in any Policy file
</success_criteria>

<output>
Create `.planning/phases/02-supplier-sync/02-01-SUMMARY.md` after completion with:
- Package additions: none (schema-only plan)
- Migrations shipped with exact timestamps
- Model contracts for Plans 02, 03, 04, 05 to consume
- Whether Sync→Products Deptrac ruleset change was needed
- Whether any Phase 1 tests regressed (expected: none — this plan is purely additive)
</output>
</content>
</invoke>