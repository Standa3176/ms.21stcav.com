<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CsvParseError;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 01 Task 2 — CompetitorIngestRun + CsvParseError + CsvMapping
|--------------------------------------------------------------------------
|
| Correlation_id shape (36 chars — Phase 2 P02 lesson), unique constraint
| on CompetitorCsvMapping.competitor_id (D-03), and enum rejection on
| CsvParseError.issue_type.
*/

it('factory persists a CompetitorIngestRun with a 36-char UUID correlation_id', function (): void {
    $run = CompetitorIngestRun::factory()->create();

    expect($run->fresh())->not->toBeNull();
    expect(mb_strlen($run->correlation_id))->toBe(36);
    expect($run->status)->toBe(CompetitorIngestRun::STATUS_STARTED);
});

it('exposes STATUS_* constants mirroring Phase 2 SyncRun', function (): void {
    expect(CompetitorIngestRun::STATUS_STARTED)->toBe('started');
    expect(CompetitorIngestRun::STATUS_COMPLETED)->toBe('completed');
    expect(CompetitorIngestRun::STATUS_FAILED)->toBe('failed');
});

it('exposes hasMany prices + parseErrors + belongsTo competitor', function (): void {
    $c = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $c->id]);

    CsvParseError::factory()->create(['ingest_run_id' => $run->id, 'competitor_id' => $c->id]);
    CsvParseError::factory()->orphanSku()->create(['ingest_run_id' => $run->id, 'competitor_id' => $c->id]);

    expect($run->competitor)->toBeInstanceOf(Competitor::class);
    expect($run->parseErrors)->toHaveCount(2);
    expect($run->parseErrors->first())->toBeInstanceOf(CsvParseError::class);
});

it('CompetitorCsvMapping enforces one mapping per competitor (D-03)', function (): void {
    $c = Competitor::factory()->create();
    CompetitorCsvMapping::factory()->create(['competitor_id' => $c->id]);

    $this->expectException(QueryException::class);

    CompetitorCsvMapping::factory()->create(['competitor_id' => $c->id]);
});

it('CsvParseError rejects out-of-enum issue_type values', function (): void {
    $this->expectException(QueryException::class);

    CsvParseError::factory()->create([
        'issue_type' => 'not_a_valid_enum',
    ]);
});

it('CsvParseError exposes TYPE_* constants + unresolved/ofType scopes + isResolved helper', function (): void {
    expect(CsvParseError::TYPE_AMBIGUOUS_MAPPING)->toBe('ambiguous_mapping');
    expect(CsvParseError::TYPE_ORPHAN_SKU)->toBe('orphan_sku');

    $open = CsvParseError::factory()->ambiguousMapping()->create();
    $closed = CsvParseError::factory()->resolved()->create();

    expect($open->isResolved())->toBeFalse();
    expect($closed->isResolved())->toBeTrue();

    expect(CsvParseError::unresolved()->count())->toBe(1);
    expect(CsvParseError::ofType(CsvParseError::TYPE_AMBIGUOUS_MAPPING)->count())->toBe(1);
});
