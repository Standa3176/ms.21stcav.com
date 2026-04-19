<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 01 Task 2 — CompetitorPrice schema + dedup guarantee (COMP-07)
|--------------------------------------------------------------------------
|
| UNIQUE(competitor_id, sku, recorded_at) is the idempotent re-ingest
| guarantee. A CSV accidentally re-processed lands the same rows — the
| unique index turns the second INSERT into a noop/error instead of
| doubling the history.
*/

it('creates the competitor_prices table with every expected column', function (): void {
    expect(Schema::hasTable('competitor_prices'))->toBeTrue();

    foreach ([
        'id', 'competitor_id', 'sku', 'mpn',
        'price_pennies_ex_vat', 'price_pennies_gross',
        'recorded_at', 'ingest_run_id',
        'created_at', 'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('competitor_prices', $col))->toBeTrue("competitor_prices missing: {$col}");
    }
});

it('enforces UNIQUE(competitor_id, sku, recorded_at) — COMP-07 dedup guarantee', function (): void {
    $c = Competitor::factory()->create();
    $when = now()->startOfDay();

    CompetitorPrice::factory()->create([
        'competitor_id' => $c->id,
        'sku' => 'DUPE-1',
        'recorded_at' => $when,
    ]);

    $this->expectException(QueryException::class);

    CompetitorPrice::factory()->create([
        'competitor_id' => $c->id,
        'sku' => 'DUPE-1',
        'recorded_at' => $when,
    ]);
});

it('allows the same SKU to be recorded multiple times per competitor across dates (trend history)', function (): void {
    $c = Competitor::factory()->create();

    CompetitorPrice::factory()->forSku('TREND-1')->recordedAt(now()->subDays(2))->create(['competitor_id' => $c->id]);
    CompetitorPrice::factory()->forSku('TREND-1')->recordedAt(now()->subDays(1))->create(['competitor_id' => $c->id]);
    CompetitorPrice::factory()->forSku('TREND-1')->recordedAt(now())->create(['competitor_id' => $c->id]);

    expect(CompetitorPrice::where('competitor_id', $c->id)->where('sku', 'TREND-1')->count())->toBe(3);
});

it('belongsTo competitor + ingestRun relationships resolve', function (): void {
    $c = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $c->id]);
    $price = CompetitorPrice::factory()->create([
        'competitor_id' => $c->id,
        'ingest_run_id' => $run->id,
    ]);

    expect($price->competitor)->toBeInstanceOf(Competitor::class);
    expect($price->competitor->id)->toBe($c->id);
    expect($price->ingestRun)->toBeInstanceOf(CompetitorIngestRun::class);
    expect($price->ingestRun->id)->toBe($run->id);
});

it('casts integer price columns + carbon recorded_at', function (): void {
    $price = CompetitorPrice::factory()->create([
        'price_pennies_ex_vat' => 12345,
        'price_pennies_gross' => 14814,
    ]);

    $fresh = $price->fresh();
    expect($fresh->price_pennies_ex_vat)->toBe(12345);
    expect($fresh->price_pennies_gross)->toBe(14814);
    expect($fresh->recorded_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});
