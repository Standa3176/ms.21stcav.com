<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Commands\SupplierDbSyncCommand;
use App\Domain\Sync\Models\ProductSupplierBlock;
use App\Domain\Sync\Services\ProductSupplierBlockResolver;
use App\Domain\Sync\Services\SupplierExclusionResolver;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 261027-duz — buildBestOfferMap per-(key, supplier) offer block
|--------------------------------------------------------------------------
|
| Incident 2026-10-27: local SKU 1301222 (Yealink T74U, £88.20 at Nimans) was
| costed at £7.20 because Midwich's suppliersku 1301222 is an unrelated item.
| A block on (1301222, MIDWICH) drops Midwich's price AND stock for THAT key
| only — the same Midwich row still counts under its own mpn key.
|
| Synthesised rows + public buildBestOfferMap() (no mysqli), mirroring
| SupplierDbSyncExclusionTest.
*/

function makeOfferBlockSyncCommand(): SupplierDbSyncCommand
{
    return new SupplierDbSyncCommand(
        app(IntegrationCredentialResolver::class),
        app(SupplierFreshnessResolver::class),
        excludeStaleSuppliersFromBuyPrice: false,
    );
}

function forgetOfferBlockCaches(): void
{
    app(ProductSupplierBlockResolver::class)->forget();
    app(SupplierExclusionResolver::class)->forget();
    app(SupplierFreshnessResolver::class)->forget();
}

/** @return array<int, array<string, string>> */
function yealinkCollisionRows(): array
{
    return [
        ['mpn' => 'MW-CABLE-X', 'suppliersku' => '1301222', 'supplierid' => 'MIDWICH', 'supplier_name' => 'Midwich', 'price' => '7.20', 'stock' => '50'],
        ['mpn' => 'T74U', 'suppliersku' => '1301222', 'supplierid' => 'NIMANS', 'supplier_name' => 'Nimans', 'price' => '88.20', 'stock' => '3'],
    ];
}

it('a block drops the supplier price AND stock for that key only', function (): void {
    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => 'MIDWICH', 'reason' => 'collision']);
    forgetOfferBlockCaches();

    $map = makeOfferBlockSyncCommand()->buildBestOfferMap(yealinkCollisionRows());

    expect($map['1301222']['buy'])->toBe('88.20')
        ->and($map['1301222']['supplier'])->toBe('Nimans')
        ->and($map['1301222']['stock'])->toBe(3)
        ->and($map['1301222']['cost_outlier'])->toBeNull()
        // Per KEY, not per row: Midwich's own mpn key is untouched.
        ->and($map['mw-cable-x']['buy'])->toBe('7.20')
        ->and($map['mw-cable-x']['supplier'])->toBe('Midwich')
        ->and($map['mw-cable-x']['stock'])->toBe(50);
});

it('blocked offers are removed BEFORE the outlier guard (no outlier remains)', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => true]);
    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => 'MIDWICH', 'reason' => 'collision']);
    forgetOfferBlockCaches();

    $map = makeOfferBlockSyncCommand()->buildBestOfferMap(yealinkCollisionRows());

    expect($map['1301222']['cost_outlier'])->toBeNull()
        ->and($map['1301222']['buy'])->toBe('88.20');
});

it('a block on a different supplier leaves the key alone', function (): void {
    config(['supplier.cost_outlier_guard.enabled' => false]);
    ProductSupplierBlock::create(['sku' => '1301222', 'supplier_id' => 'SOMEONE', 'reason' => 'x']);
    forgetOfferBlockCaches();

    $map = makeOfferBlockSyncCommand()->buildBestOfferMap(yealinkCollisionRows());

    expect($map['1301222']['buy'])->toBe('7.20')
        ->and($map['1301222']['stock'])->toBe(53);
});

it('a sole-source blocked key yields no offer (key absent)', function (): void {
    ProductSupplierBlock::create(['sku' => 'only-mw', 'supplier_id' => 'MIDWICH', 'reason' => 'x']);
    forgetOfferBlockCaches();

    $map = makeOfferBlockSyncCommand()->buildBestOfferMap([
        ['mpn' => '', 'suppliersku' => 'ONLY-MW', 'supplierid' => 'MIDWICH', 'supplier_name' => 'Midwich', 'price' => '5.00', 'stock' => '9'],
    ]);

    expect($map)->not->toHaveKey('only-mw');
});
