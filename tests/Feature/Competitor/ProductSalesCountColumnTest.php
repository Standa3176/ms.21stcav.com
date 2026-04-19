<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 01 Task 1 — products.last_sales_count_90d + _computed_at
|--------------------------------------------------------------------------
|
| D-05 noise-suppression gate source. MarginAnalyser reads these columns
| instead of running live aggregate queries against Woo order history.
| Populated daily by Plan 05-03's SalesCounterService.
*/

it('adds last_sales_count_90d + last_sales_count_computed_at columns to products', function (): void {
    expect(Schema::hasColumn('products', 'last_sales_count_90d'))->toBeTrue();
    expect(Schema::hasColumn('products', 'last_sales_count_computed_at'))->toBeTrue();
});

it('persists + casts last_sales_count_90d as integer', function (): void {
    $p = Product::factory()->create(['last_sales_count_90d' => 42]);

    $fresh = $p->fresh();
    expect($fresh->last_sales_count_90d)->toBe(42);
    expect(is_int($fresh->last_sales_count_90d))->toBeTrue();
});

it('persists + casts last_sales_count_computed_at as datetime', function (): void {
    $now = now();
    $p = Product::factory()->create(['last_sales_count_computed_at' => $now]);

    $fresh = $p->fresh();
    expect($fresh->last_sales_count_computed_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

it('defaults both columns to NULL on fresh product creation', function (): void {
    $p = Product::factory()->create();
    $fresh = $p->fresh();

    expect($fresh->last_sales_count_90d)->toBeNull();
    expect($fresh->last_sales_count_computed_at)->toBeNull();
});
