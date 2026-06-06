<?php

declare(strict_types=1);

use App\Domain\Dashboard\Services\SnapshotAggregator;
use App\Domain\Suggestions\Models\Suggestion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260606-lhp — SnapshotAggregator::computeSuggestionsTriageHealth
|--------------------------------------------------------------------------
|
| Drift-prevention + payload-shape contract for the new "Decision-grade
| Suggestions tiles" on the Home dashboard.
|
| Tile 1 ("High-confidence sourceable opportunities") and Tile 2 ("Decision
| queue health") both read the SAME `suggestions_triage_health` snapshot
| payload produced by SnapshotAggregator::computeSuggestionsTriageHealth().
|
| Drift-prevention: the high_confidence_count value the aggregator emits
| MUST equal `Suggestion::query()->highConfidenceSourceable()->count()` —
| the same scope SuggestionResource::getNavigationBadge() consumes. One
| predicate, one source of truth.
*/

beforeEach(function (): void {
    // Lock "now" so oldest_pending_days is deterministic across all
    // test cases (the diffInDays floor compares proposed_at to now()).
    Carbon::setTestNow('2026-06-06 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns a 6-key payload matching the seeded predicate matrix', function (): void {
    // Row A — pending NPO, 3 competitors, ON supplier DB, 40 days old.
    //         High-confidence + sourceable + raw-pending + oldest-pending.
    seedSuggestion('IN-CACHE-3C', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_PENDING, competitors: 3, proposedDaysAgo: 40);
    DB::table('supplier_sku_cache')->insert(['sku' => 'in-cache-3c']);

    // Row B — pending NPO, 1 competitor, ON supplier DB, 5 days old.
    //         Sourceable + raw-pending. NOT high-confidence (<3 competitors).
    seedSuggestion('IN-CACHE-1C', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_PENDING, competitors: 1, proposedDaysAgo: 5);
    DB::table('supplier_sku_cache')->insert(['sku' => 'in-cache-1c']);

    // Row C — pending NPO, 3 competitors, OFF supplier DB, 20 days old.
    //         Raw-pending only. NOT sourceable. NOT high-confidence.
    seedSuggestion('ORPHAN-3C', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_PENDING, competitors: 3, proposedDaysAgo: 20);

    // Row D — pending margin_change (NOT new_product_opportunity).
    //         Excluded from ALL counts (kind gate).
    seedSuggestion('MARGIN-1', kind: 'margin_change',
        status: Suggestion::STATUS_PENDING, competitors: 5, proposedDaysAgo: 99);

    // Row E — applied NPO, resolved 3 days ago. Counts toward applied_7d.
    seedSuggestion('APPLIED-3D', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_APPLIED, competitors: 2, proposedDaysAgo: 30,
        resolvedDaysAgo: 3);

    // Row F — rejected NPO, resolved 2 days ago. Counts toward rejected_7d.
    seedSuggestion('REJECTED-2D', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_REJECTED, competitors: 2, proposedDaysAgo: 30,
        resolvedDaysAgo: 2);

    // Row G — applied NPO, resolved 10 days ago. OUTSIDE 7d window.
    //         Must NOT count toward applied_7d.
    seedSuggestion('APPLIED-10D', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_APPLIED, competitors: 2, proposedDaysAgo: 30,
        resolvedDaysAgo: 10);

    $payload = app(SnapshotAggregator::class)->computeSuggestionsTriageHealth();

    expect($payload['high_confidence_count'])->toBe(1, 'Row A only');
    expect($payload['sourceable_count'])->toBe(2, 'Rows A + B');
    expect($payload['raw_pending_count'])->toBe(3, 'Rows A + B + C');
    expect($payload['applied_7d'])->toBe(1, 'Row E only (Row G outside window)');
    expect($payload['rejected_7d'])->toBe(1, 'Row F only');
    expect($payload['oldest_pending_days'])->toBe(40, 'Row A — diffInDays floor');
});

it('agrees byte-for-byte with Suggestion::scopeHighConfidenceSourceable (drift-prevention)', function (): void {
    // Same matrix as above (sans the resolved/applied rows — irrelevant to
    // the high_confidence_count vs scope agreement check).
    seedSuggestion('IN-CACHE-3C', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_PENDING, competitors: 3, proposedDaysAgo: 40);
    DB::table('supplier_sku_cache')->insert(['sku' => 'in-cache-3c']);

    seedSuggestion('IN-CACHE-1C', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_PENDING, competitors: 1, proposedDaysAgo: 5);
    DB::table('supplier_sku_cache')->insert(['sku' => 'in-cache-1c']);

    seedSuggestion('ORPHAN-3C', kind: 'new_product_opportunity',
        status: Suggestion::STATUS_PENDING, competitors: 3, proposedDaysAgo: 20);

    $payload = app(SnapshotAggregator::class)->computeSuggestionsTriageHealth();
    $scopeCount = Suggestion::query()->highConfidenceSourceable()->count();

    expect($payload['high_confidence_count'])->toBe($scopeCount);
    expect($scopeCount)->toBe(1);
});

it('returns the zero-payload with null oldest_pending_days for an empty DB', function (): void {
    $payload = app(SnapshotAggregator::class)->computeSuggestionsTriageHealth();

    expect($payload['high_confidence_count'])->toBe(0);
    expect($payload['sourceable_count'])->toBe(0);
    expect($payload['raw_pending_count'])->toBe(0);
    expect($payload['applied_7d'])->toBe(0);
    expect($payload['rejected_7d'])->toBe(0);
    expect($payload['oldest_pending_days'])->toBeNull();
});

it('exposes exactly the 6 expected keys (no missing, no stray)', function (): void {
    $payload = app(SnapshotAggregator::class)->computeSuggestionsTriageHealth();

    expect(array_keys($payload))->toEqualCanonicalizing([
        'high_confidence_count',
        'sourceable_count',
        'raw_pending_count',
        'applied_7d',
        'rejected_7d',
        'oldest_pending_days',
    ]);
});

// ── helpers ──────────────────────────────────────────────────────────────

function seedSuggestion(
    string $sku,
    string $kind,
    string $status,
    int $competitors,
    int $proposedDaysAgo,
    ?int $resolvedDaysAgo = null,
): Suggestion {
    $attrs = [
        'kind' => $kind,
        'status' => $status,
        'correlation_id' => 'lhp-'.uniqid(),
        'payload' => [],
        'evidence' => [
            'sku' => $sku,
            'supporting_competitors' => $competitors,
            'competitor_sightings' => array_map(
                fn (int $i) => ['name' => "Competitor {$i}", 'price_gross_pennies' => 9999],
                range(1, max($competitors, 1)),
            ),
        ],
        'proposed_at' => now()->subDays($proposedDaysAgo),
    ];

    if ($resolvedDaysAgo !== null) {
        $attrs['resolved_at'] = now()->subDays($resolvedDaysAgo);
    }

    return Suggestion::forceCreate($attrs);
}
