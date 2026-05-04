<?php

declare(strict_types=1);

use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Jobs\CompetitorCsvChunkJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Models\CsvParseError;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 02 Task 2 — CompetitorCsvChunkJob
|--------------------------------------------------------------------------
|
| Processes a 100-row chunk: each row → CompetitorCsvRowWriter → either
| competitor_prices row + event, or csv_parse_errors row, or orphan
| suggestion.
*/

it('writes 2 competitor_prices rows and fires 2 CompetitorPriceRecorded events for a happy-path 2-row batch', function (): void {
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);
    Product::factory()->create(['sku' => 'ABC-1', 'buy_price' => 50.0]);
    Product::factory()->create(['sku' => 'ABC-2', 'buy_price' => 60.0]);

    $mapping = [
        'sku_column_index' => 0,
        'price_column_index' => 1,
        'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
    ];

    $rows = [
        ['ABC-1', '89.99'],
        ['ABC-2', '149.95'],
    ];

    (new CompetitorCsvChunkJob($run->id, $mapping, $rows))->handle(
        app(\App\Domain\Competitor\Services\CompetitorCsvRowWriter::class)
    );

    expect(CompetitorPrice::count())->toBe(2);
    Event::assertDispatchedTimes(CompetitorPriceRecorded::class, 2);
    expect($run->fresh()->rows_written)->toBe(2);
});

it('creates a csv_parse_errors row for an unparseable price and increments rows_errored', function (): void {
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);
    Product::factory()->create(['sku' => 'ABC-1']);

    $mapping = [
        'sku_column_index' => 0,
        'price_column_index' => 1,
        'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
    ];

    (new CompetitorCsvChunkJob($run->id, $mapping, [['ABC-1', 'garbage']]))->handle(
        app(\App\Domain\Competitor\Services\CompetitorCsvRowWriter::class)
    );

    expect(CompetitorPrice::count())->toBe(0);
    expect(CsvParseError::where('issue_type', CsvParseError::TYPE_UNPARSEABLE_PRICE)->count())->toBe(1);
    Event::assertNotDispatched(CompetitorPriceRecorded::class);
    expect($run->fresh()->rows_errored)->toBe(1);
});

it('persists an orphan SKU as a competitor_prices row + increments rows_orphaned + suppresses CompetitorPriceRecorded event', function (): void {
    // Quick task 260504-01s — orphans now persist as queryable rows so ops can
    // see ALL competitor pricing data (not just rows that match a Product).
    // Suggestion + rows_orphaned audit metric still fire; matched-only event stays suppressed.
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    $mapping = [
        'sku_column_index' => 0,
        'price_column_index' => 1,
        'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
    ];

    (new CompetitorCsvChunkJob($run->id, $mapping, [['ORPHAN-SKU', '49.99']]))->handle(
        app(\App\Domain\Competitor\Services\CompetitorCsvRowWriter::class)
    );

    expect(CompetitorPrice::count())->toBe(1);
    expect(CompetitorPrice::first()->sku)->toBe('ORPHAN-SKU');
    expect($run->fresh()->rows_orphaned)->toBe(1);
    expect($run->fresh()->rows_written)->toBe(1);
    expect(\App\Domain\Suggestions\Models\Suggestion::where('kind', 'new_product_opportunity')->count())->toBe(1);
    Event::assertNotDispatched(CompetitorPriceRecorded::class);
});

it('is enqueued on the competitor-csv queue', function (): void {
    $job = new CompetitorCsvChunkJob(1, [], []);
    expect($job->queue)->toBe('competitor-csv');
});

// Quick task 260504-edk — onedirect pattern: empty primary SKU column but a
// real internal id elsewhere in the row.
it('falls back to a non-empty alphanumeric column when primary SKU is empty', function (): void {
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    $mapping = [
        'sku_column_index' => 0,
        'price_column_index' => 1,
        'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
    ];

    // col 0 = empty (configured SKU column),
    // col 1 = price,
    // col 2 = product name (has spaces — fails the alphanumeric regex),
    // col 3 = internal id (matches → wins as fallback).
    (new CompetitorCsvChunkJob($run->id, $mapping, [['', '£99.99', 'Product Name with spaces', 'INTID-52501']]))->handle(
        app(\App\Domain\Competitor\Services\CompetitorCsvRowWriter::class)
    );

    expect(CompetitorPrice::count())->toBe(1);
    expect(CompetitorPrice::first()->sku)->toBe('INTID-52501');
    expect($run->fresh()->rows_errored)->toBe(0); // not counted as invalid_sku_format
});
