<?php

declare(strict_types=1);

use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Jobs\CompetitorCsvChunkJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Services\CompetitorCsvRowWriter;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Quick task 260809-jie — Guard 2a: feed-jump quarantine flag on ingest.
|--------------------------------------------------------------------------
|
| Born from the 2026-08-09 production incident: competitor_id=3's price for
| a SKU jumped £1067.69 -> £3876.69 ex-VAT overnight (a 263% move, almost
| certainly a bad feed row). CompetitorCsvRowWriter now flags a row
| is_price_anomaly=true when it moves more than config('competitor.
| max_row_move_pct') vs. its own immediately-prior row for the same
| (competitor_id, sku) pair. The row still persists (audit trail intact) —
| only the pricer's "lowest current competitor" query excludes it (wired in
| a separate task).
|
| Driven through CompetitorCsvChunkJob::handle() with the real
| CompetitorCsvRowWriter, matching the existing CompetitorCsvChunkJobTest.php
| style (Event::fake + factory setup).
*/

function ingestOneRow(CompetitorIngestRun $run, string $sku, string $priceRaw): void
{
    $mapping = [
        'sku_column_index' => 0,
        'price_column_index' => 1,
        'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
    ];

    (new CompetitorCsvChunkJob($run->id, $mapping, [[$sku, $priceRaw]]))
        ->handle(app(CompetitorCsvRowWriter::class));
}

it('never flags the first-ever row for a (competitor, sku) pair — nothing to compare against', function (): void {
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    ingestOneRow($run, 'ANOM-1', '1067.69');

    expect(CompetitorPrice::count())->toBe(1);
    $row = CompetitorPrice::first();
    expect($row->is_price_anomaly)->toBeFalse();
    expect($row->price_anomaly_reason)->toBeNull();
});

it('does not flag a second row moving within the threshold', function (): void {
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    ingestOneRow($run, 'ANOM-2', '1000.00');
    $this->travel(1)->day();
    ingestOneRow($run, 'ANOM-2', '1200.00'); // +20% move, threshold is 50%

    expect(CompetitorPrice::count())->toBe(2);
    $latest = CompetitorPrice::where('sku', 'ANOM-2')->orderByDesc('recorded_at')->orderByDesc('id')->first();
    expect($latest->is_price_anomaly)->toBeFalse();
    expect($latest->price_anomaly_reason)->toBeNull();
});

it('flags the second row of the reproduced incident jump — row still persists, rows_written still increments', function (): void {
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    ingestOneRow($run, 'ANOM-3', '1067.69');
    $this->travel(1)->day();
    ingestOneRow($run, 'ANOM-3', '3876.69'); // 263% move, threshold is 50%

    expect(CompetitorPrice::count())->toBe(2);
    $latest = CompetitorPrice::where('sku', 'ANOM-3')->orderByDesc('recorded_at')->orderByDesc('id')->first();
    expect($latest->is_price_anomaly)->toBeTrue();
    expect($latest->price_anomaly_reason)->not->toBeNull();
    expect($run->fresh()->rows_written)->toBe(2);
});

it('never flags from a zero-pennies prior row — defensive divide-by-zero guard', function (): void {
    Event::fake([CompetitorPriceRecorded::class]);

    $competitor = Competitor::factory()->create();
    $run = CompetitorIngestRun::factory()->create(['competitor_id' => $competitor->id]);

    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => 'ANOM-4',
        'price_pennies_ex_vat' => 0,
        'price_pennies_gross' => 0,
        'recorded_at' => now()->subDay(),
    ]);

    ingestOneRow($run, 'ANOM-4', '500.00');

    $latest = CompetitorPrice::where('sku', 'ANOM-4')->orderByDesc('recorded_at')->orderByDesc('id')->first();
    expect($latest->is_price_anomaly)->toBeFalse();
    expect($latest->price_anomaly_reason)->toBeNull();
});
