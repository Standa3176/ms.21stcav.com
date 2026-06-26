<?php

declare(strict_types=1);

use App\Domain\Sync\Models\Supplier;
use App\Domain\Sync\Services\SupplierExclusionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260626-oqr — SupplierExclusionResolver unit test
|--------------------------------------------------------------------------
|
| Pins the operator-exclusion resolver (suppliers.is_active=false) that the
| sync's buildBestOfferMap consults to drop an excluded supplier's offers
| UNCONDITIONALLY. Mirrors SupplierFreshnessResolver: per-request cached,
| string-typed supplier_ids, forget() to rebuild.
*/

function seedExclusionSuppliers(): void
{
    Supplier::create(['supplier_id' => 'WCOAST', 'name' => 'Westcoast', 'is_active' => true]);
    Supplier::create(['supplier_id' => 'INGRAM', 'name' => 'Ingram', 'is_active' => true]);
    Supplier::create(['supplier_id' => 'NUVIAS', 'name' => 'Nuvias', 'is_active' => false]);
}

it('returns only is_active=false supplier_ids as strings', function (): void {
    seedExclusionSuppliers();

    $resolver = new SupplierExclusionResolver;
    $excluded = $resolver->excludedSupplierIds();

    expect($excluded)->toBeInstanceOf(Collection::class)
        ->and($excluded->all())->toBe(['NUVIAS'])
        ->and($excluded->first())->toBeString();
});

it('isExcluded is true for excluded suppliers and false for active ones', function (): void {
    seedExclusionSuppliers();

    $resolver = new SupplierExclusionResolver;

    expect($resolver->isExcluded('NUVIAS'))->toBeTrue()
        ->and($resolver->isExcluded('WCOAST'))->toBeFalse()
        ->and($resolver->isExcluded('INGRAM'))->toBeFalse();
});

it('caches per-request: a new inactive supplier is invisible until forget()', function (): void {
    seedExclusionSuppliers();

    $resolver = new SupplierExclusionResolver;

    // Prime the cache.
    expect($resolver->excludedSupplierIds()->all())->toBe(['NUVIAS']);

    // Add another inactive supplier WITHOUT forget() — cached result unchanged.
    Supplier::create(['supplier_id' => 'EXERTIS', 'name' => 'Exertis', 'is_active' => false]);
    expect($resolver->excludedSupplierIds()->all())->toBe(['NUVIAS']);

    // After forget(), the rebuild reflects BOTH inactive suppliers.
    $resolver->forget();
    expect($resolver->excludedSupplierIds()->all())
        ->toContain('NUVIAS')
        ->toContain('EXERTIS')
        ->toHaveCount(2);
});
