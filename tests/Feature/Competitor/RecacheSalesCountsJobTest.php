<?php

declare(strict_types=1);

use App\Domain\Competitor\Jobs\RecacheSalesCountsJob;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 3 — RecacheSalesCountsJob (A3 fallback stub)
|--------------------------------------------------------------------------
|
| A3 gate outcome at planner dev-env check 2026-04-19: WooClient does NOT
| expose a getOrders / /orders method. Plan fallback path is shipped here:
|
|   - Command + job still ship so the nightly schedule entry can live.
|   - Job body logs recache.wooclient_orders_missing + returns (no state
|     mutation). Real-time listener (Task 1) remains the source of truth
|     for last_sales_count_90d until a future plan extends WooClient.
|
| W1 semantics (NOT multiplied by quantity — 1 per line-item) MUST match
| the real-time listener aggregation when the real recache lands. The
| TODO-A3-FOLLOWUP marker in the SUMMARY + this stub's docblock
| documents the contract.
*/

it('is queued on the sync-bulk queue (reserved for long-running sync work)', function (): void {
    // Queueable trait exposes $queue; constructor routes via onQueue() to
    // avoid PHP 8.4 trait collision (Plan 05-02 precedent).
    $job = new RecacheSalesCountsJob(['SKU-A']);

    expect($job->queue)->toBe('sync-bulk');
});

it('logs recache.wooclient_orders_missing warning and exits without mutating product counters (A3 fallback)', function (): void {
    Log::spy();
    Product::factory()->create(['sku' => 'SKU-A', 'last_sales_count_90d' => 42]);

    (new RecacheSalesCountsJob(['SKU-A']))->handle();

    Log::shouldHaveReceived('warning')
        ->with('recache.wooclient_orders_missing', \Mockery::any())
        ->once();

    // Counter value preserved — real-time listener remains authoritative under A3 fallback
    expect(Product::where('sku', 'SKU-A')->value('last_sales_count_90d'))->toBe(42);
});

it('no-ops gracefully on an empty SKU list', function (): void {
    Log::spy();

    (new RecacheSalesCountsJob([]))->handle();

    // Still emits the fallback warning — CompetitorSalesRecacheCommand's chunk
    // dispatch shape means each job carries 1..N SKUs; zero-SKU edge is defensive.
    Log::shouldHaveReceived('warning')
        ->with('recache.wooclient_orders_missing', \Mockery::any())
        ->once();
});
