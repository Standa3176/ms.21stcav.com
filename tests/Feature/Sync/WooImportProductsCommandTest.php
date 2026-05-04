<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

/**
 * Quick task 260504-d7v Tests.
 *
 * Verifies woo:import-products bulk-imports the Woo catalogue into the local
 * products table and optionally enriches buy_price from supplier feed.
 */

function fakeWooPage(array $products): \Mockery\MockInterface
{
    $mock = Mockery::mock(WooClient::class);
    $mock->shouldReceive('get')
        ->withArgs(fn ($endpoint, $args) => $endpoint === 'products' && ($args['page'] ?? 1) === 1)
        ->andReturn($products);
    $mock->shouldReceive('get')
        ->withArgs(fn ($endpoint, $args) => $endpoint === 'products' && ($args['page'] ?? 1) > 1)
        ->andReturn([]);

    return $mock;
}

function fakeSupplier(array $skuMap): \Mockery\MockInterface
{
    $mock = Mockery::mock(SupplierClient::class);
    $mock->shouldReceive('fetchAllProducts')->andReturn($skuMap);

    return $mock;
}

it('--dry-run reports planned changes without writing rows', function (): void {
    app()->instance(WooClient::class, fakeWooPage([
        ['id' => 101, 'sku' => 'SKU-A', 'name' => 'Product A', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '99.00'],
        ['id' => 102, 'sku' => 'SKU-B', 'name' => 'Product B', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '149.50'],
    ]));
    app()->instance(SupplierClient::class, fakeSupplier([]));

    $this->artisan('woo:import-products', ['--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    expect(Product::count())->toBe(0);
});

it('persists Woo products with sku/name/sell_price populated', function (): void {
    app()->instance(WooClient::class, fakeWooPage([
        ['id' => 201, 'sku' => 'WIDGET-1', 'name' => 'Widget One', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '49.99'],
        ['id' => 202, 'sku' => 'WIDGET-2', 'name' => 'Widget Two', 'type' => 'simple', 'status' => 'draft', 'stock_status' => 'outofstock', 'regular_price' => '79.00'],
    ]));
    app()->instance(SupplierClient::class, fakeSupplier([]));

    $this->artisan('woo:import-products')->assertExitCode(0);

    expect(Product::count())->toBe(2);
    $w1 = Product::where('woo_product_id', 201)->first();
    expect($w1->sku)->toBe('WIDGET-1');
    expect($w1->name)->toBe('Widget One');
    expect((string) $w1->sell_price)->toBe('49.9900');
    expect($w1->stock_status)->toBe('instock');

    $w2 = Product::where('woo_product_id', 202)->first();
    expect($w2->status)->toBe('draft');
    expect($w2->stock_status)->toBe('outofstock');
});

it('--limit caps the number of simple products imported', function (): void {
    app()->instance(WooClient::class, fakeWooPage([
        ['id' => 301, 'sku' => 'A', 'name' => 'A', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '10'],
        ['id' => 302, 'sku' => 'B', 'name' => 'B', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '20'],
        ['id' => 303, 'sku' => 'C', 'name' => 'C', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '30'],
    ]));
    app()->instance(SupplierClient::class, fakeSupplier([]));

    $this->artisan('woo:import-products', ['--limit' => 2])->assertExitCode(0);

    expect(Product::count())->toBe(2);
});

// Quick task 260504-imk — stock_quantity capture rules.
it('captures stock_quantity when manage_stock=true and null when manage_stock=false', function (): void {
    app()->instance(WooClient::class, fakeWooPage([
        ['id' => 601, 'sku' => 'TRACKED', 'name' => 'Tracked', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '10', 'manage_stock' => true, 'stock_quantity' => 42],
        ['id' => 602, 'sku' => 'UNTRACKED', 'name' => 'Untracked', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '20', 'manage_stock' => false, 'stock_quantity' => 99],
        ['id' => 603, 'sku' => 'ZERO-TRACKED', 'name' => 'Zero Tracked', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'outofstock', 'regular_price' => '30', 'manage_stock' => true, 'stock_quantity' => 0],
    ]));
    app()->instance(SupplierClient::class, fakeSupplier([]));

    $this->artisan('woo:import-products')->assertExitCode(0);

    expect(Product::where('woo_product_id', 601)->first()->stock_quantity)->toBe(42);
    expect(Product::where('woo_product_id', 602)->first()->stock_quantity)->toBeNull();
    // Critical: 0-tracked must persist as 0, not null — distinguishes "0 in stock" from "untracked"
    expect(Product::where('woo_product_id', 603)->first()->stock_quantity)->toBe(0);
});

it('skips type=variation rows', function (): void {
    app()->instance(WooClient::class, fakeWooPage([
        ['id' => 401, 'sku' => 'SIMPLE', 'name' => 'Simple', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '10'],
        ['id' => 402, 'sku' => 'VAR', 'name' => 'Variation', 'type' => 'variation', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '20'],
    ]));
    app()->instance(SupplierClient::class, fakeSupplier([]));

    $this->artisan('woo:import-products')->assertExitCode(0);

    expect(Product::count())->toBe(1);
    expect(Product::first()->sku)->toBe('SIMPLE');
});

it('--with-supplier fills buy_price for SKUs in the supplier feed', function (): void {
    app()->instance(WooClient::class, fakeWooPage([
        ['id' => 501, 'sku' => 'IN-SUPPLIER', 'name' => 'In Supplier', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '99.99'],
        ['id' => 502, 'sku' => 'NOT-IN-SUPPLIER', 'name' => 'Not In Supplier', 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '49.99'],
    ]));
    app()->instance(SupplierClient::class, fakeSupplier([
        'IN-SUPPLIER' => ['price' => '40.00', 'stock' => 5],
    ]));

    $this->artisan('woo:import-products', ['--with-supplier' => true])->assertExitCode(0);

    expect(Product::count())->toBe(2);
    $matched = Product::where('woo_product_id', 501)->first();
    expect((string) $matched->buy_price)->toBe('40.0000');
    expect((string) $matched->sell_price)->toBe('99.9900');

    $unmatched = Product::where('woo_product_id', 502)->first();
    expect($unmatched->buy_price)->toBeNull();
});
