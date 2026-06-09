<?php

declare(strict_types=1);

use App\Domain\Dashboard\Services\SnapshotAggregator;
use App\Domain\Products\Models\StockDivergenceFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260609-nku — SnapshotAggregator::computeStockDivergence shape
|--------------------------------------------------------------------------
|
| Single case: seed two findings, call computeStockDivergence(), assert
| count + total_phantom_units + last_run_at shape.
*/

it('aggregator returns count + total_phantom_units + last_run_at shape', function (): void {
    $auditedAt = now()->setSeconds(0);

    StockDivergenceFinding::create([
        'sku' => 'AGG-001',
        'name' => 'Aggregator test 1',
        'woo_product_id' => 7001,
        'ms_stock_quantity' => 0,
        'woo_stock_quantity' => 3,
        'phantom_units' => 3,
        'woo_last_modified' => $auditedAt->copy()->subDay(),
        'ms_last_synced_at' => $auditedAt->copy()->subHours(2),
        'status' => 'woo_overcount',
        'run_id' => '01HX0000000000000000000000',
        'audited_at' => $auditedAt,
    ]);

    StockDivergenceFinding::create([
        'sku' => 'AGG-002',
        'name' => 'Aggregator test 2',
        'woo_product_id' => 7002,
        'ms_stock_quantity' => 0,
        'woo_stock_quantity' => 5,
        'phantom_units' => 5,
        'woo_last_modified' => $auditedAt->copy()->subDay(),
        'ms_last_synced_at' => $auditedAt->copy()->subHours(2),
        'status' => 'woo_overcount',
        'run_id' => '01HX0000000000000000000000',
        'audited_at' => $auditedAt,
    ]);

    $payload = app(SnapshotAggregator::class)->computeStockDivergence();

    expect($payload['count'])->toBe(2);
    expect($payload['total_phantom_units'])->toBe(8);
    expect($payload['last_run_at'])->not->toBeNull();
    // ISO-8601 string round-trips to within one minute of the audit time
    // (allows for sub-second DB rounding differences across drivers).
    $diffSeconds = abs(\Carbon\Carbon::parse($payload['last_run_at'])->diffInSeconds($auditedAt));
    expect($diffSeconds)->toBeLessThan(60);
});
