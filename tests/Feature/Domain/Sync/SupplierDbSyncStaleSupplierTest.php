<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260608-g8x — SupplierDbSyncCommand stale-supplier exclusion
|--------------------------------------------------------------------------
|
| Pins the wiring of SupplierFreshnessResolver into the buy_price selector.
|
| Default (flag ON): buy_price is chosen among FRESH suppliers only — a
| stale (silent) supplier never sets cost downstream, even if it is the
| cheapest in-stock row in the synthesized $rows.
|
| Override (flag OFF): byte-identical to the 2026-05-25 cheapest-in-stock
| selection (back-compat).
|
| Test pattern: synthesise `$rows` (the shape feeds_products returns), seed
| supplier_offer_snapshots so the resolver classifies SILENT as stale, then
| call the public buildBestOfferMap() directly. No mysqli; no remote DB.
*/

function makeStaleSyncCommand(bool $excludeStale = true): SupplierDbSyncCommand
{
    return new SupplierDbSyncCommand(
        app(IntegrationCredentialResolver::class),
        app(SupplierFreshnessResolver::class),
        excludeStaleSuppliersFromBuyPrice: $excludeStale,
    );
}

function seedStaleAndFreshSnapshots(): void
{
    // SILENT supplier — last snapshot 30 days ago → resolver classifies stale.
    SupplierOfferSnapshot::create([
        'sku' => 'tst-x',
        'product_id' => null,
        'supplier_id' => 'SILENT',
        'supplier_name' => 'SilentSupplier',
        'price' => 1.00,
        'stock' => 5,
        'rrp' => 5.00,
        'recorded_at' => today()->subDays(30),
    ]);
    // FRESH supplier — today.
    SupplierOfferSnapshot::create([
        'sku' => 'tst-x',
        'product_id' => null,
        'supplier_id' => 'FRESH',
        'supplier_name' => 'FreshSupplier',
        'price' => 9.00,
        'stock' => 5,
        'rrp' => 12.00,
        'recorded_at' => today(),
    ]);
}

it('Test A: flag ON picks the FRESH (higher-priced) supplier; flag OFF picks SILENT (cheapest)', function (): void {
    seedStaleAndFreshSnapshots();

    $rows = [
        ['mpn' => 'PART-X', 'suppliersku' => 'S-1', 'supplierid' => 'SILENT', 'supplier_name' => 'SilentSupplier', 'price' => '1.00', 'stock' => '5'],
        ['mpn' => 'PART-X', 'suppliersku' => 'F-1', 'supplierid' => 'FRESH', 'supplier_name' => 'FreshSupplier', 'price' => '9.00', 'stock' => '5'],
    ];

    // Flag ON: SILENT filtered out BEFORE the cheapest-in-stock reduction.
    $mapOn = makeStaleSyncCommand(excludeStale: true)->buildBestOfferMap($rows);
    expect($mapOn['part-x']['buy'])->toBe('9.00')
        ->and($mapOn['part-x']['supplier'])->toBe('FreshSupplier')
        ->and($mapOn['part-x']['in_stock'])->toBeTrue();

    // Reset cache so the second call (flag OFF) re-reads — singleton hangover.
    app(SupplierFreshnessResolver::class)->forget();

    // Flag OFF: original cheapest-overall wins.
    $mapOff = makeStaleSyncCommand(excludeStale: false)->buildBestOfferMap($rows);
    expect($mapOff['part-x']['buy'])->toBe('1.00')
        ->and($mapOff['part-x']['supplier'])->toBe('SilentSupplier');
});

it('Test B: ALL-stale rows produce empty map when flag ON; cheapest-overall when flag OFF', function (): void {
    // Two stale suppliers + their snapshots so the resolver knows both as stale.
    SupplierOfferSnapshot::create([
        'sku' => 'tst-y', 'product_id' => null,
        'supplier_id' => 'STALE1', 'supplier_name' => 'Stale1',
        'price' => 5, 'stock' => 5, 'rrp' => 10, 'recorded_at' => today()->subDays(20),
    ]);
    SupplierOfferSnapshot::create([
        'sku' => 'tst-y', 'product_id' => null,
        'supplier_id' => 'STALE2', 'supplier_name' => 'Stale2',
        'price' => 3, 'stock' => 5, 'rrp' => 10, 'recorded_at' => today()->subDays(20),
    ]);

    $rows = [
        ['mpn' => 'PART-Y', 'suppliersku' => 'S1', 'supplierid' => 'STALE1', 'supplier_name' => 'Stale1', 'price' => '5.00', 'stock' => '5'],
        ['mpn' => 'PART-Y', 'suppliersku' => 'S2', 'supplierid' => 'STALE2', 'supplier_name' => 'Stale2', 'price' => '3.00', 'stock' => '5'],
    ];

    $mapOn = makeStaleSyncCommand(excludeStale: true)->buildBestOfferMap($rows);
    expect($mapOn)->toBe([]); // every offer filtered → empty map

    app(SupplierFreshnessResolver::class)->forget();

    $mapOff = makeStaleSyncCommand(excludeStale: false)->buildBestOfferMap($rows);
    expect($mapOff['part-y']['buy'])->toBe('3.00')
        ->and($mapOff['part-y']['supplier'])->toBe('Stale2');
});
