<?php

declare(strict_types=1);

use App\Domain\Competitor\Services\SalesCounterService;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 1 — SalesCounterService (denormalised lookup)
|--------------------------------------------------------------------------
|
| Reads products.last_sales_count_90d (Plan 05-01 column); threshold check
| reads config('competitor.sales_threshold_90d', 10). No live Woo REST
| per-call — Woo rate-limit disaster avoided.
*/

it('getCount returns the products.last_sales_count_90d value when product exists', function (): void {
    Product::factory()->create(['sku' => 'SKU-1', 'last_sales_count_90d' => 42]);

    expect((new SalesCounterService())->getCount('SKU-1'))->toBe(42);
});

it('getCount returns 0 when SKU does not exist (null-coalesced)', function (): void {
    expect((new SalesCounterService())->getCount('NON-EXISTENT'))->toBe(0);
});

it('getCount returns 0 when product exists but last_sales_count_90d is NULL', function (): void {
    Product::factory()->create(['sku' => 'SKU-NULL', 'last_sales_count_90d' => null]);

    expect((new SalesCounterService())->getCount('SKU-NULL'))->toBe(0);
});

it('meetsThreshold returns true when count >= config threshold', function (): void {
    config(['competitor.sales_threshold_90d' => 10]);
    Product::factory()->create(['sku' => 'POP-1', 'last_sales_count_90d' => 15]);

    expect((new SalesCounterService())->meetsThreshold('POP-1'))->toBeTrue();
});

it('meetsThreshold returns false when count < config threshold', function (): void {
    config(['competitor.sales_threshold_90d' => 10]);
    Product::factory()->create(['sku' => 'SLOW-1', 'last_sales_count_90d' => 5]);

    expect((new SalesCounterService())->meetsThreshold('SLOW-1'))->toBeFalse();
});

it('meetsThreshold respects boundary (count equal to threshold passes)', function (): void {
    config(['competitor.sales_threshold_90d' => 10]);
    Product::factory()->create(['sku' => 'EDGE-1', 'last_sales_count_90d' => 10]);

    expect((new SalesCounterService())->meetsThreshold('EDGE-1'))->toBeTrue();
});
