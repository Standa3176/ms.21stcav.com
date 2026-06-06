<?php

declare(strict_types=1);

use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260606-gnu — suggestions:prune-orphans
|--------------------------------------------------------------------------
|
| Auto-rejects stale competitor-only orphan new_product_opportunity rows.
| Gate is the 3-way conjunction:
|   - kind = 'new_product_opportunity'
|   - status = 'pending'
|   - proposed_at older than --days (default 14)
|   - evidence.supporting_competitors < 2
|   - sku NOT in supplier_sku_cache (off-supplier-DB)
|
| Rows that fail ANY of the three age/competitors/supplier gates stay pending.
| --dry-run prints count + sample without writing.
| Mon 06:00 London cron runs this BEFORE supplier:db-sync at 07:00.
*/

it('registers suggestions:prune-orphans as an artisan command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('suggestions:prune-orphans');
});

it('rejects only the stale off-supplier <2-competitor row in the 2x2 matrix', function (): void {
    // Row A — off-supplier, 1 competitor, 60 days old → MUST be rejected
    $a = makeOrphanSuggestion('ORPH-OLD-1C', competitors: 1, ageDays: 60);

    // Row B — off-supplier, 1 competitor, 5 days old → MUST stay pending (too fresh)
    $b = makeOrphanSuggestion('ORPH-FRESH-1C', competitors: 1, ageDays: 5);

    // Row C — ON supplier DB, 1 competitor, 60 days old → MUST stay pending (sourceable)
    $c = makeOrphanSuggestion('SOURCEABLE-1C', competitors: 1, ageDays: 60);
    DB::table('supplier_sku_cache')->insert(['sku' => 'sourceable-1c']);

    // Row D — off-supplier, 3 competitors, 60 days old → MUST stay pending (high-signal)
    $d = makeOrphanSuggestion('ORPH-OLD-3C', competitors: 3, ageDays: 60);

    Artisan::call('suggestions:prune-orphans');

    // Row A: rejected
    expect($a->fresh()->status)->toBe(Suggestion::STATUS_REJECTED);
    expect($a->fresh()->rejection_reason)->toStartWith('auto-rejected: stale');
    expect($a->fresh()->resolved_at)->not->toBeNull();

    // Rows B / C / D — untouched
    expect($b->fresh()->status)->toBe(Suggestion::STATUS_PENDING);
    expect($b->fresh()->rejection_reason)->toBeNull();
    expect($c->fresh()->status)->toBe(Suggestion::STATUS_PENDING);
    expect($c->fresh()->rejection_reason)->toBeNull();
    expect($d->fresh()->status)->toBe(Suggestion::STATUS_PENDING);
    expect($d->fresh()->rejection_reason)->toBeNull();
});

it('--dry-run does not modify any rows', function (): void {
    $a = makeOrphanSuggestion('ORPH-OLD-1C', competitors: 1, ageDays: 60);

    Artisan::call('suggestions:prune-orphans', ['--dry-run' => true]);

    // Row A stays pending despite matching all 3 gates
    expect($a->fresh()->status)->toBe(Suggestion::STATUS_PENDING);
    expect($a->fresh()->rejection_reason)->toBeNull();
    expect($a->fresh()->resolved_at)->toBeNull();

    // Command output mentions the candidate count
    $output = Artisan::output();
    expect($output)->toContain('1');
    expect(strtolower($output))->toContain('dry-run');
});

it('--days=90 leaves 60-day-old rows alone', function (): void {
    $a = makeOrphanSuggestion('ORPH-OLD-1C', competitors: 1, ageDays: 60);

    Artisan::call('suggestions:prune-orphans', ['--days' => 90]);

    // 60 days < 90 days cutoff → stays pending
    expect($a->fresh()->status)->toBe(Suggestion::STATUS_PENDING);
    expect($a->fresh()->rejection_reason)->toBeNull();
});

it('returns 0 on success', function (): void {
    expect(Artisan::call('suggestions:prune-orphans'))->toBe(0);
});

// ── helpers ──

function makeOrphanSuggestion(string $sku, int $competitors, int $ageDays): Suggestion
{
    return Suggestion::forceCreate([
        'kind' => 'new_product_opportunity',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'orphan-'.uniqid(),
        'payload' => [],
        'evidence' => [
            'sku' => $sku,
            'supporting_competitors' => $competitors,
            'competitor_sightings' => array_map(
                fn (int $i) => ['name' => "Competitor {$i}", 'price_gross_pennies' => 9999],
                range(1, $competitors),
            ),
        ],
        'proposed_at' => now()->subDays($ageDays),
    ]);
}
