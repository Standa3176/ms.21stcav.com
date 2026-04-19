<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Products\Policies\ProductPolicy;
use App\Domain\Products\Policies\ProductVariantPolicy;
use App\Domain\Sync\Models\ImportIssue;
use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Policies\ImportIssuePolicy;
use App\Domain\Sync\Policies\SyncRunPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Plan 02-01 schema + model contract guarantees.
 *
 * Tests 1-8: migrations, schema, factory smoke, rollback
 * Tests 9-10: Product/ProductVariant relationships + observer (Pitfall P2-C)
 * Tests 11-14: SyncRun state machine (markRunning/abort/finalise/findResumable)
 * Tests 15-17: Policy gates per Phase 1 D-02 role split
 * Test  18:   AppServiceProvider policy registration
 */

/**
 * Helper: firstOrCreate a Spatie role then return a fresh user bearing that role.
 * `guard_name` is `web` because Filament Shield is registered on the default web guard.
 */
function roleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — products schema
// ══════════════════════════════════════════════════════════════════════════════

it('creates the products table with every expected column (D-01 simple+variable support)', function () {
    expect(Schema::hasTable('products'))->toBeTrue();

    $expected = [
        'id', 'woo_product_id', 'sku', 'name', 'type', 'status', 'stock_status',
        'buy_price', 'sell_price', 'cost_price',
        'is_custom_ms', 'exclude_from_auto_update', 'tags',
        'last_synced_at', 'last_sync_run_id',
        'created_at', 'updated_at', 'deleted_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('products', $column))->toBeTrue("products missing column: {$column}");
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — product_variants schema + FK
// ══════════════════════════════════════════════════════════════════════════════

it('creates the product_variants table with FK-constrained product_id + unique woo_variation_id + unique sku', function () {
    expect(Schema::hasTable('product_variants'))->toBeTrue();

    $expected = [
        'id', 'product_id', 'woo_variation_id', 'sku', 'name',
        'buy_price', 'sell_price', 'old_buy_price', 'old_sell_price',
        'stock_quantity', 'old_stock_quantity',
        'status', 'attributes', 'last_synced_at',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('product_variants', $column))->toBeTrue("product_variants missing column: {$column}");
    }

    // FK constraint: cascading delete. We prove it via Eloquent round-trip.
    $product = Product::factory()->variable()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    expect($product->variants()->count())->toBe(1);
    $product->delete();  // soft delete — won't cascade
    $product->forceDelete(); // this should cascade to variants via FK
    expect(ProductVariant::find($variant->id))->toBeNull();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — sync_runs schema + consecutive_failures column (D-06(b) blocker fix)
// ══════════════════════════════════════════════════════════════════════════════

it('creates the sync_runs table with 5-state status enum and DB-backed consecutive_failures counter', function () {
    expect(Schema::hasTable('sync_runs'))->toBeTrue();

    $expected = [
        'id', 'started_at', 'completed_at', 'status', 'dry_run',
        'total_skus', 'updated_count', 'skipped_count', 'failed_count',
        'missing_count', 'unknown_sku_count',
        'consecutive_failures',   // D-06(b) Checker-blocker fix
        'abort_reason', 'abort_message',
        'cursor_page', 'cursor_sku', 'correlation_id',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('sync_runs', $column))->toBeTrue("sync_runs missing column: {$column}");
    }

    // consecutive_failures column must default to 0 for D-06(b) atomic-increment semantics
    $run = SyncRun::factory()->create();
    expect($run->consecutive_failures)->toBe(0);

    // and be incrementable (proves unsignedInt not enum)
    $run->increment('consecutive_failures');
    expect($run->fresh()->consecutive_failures)->toBe(1);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — sync_errors schema (append-only)
// ══════════════════════════════════════════════════════════════════════════════

it('creates the sync_errors append-only table with FK sync_run_id and NO updated_at column', function () {
    expect(Schema::hasTable('sync_errors'))->toBeTrue();

    $expected = [
        'id', 'sync_run_id', 'sku', 'woo_product_id', 'woo_variation_id',
        'error_class', 'error_message', 'correlation_id', 'created_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('sync_errors', $column))->toBeTrue("sync_errors missing column: {$column}");
    }

    // Append-only — NO updated_at
    expect(Schema::hasColumn('sync_errors', 'updated_at'))->toBeFalse();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — import_issues schema (SYNC-12 + D-09)
// ══════════════════════════════════════════════════════════════════════════════

it('creates the import_issues table with issue_type enum and nullable resolved_at for triage', function () {
    expect(Schema::hasTable('import_issues'))->toBeTrue();

    $expected = [
        'id', 'sku', 'woo_product_id', 'woo_variation_id',
        'issue_type', 'detected_at', 'last_seen_at', 'resolved_at',
        'notes', 'correlation_id',
        'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('import_issues', $column))->toBeTrue("import_issues missing column: {$column}");
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — sync_run_items schema (CSV source, 11 columns + FK)
// ══════════════════════════════════════════════════════════════════════════════

it('creates the sync_run_items append-only table with all 11 D-10 CSV columns plus sync_run_id FK', function () {
    expect(Schema::hasTable('sync_run_items'))->toBeTrue();

    $expected = [
        'id', 'sync_run_id', 'sku', 'woo_product_id', 'woo_variation_id',
        'action', 'reason',
        'old_price', 'new_price', 'old_stock', 'new_stock',
        'error_message', 'correlation_id', 'created_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('sync_run_items', $column))->toBeTrue("sync_run_items missing column: {$column}");
    }

    // Append-only — NO updated_at
    expect(Schema::hasColumn('sync_run_items', 'updated_at'))->toBeFalse();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 7 — factory smoke test
// ══════════════════════════════════════════════════════════════════════════════

it('produces valid persisted instances from every new Phase-2 factory + ProductFactory::variable()->hasVariants()', function () {
    $product = Product::factory()->create();
    expect($product->id)->not->toBeNull();
    expect($product->type)->toBe('simple');

    $variant = ProductVariant::factory()->create();
    expect($variant->id)->not->toBeNull();
    expect($variant->product_id)->not->toBeNull();

    $run = SyncRun::factory()->create();
    expect($run->id)->not->toBeNull();

    $err = SyncError::factory()->create();
    expect($err->id)->not->toBeNull();

    $issue = ImportIssue::factory()->create();
    expect($issue->id)->not->toBeNull();

    $item = SyncRunItem::factory()->create();
    expect($item->id)->not->toBeNull();

    // variable() state + hasVariants() — Product::factory()->variable()->hasVariants(3)
    $variable = Product::factory()->variable()->hasVariants(3)->create();
    expect($variable->type)->toBe('variable');
    expect($variable->sku)->toBeNull();
    expect($variable->variants()->count())->toBe(3);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 8 — migration rollback + redeploy (round-trip clean)
// ══════════════════════════════════════════════════════════════════════════════

it('rolls back the 6 Phase-2 migrations + re-migrates cleanly (round-trip)', function () {
    // RefreshDatabase has already brought us to a fully-migrated state.
    // Step=9 rolls back (newest first):
    //   Phase 3 Plan 01:
    //     2026_04_19_090100_create_product_overrides_table
    //     2026_04_19_090000_create_pricing_rules_table
    //   Phase 2 Plan 04 (additive column):
    //     2026_04_18_200600_add_receives_sync_reports_to_alert_recipients
    //   Phase 2 Plan 01 (6 table creates):
    //     2026_04_18_200500_create_sync_run_items_table
    //     2026_04_18_200400_create_import_issues_table
    //     2026_04_18_200300_create_sync_errors_table
    //     2026_04_18_200200_create_sync_runs_table
    //     2026_04_18_200100_create_product_variants_table
    //     2026_04_18_200000_create_products_table
    // Step = 2 (Phase 3) + 1 (receives_sync_reports) + 6 (Phase 2 tables) = 9.
    $this->artisan('migrate:rollback', ['--step' => 9])->assertExitCode(0);

    expect(Schema::hasTable('product_overrides'))->toBeFalse();
    expect(Schema::hasTable('pricing_rules'))->toBeFalse();
    expect(Schema::hasTable('sync_run_items'))->toBeFalse();
    expect(Schema::hasTable('import_issues'))->toBeFalse();
    expect(Schema::hasTable('sync_errors'))->toBeFalse();
    expect(Schema::hasTable('sync_runs'))->toBeFalse();
    expect(Schema::hasTable('product_variants'))->toBeFalse();
    expect(Schema::hasTable('products'))->toBeFalse();

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

    expect(Schema::hasTable('products'))->toBeTrue();
    expect(Schema::hasTable('product_variants'))->toBeTrue();
    expect(Schema::hasTable('sync_runs'))->toBeTrue();
    expect(Schema::hasTable('sync_errors'))->toBeTrue();
    expect(Schema::hasTable('import_issues'))->toBeTrue();
    expect(Schema::hasTable('sync_run_items'))->toBeTrue();
    expect(Schema::hasTable('pricing_rules'))->toBeTrue();
    expect(Schema::hasTable('product_overrides'))->toBeTrue();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 9 — Product ↔ ProductVariant relationship (hasMany / belongsTo)
// ══════════════════════════════════════════════════════════════════════════════

it('returns children via Product::variants() and parent via ProductVariant::product()', function () {
    $product = Product::factory()->variable()->hasVariants(3)->create();

    expect($product->variants)->toHaveCount(3);
    expect($product->variants->first())->toBeInstanceOf(ProductVariant::class);

    $variant = $product->variants->first();
    expect($variant->product)->toBeInstanceOf(Product::class);
    expect($variant->product->id)->toBe($product->id);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 10 — ProductVariantObserver bumps parent last_synced_at (Pitfall P2-C)
// ══════════════════════════════════════════════════════════════════════════════

it('bumps parent Product.last_synced_at on variant save (Pitfall P2-C mitigation)', function () {
    // Arrange: a variable parent with last_synced_at=NULL and a child variant.
    $product = Product::factory()->variable()->create(['last_synced_at' => null]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

    // After variant create the observer should have bumped the parent's timestamp.
    expect($product->fresh()->last_synced_at)
        ->not->toBeNull('observer must fire on variant create');

    // Now reset the parent timestamp via a raw DB write (bypasses Eloquent so
    // the observer does not re-fire here), then refresh the in-memory $variant
    // so its cached ->product relation reflects the reset state.
    \Illuminate\Support\Facades\DB::table('products')
        ->where('id', $product->id)
        ->update(['last_synced_at' => null]);

    expect($product->fresh()->last_synced_at)->toBeNull();

    $variant = $variant->fresh();   // drop cached ->product relation
    $variant->update(['stock_quantity' => 42]);

    expect($product->fresh()->last_synced_at)
        ->not->toBeNull('observer must fire on variant update too');
});

// ══════════════════════════════════════════════════════════════════════════════
// Tests 11-14 — SyncRun state machine
// ══════════════════════════════════════════════════════════════════════════════

it('markRunning flips status queued→running and writes Auditor sync.run.running row', function () {
    $run = SyncRun::factory()->create(['status' => SyncRun::STATUS_QUEUED]);
    $run->markRunning();

    expect($run->fresh()->status)->toBe(SyncRun::STATUS_RUNNING);

    $activity = \Spatie\Activitylog\Models\Activity::where('description', 'sync.run.running')->latest()->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties->get('run_id'))->toBe($run->id);
});

it('abort flips to aborted, records reason+message, sets completed_at, writes Auditor row', function () {
    $run = SyncRun::factory()->running()->create();
    $run->abort(SyncRun::ABORT_ERROR_RATE, 'test msg');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRun::STATUS_ABORTED);
    expect($fresh->abort_reason)->toBe(SyncRun::ABORT_ERROR_RATE);
    expect($fresh->abort_message)->toBe('test msg');
    expect($fresh->completed_at)->not->toBeNull();

    $activity = \Spatie\Activitylog\Models\Activity::where('description', 'sync.run.aborted')->latest()->first();
    expect($activity)->not->toBeNull();
    expect($activity->properties->get('reason'))->toBe(SyncRun::ABORT_ERROR_RATE);
});

it('finalise flips to completed + stamps completed_at + Auditor row with stats', function () {
    $run = SyncRun::factory()->running()->create([
        'total_skus' => 100,
        'updated_count' => 50,
    ]);
    $run->finalise();

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRun::STATUS_COMPLETED);
    expect($fresh->completed_at)->not->toBeNull();

    $activity = \Spatie\Activitylog\Models\Activity::where('description', 'sync.run.completed')->latest()->first();
    expect($activity)->not->toBeNull();
    $stats = $activity->properties->get('stats');
    expect($stats)->toBeArray();
    expect($stats['total_skus'])->toBe(100);
    expect($stats['updated_count'])->toBe(50);
});

it('findResumable fetches aborted/failed/running runs and flips them back to running for resume', function () {
    $run = SyncRun::factory()->aborted()->create();

    $resumed = SyncRun::findResumable($run->id);

    expect($resumed->id)->toBe($run->id);
    expect($resumed->status)->toBe(SyncRun::STATUS_RUNNING);
});

// ══════════════════════════════════════════════════════════════════════════════
// Tests 15-17 — Policy gates per Phase 1 D-02 role split
// ══════════════════════════════════════════════════════════════════════════════

it('gates ProductPolicy update: admin + pricing_manager allow; sales + read_only deny', function () {
    $policy = new ProductPolicy();
    $product = Product::factory()->create();

    expect($policy->viewAny(roleUser('admin')))->toBeTrue();
    expect($policy->update(roleUser('admin'), $product))->toBeTrue();

    expect($policy->viewAny(roleUser('pricing_manager')))->toBeTrue();
    expect($policy->update(roleUser('pricing_manager'), $product))->toBeTrue();

    expect($policy->viewAny(roleUser('sales')))->toBeTrue();
    expect($policy->update(roleUser('sales'), $product))->toBeFalse();

    expect($policy->viewAny(roleUser('read_only')))->toBeTrue();
    expect($policy->update(roleUser('read_only'), $product))->toBeFalse();
});

it('gates SyncRunPolicy: viewAny for all 4 roles; retry admin-only', function () {
    $policy = new SyncRunPolicy();
    $run = SyncRun::factory()->create();

    expect($policy->viewAny(roleUser('admin')))->toBeTrue();
    expect($policy->viewAny(roleUser('pricing_manager')))->toBeTrue();
    expect($policy->viewAny(roleUser('sales')))->toBeTrue();
    expect($policy->viewAny(roleUser('read_only')))->toBeTrue();

    expect($policy->retry(roleUser('admin'), $run))->toBeTrue();
    expect($policy->retry(roleUser('pricing_manager'), $run))->toBeFalse();
    expect($policy->retry(roleUser('sales'), $run))->toBeFalse();
    expect($policy->retry(roleUser('read_only'), $run))->toBeFalse();
});

it('gates ImportIssuePolicy resolve: admin + pricing_manager allow; sales + read_only deny', function () {
    $policy = new ImportIssuePolicy();
    $issue = ImportIssue::factory()->create();

    expect($policy->resolve(roleUser('admin'), $issue))->toBeTrue();
    expect($policy->resolve(roleUser('pricing_manager'), $issue))->toBeTrue();
    expect($policy->resolve(roleUser('sales'), $issue))->toBeFalse();
    expect($policy->resolve(roleUser('read_only'), $issue))->toBeFalse();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 18 — AppServiceProvider policy registration
// ══════════════════════════════════════════════════════════════════════════════

it('registers all 4 Phase-2 policies on the Gate facade via AppServiceProvider::boot()', function () {
    expect(Gate::getPolicyFor(Product::class))->toBeInstanceOf(ProductPolicy::class);
    expect(Gate::getPolicyFor(ProductVariant::class))->toBeInstanceOf(ProductVariantPolicy::class);
    expect(Gate::getPolicyFor(SyncRun::class))->toBeInstanceOf(SyncRunPolicy::class);
    expect(Gate::getPolicyFor(ImportIssue::class))->toBeInstanceOf(ImportIssuePolicy::class);
});
