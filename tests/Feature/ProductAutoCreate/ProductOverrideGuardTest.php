<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\ProductAutoCreate\Services\ProductOverrideGuard;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 2 — ProductOverrideGuard
|--------------------------------------------------------------------------
| Covers:
|   - No Product match for wooProductId → no-op (no Woo PUT, no audit).
|   - No ProductOverride row → no-op.
|   - Pinned title → PUT /products/{wooId} with current Product.name value.
|   - Pinned regular_price → price formatted as "xx.xx" string.
|   - Pinned images → payload is [{src: url}].
|   - Unpinned fields → not included in the PUT payload.
|   - status + stock_quantity intentionally ignored (no pin map entry).
|   - Audit row written via Auditor::record on successful revert.
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

it('no-op when no product matches wooProductId', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldNotReceive('record');

    (new ProductOverrideGuard($woo, $auditor))->revertIfPinned(99999, ['name'], 'supplier_price_changed');
});

it('no-op when product has no ProductOverride row', function (): void {
    Product::factory()->create(['woo_product_id' => 500]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldNotReceive('record');

    (new ProductOverrideGuard($woo, $auditor))->revertIfPinned(500, ['name'], 'supplier_price_changed');
});

it('reverts pinned title with current local Product.name', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 500,
        'name' => 'Pinned Name Do Not Touch',
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'pin_title' => true,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->with('/products/500', ['name' => 'Pinned Name Do Not Touch'])
        ->once()
        ->andReturn([]);
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldReceive('record')->with('product_auto_create.pin_reverted', Mockery::type('array'))->once();

    (new ProductOverrideGuard($woo, $auditor))->revertIfPinned(500, ['name'], 'supplier_sync');
});

it('reverts pinned regular_price with properly formatted string', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 501,
        'sell_price' => 199.99,
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'pin_price' => true,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->with('/products/501', ['regular_price' => '199.99'])
        ->once()
        ->andReturn([]);
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldReceive('record')->once();

    (new ProductOverrideGuard($woo, $auditor))->revertIfPinned(501, ['regular_price'], 'supplier_price_changed');
});

it('reverts pinned image to [{src: url}] payload shape', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 502,
        'image_url' => 'https://ops.meetingstore.co.uk/storage/auto-create-images/x.webp',
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'pin_image' => true,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->with('/products/502', [
            'images' => [[
                'src' => 'https://ops.meetingstore.co.uk/storage/auto-create-images/x.webp',
            ]],
        ])
        ->once()
        ->andReturn([]);
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldReceive('record')->once();

    (new ProductOverrideGuard($woo, $auditor))->revertIfPinned(502, ['images'], 'supplier_sync');
});

it('skips unpinned fields', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 503,
        'name' => 'Unpinned Name',
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'pin_title' => false,  // not pinned — do not revert
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldNotReceive('record');

    (new ProductOverrideGuard($woo, $auditor))->revertIfPinned(503, ['name'], 'supplier_sync');
});

it('ignores status + stock_quantity (no pin map entry)', function (): void {
    $product = Product::factory()->create(['woo_product_id' => 504]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'pin_title' => true,  // pinned but not in $fieldNames
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldNotReceive('record');

    (new ProductOverrideGuard($woo, $auditor))
        ->revertIfPinned(504, ['status', 'stock_quantity'], 'supplier_sku_missing');
});
