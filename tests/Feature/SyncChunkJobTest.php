<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierStockChanged;
use App\Domain\Sync\Jobs\SyncChunkJob;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\SyncRunItem;
use App\Domain\Sync\Services\AbortGuard;
use App\Domain\Sync\Services\SyncDiffEngine;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
    // Shadow mode for tests — WooClient::put records SyncDiff instead of real HTTP.
    config(['services.woo.write_enabled' => false]);
});

/**
 * Build a canonical Woo simple-product sku-row.
 */
function buildSkuRow(string $sku, int $pid, string $price = '10.00', int $stock = 5, array $extra = []): array
{
    return array_merge([
        'type' => 'simple',
        'sku' => $sku,
        'woo_product_id' => $pid,
        'woo_variation_id' => null,
        'price' => $price,
        'stock_quantity' => $stock,
        'manage_stock' => true,
        'is_custom_ms' => false,
        'exclude_from_auto_update' => false,
    ], $extra);
}

// -----------------------------------------------------------------------------
// C1: Job is dispatched on 'woo-writes' queue (260719-wth)
// -----------------------------------------------------------------------------
test('C1: SyncChunkJob lives on the woo-writes queue', function () {
    $run = SyncRun::factory()->running()->create();
    $job = new SyncChunkJob(runId: $run->id, page: 1, skus: [], supplierFeed: []);

    // 260719-wth — supplier price/stock chunks are live Woo writes; moved off the
    // shared sync-woo-push pool onto the dedicated single-worker write queue.
    expect($job->queue)->toBe('woo-writes');
});

// -----------------------------------------------------------------------------
// C2: Processes SKUs → SyncRunItem rows + updated_count bumps
// -----------------------------------------------------------------------------
test('C2: processes a page of SKUs, writes SyncRunItem rows, bumps updated_count', function () {
    Event::fake([SupplierPriceChanged::class, SupplierStockChanged::class]);
    $run = SyncRun::factory()->running()->create();

    // 2 SKUs need an update (supplier differs), 1 is no-op (exact match).
    $skus = [
        buildSkuRow('NEED-PRICE', 1001, '10.00', 5),
        buildSkuRow('NEED-STOCK', 1002, '20.00', 5),
        buildSkuRow('NO-OP', 1003, '30.00', 5),
    ];
    $supplierFeed = [
        'NEED-PRICE' => ['price' => '15.00', 'stock' => 5],   // price diff
        'NEED-STOCK' => ['price' => '20.00', 'stock' => 3],   // stock diff
        'NO-OP' => ['price' => '30.00', 'stock' => 5],        // identical
    ];

    $job = new SyncChunkJob($run->id, 1, $skus, $supplierFeed);
    $job->handle(app(WooClient::class), app(SyncDiffEngine::class), app(AbortGuard::class));

    $run->refresh();
    expect($run->updated_count)->toBe(2);

    $items = SyncRunItem::forRun($run->id)->get();
    expect($items)->toHaveCount(2)  // only updated rows write items (no-op returns early)
        ->and($items->pluck('action')->unique()->all())->toEqual(['updated']);
});

// -----------------------------------------------------------------------------
// C3: Dry-run — no real Woo call; shadow rows land in sync_diffs
// -----------------------------------------------------------------------------
test('C3: dry-run mode — WooClient writes land in sync_diffs; no real HTTP', function () {
    $run = SyncRun::factory()->running()->create(['dry_run' => true]);

    $skus = [buildSkuRow('D3-1', 5555, '10.00', 5)];
    $supplierFeed = ['D3-1' => ['price' => '12.50', 'stock' => 5]];

    $job = new SyncChunkJob($run->id, 1, $skus, $supplierFeed);
    $job->handle(app(WooClient::class), app(SyncDiffEngine::class), app(AbortGuard::class));

    expect(SyncDiff::count())->toBe(1)
        ->and(SyncDiff::first()->endpoint)->toBe('products/5555');
});

// -----------------------------------------------------------------------------
// C4: Pitfall P2-F — product already synced in THIS run → skipped
// -----------------------------------------------------------------------------
test('C4: products with last_synced_at > run.started_at are skipped (worker-retry idempotency)', function () {
    $run = SyncRun::factory()->running()->create(['started_at' => now()->subMinutes(10)]);

    // Seed a product with last_synced_at AFTER run.started_at — simulates a retry.
    Product::factory()->create([
        'woo_product_id' => 7777,
        'sku' => 'ALREADY-DONE',
        'last_synced_at' => now()->subMinute(),  // > run.started_at (10 min ago)
    ]);

    $skus = [buildSkuRow('ALREADY-DONE', 7777, '10.00', 5)];
    $supplierFeed = ['ALREADY-DONE' => ['price' => '15.00', 'stock' => 5]];

    $job = new SyncChunkJob($run->id, 1, $skus, $supplierFeed);
    $job->handle(app(WooClient::class), app(SyncDiffEngine::class), app(AbortGuard::class));

    // No SyncRunItem created — SKU was short-circuited.
    expect(SyncRunItem::forRun($run->id)->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// C5: Events dispatched after successful write
// -----------------------------------------------------------------------------
test('C5: successful update dispatches SupplierPriceChanged + SupplierStockChanged', function () {
    Event::fake([SupplierPriceChanged::class, SupplierStockChanged::class]);
    $run = SyncRun::factory()->running()->create();

    $skus = [buildSkuRow('BOTH', 6000, '10.00', 5)];
    $supplierFeed = ['BOTH' => ['price' => '20.00', 'stock' => 10]];

    $job = new SyncChunkJob($run->id, 1, $skus, $supplierFeed);
    $job->handle(app(WooClient::class), app(SyncDiffEngine::class), app(AbortGuard::class));

    Event::assertDispatched(SupplierPriceChanged::class, 1);
    Event::assertDispatched(SupplierStockChanged::class, 1);

    // Sanity: no-op row dispatches neither.
    Event::fake([SupplierPriceChanged::class, SupplierStockChanged::class]);
    $run2 = SyncRun::factory()->running()->create();
    $skus2 = [buildSkuRow('NOPE', 7000, '10.00', 5)];
    $feed2 = ['NOPE' => ['price' => '10.00', 'stock' => 5]];  // identical
    $job2 = new SyncChunkJob($run2->id, 1, $skus2, $feed2);
    $job2->handle(app(WooClient::class), app(SyncDiffEngine::class), app(AbortGuard::class));
    Event::assertNotDispatched(SupplierPriceChanged::class);
    Event::assertNotDispatched(SupplierStockChanged::class);
});
