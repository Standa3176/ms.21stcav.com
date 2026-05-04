<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a ProductPriceSnapshot with correct casts', function () {
    $product = Product::factory()->create();

    $snapshot = ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'TEST-001',
        'woo_status' => 'publish',
        'sell_price' => '199.9900',
        'buy_price' => '120.5000',
        'stock_quantity' => 5,
        'recorded_at' => '2026-05-04',
    ]);

    expect($snapshot->fresh())
        ->recorded_at->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($snapshot->fresh()->recorded_at->format('Y-m-d'))->toBe('2026-05-04')
        ->and((string) $snapshot->fresh()->sell_price)->toBe('199.9900')
        ->and((string) $snapshot->fresh()->buy_price)->toBe('120.5000')
        ->and($snapshot->fresh()->stock_quantity)->toBe(5)
        ->and($snapshot->fresh()->woo_status)->toBe('publish');
});

it('belongs to a Product (BelongsTo)', function () {
    $product = Product::factory()->create(['sku' => 'BTSKU-001']);

    $snapshot = ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'BTSKU-001',
        'sell_price' => '10.0000',
        'buy_price' => '5.0000',
        'stock_quantity' => 1,
        'recorded_at' => today(),
    ]);

    expect($snapshot->product)->not->toBeNull()
        ->and($snapshot->product->id)->toBe($product->id)
        ->and($snapshot->product->sku)->toBe('BTSKU-001');
});

it('enforces unique (product_id, recorded_at)', function () {
    $product = Product::factory()->create();

    ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'UNIQ-001',
        'sell_price' => '10.0000',
        'buy_price' => '5.0000',
        'stock_quantity' => 1,
        'recorded_at' => '2026-05-04',
    ]);

    expect(fn () => ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'UNIQ-001',
        'sell_price' => '20.0000',
        'buy_price' => '7.0000',
        'stock_quantity' => 2,
        'recorded_at' => '2026-05-04',
    ]))->toThrow(QueryException::class);
});

it('allows multiple snapshots for the same product on different days', function () {
    $product = Product::factory()->create();

    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'MULTI-001',
        'sell_price' => '10.0000', 'buy_price' => '5.0000',
        'stock_quantity' => 1, 'recorded_at' => '2026-05-03',
    ]);
    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'MULTI-001',
        'sell_price' => '11.0000', 'buy_price' => '6.0000',
        'stock_quantity' => 2, 'recorded_at' => '2026-05-04',
    ]);

    expect(ProductPriceSnapshot::where('product_id', $product->id)->count())->toBe(2);
});
