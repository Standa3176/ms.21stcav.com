<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Models\Supplier;
use App\Domain\Sync\Services\SupplierExclusionResolver;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260626-oqr — buildBestOfferMap operator-exclusion drop
|--------------------------------------------------------------------------
|
| Pins the UNCONDITIONAL drop of operator-excluded suppliers
| (suppliers.is_active=false) in buildBestOfferMap, ahead of the existing
| stale filter. Mirrors SupplierDbSyncStaleSupplierTest: synthesise $rows
| and call the public buildBestOfferMap() directly (no mysqli, no remote DB).
|
| Key contrast with the stale mechanism:
|   - stale exclusion is gated by excludeStaleSuppliersFromBuyPrice (has an OFF)
|   - operator exclusion is UNCONDITIONAL (no OFF; outranks freshness policy)
|
| The exclusion resolver is injected from the container (NOT constructed
| inline) so its is_active=false query runs against the seeded suppliers.
*/

function makeExclusionSyncCommand(bool $excludeStale = true): SupplierDbSyncCommand
{
    // Let the container inject the SupplierExclusionResolver (nullable last
    // arg resolves from app() when null) so it sees the seeded suppliers.
    return new SupplierDbSyncCommand(
        app(IntegrationCredentialResolver::class),
        app(SupplierFreshnessResolver::class),
        excludeStaleSuppliersFromBuyPrice: $excludeStale,
    );
}

function forgetSyncCaches(): void
{
    app(SupplierExclusionResolver::class)->forget();
    app(SupplierFreshnessResolver::class)->forget();
}

it('Test A: drops the excluded (cheapest) supplier; WCOAST sets buy + stock', function (): void {
    Supplier::create(['supplier_id' => 'NUVIAS', 'name' => 'Nuvias', 'is_active' => false]);
    Supplier::create(['supplier_id' => 'WCOAST', 'name' => 'Westcoast', 'is_active' => true]);

    $rows = [
        ['mpn' => 'PART-X', 'suppliersku' => 'N-1', 'supplierid' => 'NUVIAS', 'supplier_name' => 'Nuvias', 'price' => '1.00', 'stock' => '5'],
        ['mpn' => 'PART-X', 'suppliersku' => 'W-1', 'supplierid' => 'WCOAST', 'supplier_name' => 'Westcoast', 'price' => '9.00', 'stock' => '5'],
    ];

    forgetSyncCaches();
    $map = makeExclusionSyncCommand(excludeStale: true)->buildBestOfferMap($rows);

    // NUVIAS dropped despite being cheapest in-stock — WCOAST wins.
    expect($map['part-x']['buy'])->toBe('9.00')
        ->and($map['part-x']['supplier'])->toBe('Westcoast')
        ->and($map['part-x']['in_stock'])->toBeTrue()
        // Total stock reflects ONLY WCOAST (5), not 10 — NUVIAS stock gone too.
        ->and($map['part-x']['stock'])->toBe(5);
});

it('Test B: exclusion is UNCONDITIONAL — still drops NUVIAS with excludeStale=false', function (): void {
    Supplier::create(['supplier_id' => 'NUVIAS', 'name' => 'Nuvias', 'is_active' => false]);
    Supplier::create(['supplier_id' => 'WCOAST', 'name' => 'Westcoast', 'is_active' => true]);

    $rows = [
        ['mpn' => 'PART-X', 'suppliersku' => 'N-1', 'supplierid' => 'NUVIAS', 'supplier_name' => 'Nuvias', 'price' => '1.00', 'stock' => '5'],
        ['mpn' => 'PART-X', 'suppliersku' => 'W-1', 'supplierid' => 'WCOAST', 'supplier_name' => 'Westcoast', 'price' => '9.00', 'stock' => '5'],
    ];

    forgetSyncCaches();
    // Stale flag OFF would keep a STALE supplier — but the exclusion flag has
    // no OFF, so NUVIAS is STILL dropped.
    $map = makeExclusionSyncCommand(excludeStale: false)->buildBestOfferMap($rows);

    expect($map['part-x']['buy'])->toBe('9.00')
        ->and($map['part-x']['supplier'])->toBe('Westcoast')
        ->and($map['part-x']['stock'])->toBe(5);
});

it('Test C: excluded sole-source SKU yields NO offer (key absent)', function (): void {
    Supplier::create(['supplier_id' => 'NUVIAS', 'name' => 'Nuvias', 'is_active' => false]);

    $rows = [
        ['mpn' => 'PART-Z', 'suppliersku' => 'N-Z', 'supplierid' => 'NUVIAS', 'supplier_name' => 'Nuvias', 'price' => '2.00', 'stock' => '7'],
    ];

    forgetSyncCaches();
    $map = makeExclusionSyncCommand(excludeStale: true)->buildBestOfferMap($rows);

    // Offer dropped, no fallback invented — key absent (flows through the
    // existing no-fresh-source handling, same as all-stale).
    expect($map)->not->toHaveKey('part-z')
        ->and($map)->toBe([]);
});
