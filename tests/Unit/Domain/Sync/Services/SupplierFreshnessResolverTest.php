<?php

declare(strict_types=1);

use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Models\Supplier;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260608-g8x — SupplierFreshnessResolver coverage
|--------------------------------------------------------------------------
|
| 6 cases:
|   A — fresh (today)
|   B — amber (today-4 with default 7d window, ratio 0.7 → boundary=4)
|   C — stale (today-9)
|   D — per-supplier override flips classification (today-14 + override=21 → fresh)
|   E — unknown (Supplier row exists, zero snapshots)
|   F — per-request cache: second call adds zero queries (DB::enableQueryLog())
*/

function seedSnapshot(string $supplierId, int $daysAgo, ?string $name = null): void
{
    SupplierOfferSnapshot::create([
        'sku' => 'sku-'.strtolower($supplierId),
        'product_id' => null,
        'supplier_id' => $supplierId,
        'supplier_name' => $name ?? ($supplierId.'Sup'),
        'price' => 1.00,
        'stock' => 1,
        'rrp' => 1.00,
        'recorded_at' => today()->subDays($daysAgo),
    ]);
}

function freshResolver(): SupplierFreshnessResolver
{
    $r = app(SupplierFreshnessResolver::class);
    $r->forget();

    return $r;
}

it('Case A: today snapshot → classify=fresh + freshSupplierIds contains it', function (): void {
    seedSnapshot('A-FRESH', daysAgo: 0);

    $r = freshResolver();
    expect($r->classify('A-FRESH'))->toBe('fresh');
    expect($r->freshSupplierIds()->all())->toContain('A-FRESH');
});

it('Case B: today-4 with default 7d window → amber (boundary = floor(7*0.7)=4)', function (): void {
    seedSnapshot('B-AMBER', daysAgo: 4);

    $r = freshResolver();
    expect($r->classify('B-AMBER'))->toBe('amber');
    expect($r->amberSupplierIds()->all())->toContain('B-AMBER');
    expect($r->freshSupplierIds()->all())->not->toContain('B-AMBER');
});

it('Case C: today-9 → stale + staleSupplierIds contains it; not in freshSupplierIds', function (): void {
    seedSnapshot('C-STALE', daysAgo: 9);

    $r = freshResolver();
    expect($r->classify('C-STALE'))->toBe('stale');
    expect($r->staleSupplierIds()->all())->toContain('C-STALE');
    expect($r->freshSupplierIds()->all())->not->toContain('C-STALE');
});

it('Case D: per-supplier stale_after_days=30 override flips a today-14 snapshot back to fresh', function (): void {
    seedSnapshot('D-OVR', daysAgo: 14);
    Supplier::create([
        'supplier_id' => 'D-OVR',
        'name' => 'NuviasLike',
        'stale_after_days' => 30,
    ]);

    $r = freshResolver();
    // 14 days < amber boundary (floor(30 * 0.7) = 21) → fresh.
    // (Without the override, 14d vs default 7d threshold = stale.)
    expect($r->classify('D-OVR'))->toBe('fresh');
    expect($r->thresholdDaysFor('D-OVR'))->toBe(30);
});

it('Case E: Supplier row without snapshots → classify=unknown; absent from fresh/amber/stale lists', function (): void {
    Supplier::create([
        'supplier_id' => 'E-DORMANT',
        'name' => 'DormantSupplier',
    ]);

    $r = freshResolver();
    expect($r->classify('E-DORMANT'))->toBe('unknown');
    expect($r->freshSupplierIds()->all())->not->toContain('E-DORMANT');
    expect($r->amberSupplierIds()->all())->not->toContain('E-DORMANT');
    expect($r->staleSupplierIds()->all())->not->toContain('E-DORMANT');
});

it('Case F: per-request cache — second call adds ZERO new queries', function (): void {
    seedSnapshot('F-CACHE', daysAgo: 0);

    $r = freshResolver();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $r->freshSupplierIds(); // first call — triggers loadCache()
    $firstCount = count(DB::getQueryLog());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $r->freshSupplierIds(); // second call — hits cache
    $secondCount = count(DB::getQueryLog());

    expect($firstCount)->toBeGreaterThan(0); // sanity: first call DID query
    expect($secondCount)->toBe(0);
});
