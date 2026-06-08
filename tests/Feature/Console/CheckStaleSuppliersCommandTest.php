<?php

declare(strict_types=1);

use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Models\Supplier;
use App\Domain\Sync\Models\SupplierFreshnessSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260608-g8x — suppliers:check-stale command coverage
|--------------------------------------------------------------------------
|
| 3 cases:
|   A — --dry-run prints stats, writes NEITHER suppliers NOR snapshot rows
|   B — live run upserts suppliers + TRUNCATE-and-replaces snapshots; re-run
|       keeps the row count + assigns a NEW run_id
|   C — mixed-status snapshot covers all 3 supplier_offer states + 1 unknown
*/

function seedFreshness(string $sid, int $daysAgo): void
{
    SupplierOfferSnapshot::create([
        'sku' => 'sku-'.strtolower($sid),
        'product_id' => null,
        'supplier_id' => $sid,
        'supplier_name' => $sid.'Sup',
        'price' => 1.00,
        'stock' => 1,
        'rrp' => 1.00,
        'recorded_at' => today()->subDays($daysAgo),
    ]);
}

it('Case A: --dry-run prints counts and writes NOTHING (snapshot table + suppliers table)', function (): void {
    seedFreshness('FRESH1', 0);
    seedFreshness('AMBER1', 5);
    seedFreshness('STALE1', 15);

    $this->artisan('suppliers:check-stale', ['--dry-run' => true])
        ->expectsOutputToContain('fresh: 1 | amber: 1 | stale: 1 | unknown: 0')
        ->assertExitCode(0);

    expect(DB::table('supplier_freshness_snapshots')->count())->toBe(0);
    expect(Supplier::query()->count())->toBe(0);
});

it('Case B: live TRUNCATE-and-replace — re-run produces the same row count under a new run_id', function (): void {
    seedFreshness('S1', 0);
    seedFreshness('S2', 5);
    seedFreshness('S3', 15);

    $this->artisan('suppliers:check-stale')->assertExitCode(0);

    expect(Supplier::query()->count())->toBe(3);
    expect(DB::table('supplier_freshness_snapshots')->count())->toBe(3);
    $firstRunIds = DB::table('supplier_freshness_snapshots')
        ->select('run_id')
        ->distinct()
        ->pluck('run_id');
    expect($firstRunIds)->toHaveCount(1);
    $firstRunId = $firstRunIds->first();

    // Re-run live → must STAY at 3 rows (truncate-and-replace), new run_id.
    $this->artisan('suppliers:check-stale')->assertExitCode(0);

    expect(DB::table('supplier_freshness_snapshots')->count())->toBe(3);
    $secondRunIds = DB::table('supplier_freshness_snapshots')
        ->select('run_id')
        ->distinct()
        ->pluck('run_id');
    expect($secondRunIds)->toHaveCount(1);
    expect($secondRunIds->first())->not->toBe($firstRunId);
});

it('Case C: mixed-status snapshot — fresh/stale/unknown all appear via current() scope', function (): void {
    seedFreshness('LIVE', 0);   // → fresh
    seedFreshness('SILENT', 15); // → stale
    // dormant: row in suppliers, zero snapshots
    Supplier::create(['supplier_id' => 'GHOST', 'name' => 'GhostSupplier']);

    $this->artisan('suppliers:check-stale')->assertExitCode(0);

    $statuses = SupplierFreshnessSnapshot::query()
        ->current()
        ->pluck('status')
        ->sort()
        ->values()
        ->all();

    expect($statuses)->toBe(['fresh', 'stale', 'unknown']);
});
