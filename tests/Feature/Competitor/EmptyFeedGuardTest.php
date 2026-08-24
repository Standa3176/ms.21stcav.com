<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Events\CompetitorCsvIngested;
use App\Domain\Competitor\Listeners\FlagEmptyFeedRegression;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Notifications\EmptyFeedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| 260823-gua — an empty competitor feed must fail loudly
|--------------------------------------------------------------------------
|
| Prod 2026-08-23: screenmoove published a 23-byte CSV (BOM + header + one
| blank row) from 2026-07-22 onward. Ten consecutive pulls, all `success`; ten
| ingests, all `completed`, all writing zero rows. Nothing alerted — the FTP
| pull worked and competitor:check-stale watches ingest RECENCY, which was
| perfect. screenmoove held 66% of all competitor price data, so ~3,400
| published products silently priced against five-week-old data for a month.
|
| The guard turns that specific silence into a failed run plus an alert, while
| staying quiet for a genuinely new source whose first file happens to be empty.
*/

/**
 * Drive the listener with the event the (frozen) ingest job emits.
 *
 * The guard lives in a listener rather than IngestCompetitorCsvJob because
 * Phase 11.2 D-07 freezes that file — see Phase5IngestUntouchedTest.
 */
function runEmptyFeedGuard(CompetitorIngestRun $run): void
{
    (new FlagEmptyFeedRegression)->handle(new CompetitorCsvIngested(
        competitorId: (int) $run->competitor_id,
        ingestRunId: (int) $run->id,
        filename: (string) $run->filename,
        rowsTotal: (int) $run->rows_total,
        rowsWritten: (int) $run->rows_written,
        rowsErrored: (int) $run->rows_errored,
        rowsOrphaned: (int) $run->rows_orphaned,
    ));
}

function makeRun(Competitor $competitor, int $rowsTotal, int $rowsWritten): CompetitorIngestRun
{
    return CompetitorIngestRun::create([
        'competitor_id' => $competitor->id,
        'filename' => 'screenmoove_2026-01-01.csv',
        'rows_total' => $rowsTotal,
        'rows_written' => $rowsWritten,
        'rows_errored' => 0,
        'rows_orphaned' => 0,
        'status' => CompetitorIngestRun::STATUS_COMPLETED,
        'correlation_id' => (string) Str::uuid(),
        'started_at' => now(),
        'completed_at' => now(),
    ]);
}

beforeEach(function (): void {
    Notification::fake();
});

it('fails the run and alerts when a previously-populated feed arrives empty', function (): void {
    $competitor = Competitor::factory()->create(['name' => 'screenmoove']);
    CompetitorPrice::factory()->count(3)->create(['competitor_id' => $competitor->id]);

    $recipient = AlertRecipient::create([
        'email' => 'ops@meetingstore.co.uk',
        'name' => 'Ops',
        'is_active' => true,
        'receives_competitor_alerts' => true,
    ]);

    // The exact prod shape: one row read (the blank ",," line), nothing written.
    $run = makeRun($competitor, rowsTotal: 1, rowsWritten: 0);

    runEmptyFeedGuard($run);

    expect($run->fresh()->status)->toBe(CompetitorIngestRun::STATUS_FAILED)
        ->and($run->fresh()->error_message)->toContain('Empty feed');

    Notification::assertSentTo($recipient, EmptyFeedNotification::class);
});

it('stays quiet for a brand-new competitor whose first file is empty', function (): void {
    // No prior prices — an empty first file is not a regression, and alerting
    // here is what would make the guard too noisy to keep switched on.
    $competitor = Competitor::factory()->create(['name' => 'brand new source']);

    $run = makeRun($competitor, rowsTotal: 1, rowsWritten: 0);

    runEmptyFeedGuard($run);

    expect($run->fresh()->status)->toBe(CompetitorIngestRun::STATUS_COMPLETED);
    Notification::assertNothingSent();
});

it('leaves a healthy ingest completely alone', function (): void {
    $competitor = Competitor::factory()->create();
    CompetitorPrice::factory()->count(3)->create(['competitor_id' => $competitor->id]);

    $run = makeRun($competitor, rowsTotal: 5688, rowsWritten: 5688);

    runEmptyFeedGuard($run);

    expect($run->fresh()->status)->toBe(CompetitorIngestRun::STATUS_COMPLETED)
        ->and($run->fresh()->error_message)->toBeNull();
    Notification::assertNothingSent();
});

it('still fails the run when nobody is subscribed to competitor alerts', function (): void {
    // The run status is the durable signal; the email is a courtesy. An empty
    // recipient list must not swallow the failure.
    $competitor = Competitor::factory()->create();
    CompetitorPrice::factory()->count(2)->create(['competitor_id' => $competitor->id]);

    $run = makeRun($competitor, rowsTotal: 1, rowsWritten: 0);

    runEmptyFeedGuard($run);

    expect($run->fresh()->status)->toBe(CompetitorIngestRun::STATUS_FAILED);
    Notification::assertNothingSent();
});

it('records how many rows the competitor already held, as the evidence', function (): void {
    $competitor = Competitor::factory()->create();
    CompetitorPrice::factory()->count(7)->create(['competitor_id' => $competitor->id]);

    $run = makeRun($competitor, rowsTotal: 1, rowsWritten: 0);

    runEmptyFeedGuard($run);

    // "0 written but 7 already held" is what distinguishes a dead feed from a
    // quiet one, and it is what the operator needs to take to the supplier.
    expect($run->fresh()->error_message)->toContain('7 price rows');
});
