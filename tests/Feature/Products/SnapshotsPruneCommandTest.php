<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes rows older than 90 days from both snapshot tables', function () {
    $product = Product::factory()->create();

    // Old (>90 days) — should be pruned
    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'OLD',
        'sell_price' => '10.0000', 'buy_price' => '5.0000', 'stock_quantity' => 1,
        'recorded_at' => now()->subDays(120),
    ]);
    SupplierOfferSnapshot::create([
        'sku' => 'old-feed', 'product_id' => null,
        'supplier_id' => '1', 'supplier_name' => 'X',
        'price' => '10.0000', 'stock' => 1, 'rrp' => null,
        'recorded_at' => now()->subDays(120),
    ]);

    // Recent (<90 days) — should remain
    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'RECENT',
        'sell_price' => '20.0000', 'buy_price' => '10.0000', 'stock_quantity' => 2,
        'recorded_at' => now()->subDays(10),
    ]);
    SupplierOfferSnapshot::create([
        'sku' => 'recent-feed', 'product_id' => null,
        'supplier_id' => '1', 'supplier_name' => 'X',
        'price' => '20.0000', 'stock' => 2, 'rrp' => null,
        'recorded_at' => now()->subDays(10),
    ]);

    $this->artisan('history:prune')
        ->expectsOutputToContain('Pruned 1 product_price_snapshots + 1 supplier_offer_snapshots older than 90 days')
        ->assertExitCode(0);

    expect(ProductPriceSnapshot::count())->toBe(1)
        ->and(ProductPriceSnapshot::first()->sku)->toBe('RECENT')
        ->and(SupplierOfferSnapshot::count())->toBe(1)
        ->and(SupplierOfferSnapshot::first()->sku)->toBe('recent-feed');
});

it('honours --days override flag', function () {
    $product = Product::factory()->create();

    // 20 days old — outside the 30-day cap, would be kept by default 90-day prune
    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'OLD-30',
        'sell_price' => '10.0000', 'buy_price' => '5.0000', 'stock_quantity' => 1,
        'recorded_at' => now()->subDays(40),
    ]);
    // 5 days old — within the 30-day cap
    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'YOUNG-30',
        'sell_price' => '20.0000', 'buy_price' => '10.0000', 'stock_quantity' => 2,
        'recorded_at' => now()->subDays(5),
    ]);

    $this->artisan('history:prune', ['--days' => 30])
        ->assertExitCode(0);

    expect(ProductPriceSnapshot::count())->toBe(1)
        ->and(ProductPriceSnapshot::first()->sku)->toBe('YOUNG-30');
});

it('is a no-op when no rows exceed the threshold', function () {
    $product = Product::factory()->create();

    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'YOUNG',
        'sell_price' => '10.0000', 'buy_price' => '5.0000', 'stock_quantity' => 1,
        'recorded_at' => now()->subDays(5),
    ]);
    SupplierOfferSnapshot::create([
        'sku' => 'young-feed', 'product_id' => null,
        'supplier_id' => '1', 'supplier_name' => 'X',
        'price' => '10.0000', 'stock' => 1, 'rrp' => null,
        'recorded_at' => now()->subDays(5),
    ]);

    $this->artisan('history:prune')
        ->expectsOutputToContain('Pruned 0 product_price_snapshots + 0 supplier_offer_snapshots')
        ->assertExitCode(0);

    expect(ProductPriceSnapshot::count())->toBe(1)
        ->and(SupplierOfferSnapshot::count())->toBe(1);
});

it('--days=0 is a safety no-op (does not wipe the table)', function () {
    $product = Product::factory()->create();

    ProductPriceSnapshot::create([
        'product_id' => $product->id, 'sku' => 'KEEP',
        'sell_price' => '10.0000', 'buy_price' => '5.0000', 'stock_quantity' => 1,
        'recorded_at' => now()->subDays(120),
    ]);

    $this->artisan('history:prune', ['--days' => 0])
        ->expectsOutputToContain('--days=0 is a no-op safety guard')
        ->assertExitCode(0);

    expect(ProductPriceSnapshot::count())->toBe(1);
});
