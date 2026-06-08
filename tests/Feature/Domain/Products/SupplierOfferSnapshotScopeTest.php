<?php

declare(strict_types=1);

use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260608-g8x — freshOnly() scope coverage
|--------------------------------------------------------------------------
|
| Single focused case: SOS::query()->freshOnly()->pluck('supplier_id') yields
| only the FRESH supplier ids — the SILENT supplier (10 days stale) is
| excluded; the '__NO_FRESH_SUPPLIERS__' string sentinel never appears.
*/

it('freshOnly() returns only FRESH supplier rows; SILENT excluded; sentinel never returned', function (): void {
    SupplierOfferSnapshot::create([
        'sku' => 'sku-fresh',
        'product_id' => null,
        'supplier_id' => 'FRESH',
        'supplier_name' => 'FreshSupplier',
        'price' => 1.00,
        'stock' => 5,
        'rrp' => 1.00,
        'recorded_at' => today(),
    ]);

    SupplierOfferSnapshot::create([
        'sku' => 'sku-silent',
        'product_id' => null,
        'supplier_id' => 'SILENT',
        'supplier_name' => 'SilentSupplier',
        'price' => 1.00,
        'stock' => 5,
        'rrp' => 1.00,
        'recorded_at' => today()->subDays(30),
    ]);

    app(SupplierFreshnessResolver::class)->forget();

    $ids = SupplierOfferSnapshot::query()->freshOnly()->pluck('supplier_id')->all();

    expect($ids)->toBe(['FRESH']);
    expect($ids)->not->toContain('SILENT');
    expect($ids)->not->toContain('__NO_FRESH_SUPPLIERS__');
});
