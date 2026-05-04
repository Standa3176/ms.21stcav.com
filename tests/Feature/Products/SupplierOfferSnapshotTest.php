<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a SupplierOfferSnapshot with correct casts', function () {
    $product = Product::factory()->create();

    $snapshot = SupplierOfferSnapshot::create([
        'sku' => 'feed-sku-001',
        'product_id' => $product->id,
        'supplier_id' => '12',
        'supplier_name' => 'Nuvias',
        'price' => '99.9900',
        'stock' => 17,
        'rrp' => '149.0000',
        'recorded_at' => '2026-05-04',
    ]);

    expect($snapshot->fresh())
        ->recorded_at->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($snapshot->fresh()->recorded_at->format('Y-m-d'))->toBe('2026-05-04')
        ->and((string) $snapshot->fresh()->price)->toBe('99.9900')
        ->and((string) $snapshot->fresh()->rrp)->toBe('149.0000')
        ->and($snapshot->fresh()->stock)->toBe(17)
        ->and($snapshot->fresh()->supplier_id)->toBe('12');
});

it('allows nullable product_id (offers for unmatched SKUs)', function () {
    $snapshot = SupplierOfferSnapshot::create([
        'sku' => 'orphan-sku',
        'product_id' => null,
        'supplier_id' => '5',
        'supplier_name' => 'Tech Data',
        'price' => '50.0000',
        'stock' => null,
        'rrp' => null,
        'recorded_at' => today(),
    ]);

    expect($snapshot->product_id)->toBeNull()
        ->and($snapshot->product)->toBeNull();
});

it('belongs to a Product when product_id is set', function () {
    $product = Product::factory()->create(['sku' => 'btso-001']);

    $snapshot = SupplierOfferSnapshot::create([
        'sku' => 'btso-001',
        'product_id' => $product->id,
        'supplier_id' => '7',
        'supplier_name' => 'Ingram',
        'price' => '25.0000',
        'stock' => 3,
        'rrp' => '40.0000',
        'recorded_at' => today(),
    ]);

    expect($snapshot->product?->id)->toBe($product->id)
        ->and($snapshot->product?->sku)->toBe('btso-001');
});

it('enforces unique (sku, supplier_id, recorded_at)', function () {
    SupplierOfferSnapshot::create([
        'sku' => 'dup-key',
        'product_id' => null,
        'supplier_id' => '3',
        'supplier_name' => 'X',
        'price' => '10.0000',
        'stock' => 1,
        'rrp' => null,
        'recorded_at' => '2026-05-04',
    ]);

    expect(fn () => SupplierOfferSnapshot::create([
        'sku' => 'dup-key',
        'product_id' => null,
        'supplier_id' => '3',
        'supplier_name' => 'Y',
        'price' => '20.0000',
        'stock' => 2,
        'rrp' => null,
        'recorded_at' => '2026-05-04',
    ]))->toThrow(QueryException::class);
});

it('allows different suppliers for the same SKU on the same day', function () {
    SupplierOfferSnapshot::create([
        'sku' => 'multi-supplier',
        'product_id' => null,
        'supplier_id' => '1',
        'supplier_name' => 'A',
        'price' => '10.0000',
        'stock' => 1,
        'rrp' => null,
        'recorded_at' => '2026-05-04',
    ]);
    SupplierOfferSnapshot::create([
        'sku' => 'multi-supplier',
        'product_id' => null,
        'supplier_id' => '2',
        'supplier_name' => 'B',
        'price' => '11.0000',
        'stock' => 2,
        'rrp' => null,
        'recorded_at' => '2026-05-04',
    ]);

    expect(SupplierOfferSnapshot::where('sku', 'multi-supplier')->count())->toBe(2);
});
