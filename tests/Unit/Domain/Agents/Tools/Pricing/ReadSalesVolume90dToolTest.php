<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Pricing\ReadSalesVolume90dTool;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 02 Task 1 — ReadSalesVolume90dTool real-impl unit tests
|--------------------------------------------------------------------------
|
| Validates:
|   - Reads `products.last_sales_count_90d` + `products.last_sales_count_computed_at`
|     (RESEARCH schema correction — `last_` prefix; NOT `sales_count_computed_at`).
|   - Returns `_cache_age_hours` hint when computed > 24h ago.
|   - Returns `sales_count: 0` for unknown SKU; never throws.
*/

function invokeReadSalesVolume90dTool(string $sku): string
{
    // Prism v0.100.1 Tool::handle(...$args) takes variadic positional args
    return app(ReadSalesVolume90dTool::class)->asPrismTool()->handle($sku);
}

it('reads last_sales_count_90d + last_sales_count_computed_at columns', function () {
    Product::factory()->create([
        'sku' => 'SALES-FRESH',
        'last_sales_count_90d' => 47,
        'last_sales_count_computed_at' => now()->subHours(4),
    ]);

    $payload = json_decode(invokeReadSalesVolume90dTool('SALES-FRESH'), true);

    expect($payload['sku'])->toBe('SALES-FRESH');
    expect($payload['window_days'])->toBe(90);
    expect($payload['sales_count'])->toBe(47);
    expect($payload)->toHaveKey('_cache_computed_at');
});

it('emits _cache_age_hours when cache is older than 24h', function () {
    Product::factory()->create([
        'sku' => 'SALES-STALE',
        'last_sales_count_90d' => 12,
        'last_sales_count_computed_at' => now()->subHours(30),
    ]);

    $payload = json_decode(invokeReadSalesVolume90dTool('SALES-STALE'), true);

    expect($payload)->toHaveKey('_cache_age_hours');
    expect($payload['_cache_age_hours'])->toBeBetween(29, 31);
});

it('returns _note when sales count never cached', function () {
    Product::factory()->create([
        'sku' => 'SALES-UNCACHED',
        'last_sales_count_90d' => 0,
        'last_sales_count_computed_at' => null,
    ]);

    $payload = json_decode(invokeReadSalesVolume90dTool('SALES-UNCACHED'), true);

    expect($payload['sales_count'])->toBe(0);
    expect($payload)->toHaveKey('_note');
});

it('returns sales_count=0 for unknown SKU — never throws', function () {
    $payload = json_decode(invokeReadSalesVolume90dTool('NEVER-SEEN-SKU'), true);

    expect($payload['sku'])->toBe('NEVER-SEEN-SKU');
    expect($payload['sales_count'])->toBe(0);
    expect($payload)->toHaveKey('_note');
});
