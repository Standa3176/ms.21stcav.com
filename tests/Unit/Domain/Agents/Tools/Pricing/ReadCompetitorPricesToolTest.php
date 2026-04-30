<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Pricing\ReadCompetitorPricesTool;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 02 Task 1 — ReadCompetitorPricesTool real-impl unit tests
|--------------------------------------------------------------------------
|
| Validates:
|   - 90d window enforcement (CONTEXT D-04)
|   - 3 KB soft cap + _truncated/_total_available hints (CONTEXT D-05)
|   - Schema: { sku, window_days:90, competitors:[{competitor_id, competitor_name, data_points:[]}], _truncated, _total_available }
|   - Unknown SKU returns empty/zero payload, never throws
*/

function invokeReadCompetitorPricesTool(string $sku): string
{
    // Prism v0.100.1 Tool::handle(...$args) takes variadic positional args
    return app(ReadCompetitorPricesTool::class)->asPrismTool()->handle($sku);
}

it('returns the documented schema for a known SKU', function () {
    $competitor = Competitor::factory()->create(['name' => 'AV Distributor Ltd']);
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => 'TEST-SCHEMA',
        'price_pennies_ex_vat' => 154500,
        'recorded_at' => now()->subDays(5),
    ]);

    $payload = json_decode(invokeReadCompetitorPricesTool('TEST-SCHEMA'), true);

    expect($payload)->toHaveKeys(['sku', 'window_days', 'competitors', '_truncated', '_total_available']);
    expect($payload['sku'])->toBe('TEST-SCHEMA');
    expect($payload['window_days'])->toBe(90);
    expect($payload['_truncated'])->toBeFalse();
    expect($payload['_total_available'])->toBe(1);
    expect($payload['competitors'])->toHaveCount(1);
    expect($payload['competitors'][0]['competitor_name'])->toBe('AV Distributor Ltd');
    expect($payload['competitors'][0]['data_points'])->toHaveCount(1);
    expect($payload['competitors'][0]['data_points'][0]['price_pennies_ex_vat'])->toBe(154500);
});

it('caps response at 3 KB and emits _truncated + _total_available hints', function () {
    $sku = 'TEST-CAP';
    // Seed 60 rows across 5 competitors so per-competitor data_points dominate the payload
    $competitors = Competitor::factory()->count(5)->create();
    foreach (range(1, 60) as $i) {
        CompetitorPrice::factory()->create([
            'competitor_id' => $competitors->random()->id,
            'sku' => $sku,
            'price_pennies_ex_vat' => 100000 + $i,
            'recorded_at' => now()->subDays(($i % 89) + 1),
        ]);
    }

    $json = invokeReadCompetitorPricesTool($sku);
    $payload = json_decode($json, true);

    expect(strlen($json))->toBeLessThanOrEqual(3072);
    expect($payload['_truncated'])->toBeTrue();
    expect($payload['_total_available'])->toBe(60);
});

it('enforces 90d window — excludes rows older than 90 days', function () {
    $competitor = Competitor::factory()->create();
    $sku = 'TEST-WINDOW';
    // 1 row 100d old (excluded), 1 row 30d old (included)
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $sku,
        'recorded_at' => now()->subDays(100),
    ]);
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $sku,
        'recorded_at' => now()->subDays(30),
    ]);

    $payload = json_decode(invokeReadCompetitorPricesTool($sku), true);

    expect($payload['_total_available'])->toBe(1);
    expect($payload['competitors'][0]['data_points'])->toHaveCount(1);
});

it('returns empty payload for unknown SKU — never throws', function () {
    $payload = json_decode(invokeReadCompetitorPricesTool('NEVER-SEEN-SKU'), true);

    expect($payload['sku'])->toBe('NEVER-SEEN-SKU');
    expect($payload['window_days'])->toBe(90);
    expect($payload['_total_available'])->toBe(0);
    expect($payload['competitors'])->toBe([]);
});
