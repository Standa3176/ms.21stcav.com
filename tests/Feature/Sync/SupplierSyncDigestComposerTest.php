<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Services\SupplierSyncDigestComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Stock-updater parity glue — SupplierSyncDigestComposer
|--------------------------------------------------------------------------
|
| Builds the 4-section payload consumed by SupplierSyncDigestMail. Tests
| exercise each section against synthetic snapshots so the digest's
| accuracy doesn't depend on a live supplier:db-sync run.
*/

it('returns the expected payload shape', function (): void {
    $payload = app(SupplierSyncDigestComposer::class)->compose();

    expect($payload)->toHaveKeys([
        'window_start', 'window_end',
        'totals', 'price_changes', 'stock_changes',
        'flipped_pending', 'missing_supplier_offer',
    ]);
    expect($payload['totals'])->toHaveKeys([
        'products', 'with_buy_price', 'pending', 'missing_supplier_offer',
    ]);
});

it('totals reflect the current Products table state', function (): void {
    Product::factory()->create(['status' => 'publish', 'buy_price' => 100]);
    Product::factory()->create(['status' => 'publish', 'buy_price' => 50]);
    Product::factory()->create(['status' => 'pending', 'buy_price' => null]);

    $totals = app(SupplierSyncDigestComposer::class)->compose()['totals'];

    expect($totals['products'])->toBe(3);
    expect($totals['with_buy_price'])->toBe(2);
    expect($totals['pending'])->toBe(1);
});

it('price_changes lists products whose buy_price changed today vs the prior snapshot', function (): void {
    $product = Product::factory()->create(['sku' => 'PC-001', 'name' => 'Camera']);

    ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'PC-001',
        'recorded_at' => today()->subDay(),
        'woo_status' => 'publish',
        'sell_price' => 120,
        'buy_price' => 100,
        'stock_quantity' => 5,
    ]);
    ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'PC-001',
        'recorded_at' => today(),
        'woo_status' => 'publish',
        'sell_price' => 156,
        'buy_price' => 130,
        'stock_quantity' => 5,
    ]);

    $payload = app(SupplierSyncDigestComposer::class)->compose();

    expect($payload['price_changes'])->toHaveCount(1);
    expect($payload['price_changes'][0]['sku'])->toBe('PC-001');
    expect($payload['price_changes'][0]['old'])->toBe(100.0);
    expect($payload['price_changes'][0]['new'])->toBe(130.0);
    expect($payload['price_changes'][0]['delta_pct'])->toBe(30.0);
});

it('stock_changes lists products whose stock_quantity changed today vs the prior snapshot', function (): void {
    $product = Product::factory()->create(['sku' => 'ST-001', 'name' => 'Mic']);

    ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'ST-001',
        'recorded_at' => today()->subDay(),
        'woo_status' => 'publish',
        'sell_price' => 50,
        'buy_price' => 30,
        'stock_quantity' => 10,
    ]);
    ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => 'ST-001',
        'recorded_at' => today(),
        'woo_status' => 'publish',
        'sell_price' => 50,
        'buy_price' => 30,
        'stock_quantity' => 2,
    ]);

    $payload = app(SupplierSyncDigestComposer::class)->compose();

    expect($payload['stock_changes'])->toHaveCount(1);
    expect($payload['stock_changes'][0]['sku'])->toBe('ST-001');
    expect($payload['stock_changes'][0]['old'])->toBe(10);
    expect($payload['stock_changes'][0]['new'])->toBe(2);
});

it('flipped_pending lists products whose status went pending in the digest window', function (): void {
    $product = Product::factory()->create([
        'status' => 'pending',
        'buy_price' => null,
        'name' => 'Recently flipped',
    ]);
    // Force updated_at into the digest window (last 24h)
    $product->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

    $payload = app(SupplierSyncDigestComposer::class)->compose();

    $flipped = collect($payload['flipped_pending']);
    expect($flipped->pluck('sku'))->toContain($product->sku);
});

it('missing_supplier_offer lists published SKUs with no SupplierOfferSnapshot for today', function (): void {
    $offered = Product::factory()->create(['sku' => 'OFFERED', 'status' => 'publish']);
    $missing = Product::factory()->create(['sku' => 'MISSING', 'status' => 'publish']);

    SupplierOfferSnapshot::create([
        'sku' => 'offered',
        'product_id' => $offered->id,
        'supplier_id' => 'sup-1',
        'supplier_name' => 'Supplier 1',
        'price' => 50,
        'stock' => 5,
        'rrp' => 80,
        'recorded_at' => today(),
    ]);

    $payload = app(SupplierSyncDigestComposer::class)->compose();

    $missingSkus = collect($payload['missing_supplier_offer'])->pluck('sku');
    expect($missingSkus)->toContain('MISSING');
    expect($missingSkus)->not->toContain('OFFERED');
});
