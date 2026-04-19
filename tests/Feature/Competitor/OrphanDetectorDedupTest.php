<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Services\OrphanDetector;
use App\Domain\Suggestions\Models\Suggestion;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 2 — OrphanDetector D-09 cross-competitor dedup
|--------------------------------------------------------------------------
|
| One suggestion per orphan SKU, regardless of how many competitors track
| it. Second + subsequent competitors INCREMENT supporting_competitors on
| the existing evidence JSON via updateOrCreate keyed on (kind, sku).
*/

it('creates a new_product_opportunity suggestion on first sighting', function (): void {
    $c = Competitor::factory()->create(['name' => 'AcmeAV']);

    $suggestion = app(OrphanDetector::class)->record($c, 'ORPHAN-001', 10000);

    expect($suggestion)->toBeInstanceOf(Suggestion::class);
    expect($suggestion->kind)->toBe('new_product_opportunity');
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);

    $evidence = (array) $suggestion->evidence;
    expect($evidence['sku'])->toBe('ORPHAN-001');
    expect($evidence['supporting_competitors'])->toBe(1);
    expect($evidence['competitor_sightings'])->toHaveCount(1);
    expect($evidence['competitor_sightings'][0]['competitor_id'])->toBe($c->id);
    expect($evidence['competitor_sightings'][0]['name'])->toBe('AcmeAV');
});

it('increments supporting_competitors on second competitor tracking same SKU (D-09)', function (): void {
    $c1 = Competitor::factory()->create();
    $c2 = Competitor::factory()->create();

    $detector = app(OrphanDetector::class);
    $first = $detector->record($c1, 'SHARED-SKU', 10000);
    $second = $detector->record($c2, 'SHARED-SKU', 11500);

    // Only ONE suggestion row for the SKU.
    expect(Suggestion::where('kind', 'new_product_opportunity')->count())->toBe(1);

    // The returned row is the SAME (updated) row.
    expect($first->id)->toBe($second->id);

    $evidence = (array) $second->fresh()->evidence;
    expect($evidence['supporting_competitors'])->toBe(2);
    expect($evidence['competitor_sightings'])->toHaveCount(2);
    $ids = array_column($evidence['competitor_sightings'], 'competitor_id');
    expect($ids)->toContain($c1->id, $c2->id);
});

it('is idempotent for same competitor + same SKU (no double-count)', function (): void {
    $c = Competitor::factory()->create();

    $detector = app(OrphanDetector::class);
    $detector->record($c, 'SAME-SKU', 10000);
    $suggestion = $detector->record($c, 'SAME-SKU', 10100);

    expect(Suggestion::where('kind', 'new_product_opportunity')->count())->toBe(1);

    $evidence = (array) $suggestion->fresh()->evidence;
    expect($evidence['supporting_competitors'])->toBe(1);
    expect($evidence['competitor_sightings'])->toHaveCount(1);
});
