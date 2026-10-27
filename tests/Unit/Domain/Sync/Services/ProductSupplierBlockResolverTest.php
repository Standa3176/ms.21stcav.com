<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Models\ProductSupplierBlock;
use App\Domain\Sync\Services\ProductSupplierBlockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 261027-duz — ProductSupplierBlockResolver
|--------------------------------------------------------------------------
|
| Per-request cached (sku, supplier_id) block lookup consumed by
| supplier:db-sync (buildBestOfferMap) and products:hydrate-stock-from-offers.
*/

beforeEach(function (): void {
    app(ProductSupplierBlockResolver::class)->forget();
});

it('returns blocks for a key, looked up lowercased + trimmed', function (): void {
    ProductSupplierBlock::create([
        'sku' => '1301222',
        'supplier_id' => 'MIDWICH',
        'reason' => 'Midwich 1301222 is an unrelated item',
    ]);

    $resolver = app(ProductSupplierBlockResolver::class);

    expect($resolver->blocksFor('1301222'))->toBe(['MIDWICH' => 'Midwich 1301222 is an unrelated item'])
        ->and($resolver->blocksFor(' 1301222 '))->toBe(['MIDWICH' => 'Midwich 1301222 is an unrelated item'])
        ->and($resolver->blocksFor('other'))->toBe([]);
});

it('isBlocked is per (key, supplier)', function (): void {
    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => 'MIDWICH', 'reason' => 'x']);

    $resolver = app(ProductSupplierBlockResolver::class);

    expect($resolver->isBlocked('1301222', 'MIDWICH'))->toBeTrue()
        ->and($resolver->isBlocked('1301222', 'NIMANS'))->toBeFalse()
        ->and($resolver->isBlocked('mw-cable-x', 'MIDWICH'))->toBeFalse();
});

it('normalises the stored sku (lowercase + trim) on save', function (): void {
    $block = ProductSupplierBlock::create(['sku' => ' 83Z49AA#ABU ', 'supplier_id' => ' TECHDATA ', 'reason' => 'x']);

    expect($block->fresh()->sku)->toBe('83z49aa#abu')
        ->and($block->fresh()->supplier_id)->toBe('TECHDATA')
        ->and(app(ProductSupplierBlockResolver::class)->isBlocked('83Z49AA#ABU', 'TECHDATA'))->toBeTrue();
});

it('fills product_id from the matching product SKU when null', function (): void {
    $product = Product::factory()->create(['sku' => 'YEA-T74U']);

    $block = ProductSupplierBlock::create(['sku' => 'yea-t74u', 'supplier_id' => 'MIDWICH', 'reason' => 'x']);

    expect((int) $block->fresh()->product_id)->toBe((int) $product->id);
});

it('caches until forget()', function (): void {
    $resolver = app(ProductSupplierBlockResolver::class);

    expect($resolver->blocksFor('1301222'))->toBe([]);

    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => 'MIDWICH', 'reason' => 'x']);

    expect($resolver->isBlocked('1301222', 'MIDWICH'))->toBeFalse();

    $resolver->forget();

    expect($resolver->isBlocked('1301222', 'MIDWICH'))->toBeTrue();
});

it('is registered as a singleton', function (): void {
    expect(app(ProductSupplierBlockResolver::class))->toBe(app(ProductSupplierBlockResolver::class));
});
