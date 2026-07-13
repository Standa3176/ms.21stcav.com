<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-01 Task 7 — agents:run-ad-optimisation command
|--------------------------------------------------------------------------
|
|   - registers the signature
|   - SAFE NO-OP: zero recent GA4 rows → exit 0, nothing dispatched, no spend
|   - with recent GA4 rows → dispatches one RunAdOptimisationJob
|   - --dry-run dispatches nothing
*/

use App\Domain\Agents\Jobs\RunAdOptimisationJob;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

function seedRecentGaRow(?string $date = null): GaChannelMetric
{
    return GaChannelMetric::create([
        'date' => $date ?? now()->subDays(2)->toDateString(),
        'channel_group' => 'Paid Search',
        'source_medium' => 'google / cpc',
        'campaign' => 'Brand UK',
        'sessions' => 100,
        'key_events' => 10,
        'transactions' => 3,
        'purchase_revenue_pennies' => 120000,
        'pulled_at' => now(),
    ]);
}

function seedAdOptSuggestion(string $status = Suggestion::STATUS_PENDING): Suggestion
{
    return Suggestion::create([
        'kind' => 'ad_optimisation',
        'status' => $status,
        'correlation_id' => 'test-ad-opt-'.uniqid(),
        'payload' => ['proposals' => [], 'agent_run_id' => 'run-'.uniqid()],
        'evidence' => ['agent_kind' => 'ad_optimisation'],
        'proposed_at' => now(),
    ]);
}

it('registers the agents:run-ad-optimisation signature', function () {
    Artisan::call('list');
    expect(Artisan::output())->toContain('agents:run-ad-optimisation');
});

it('SAFE NO-OP — exits 0 and dispatches nothing when there is no recent GA4 data', function () {
    $exitCode = Artisan::call('agents:run-ad-optimisation');

    expect($exitCode)->toBe(0);
    Queue::assertNothingPushed();
});

it('SAFE NO-OP — GA4 rows older than the lookback window do not trigger a dispatch', function () {
    seedRecentGaRow(now()->subDays(60)->toDateString());  // outside default 14d lookback

    $exitCode = Artisan::call('agents:run-ad-optimisation');

    expect($exitCode)->toBe(0);
    Queue::assertNothingPushed();
});

it('dispatches ONE RunAdOptimisationJob when recent GA4 data exists', function () {
    seedRecentGaRow();

    $exitCode = Artisan::call('agents:run-ad-optimisation');

    expect($exitCode)->toBe(0);
    Queue::assertPushed(RunAdOptimisationJob::class, 1);
    Queue::assertPushed(RunAdOptimisationJob::class, fn (RunAdOptimisationJob $job) => $job->batchCorrelationId !== null);
});

it('--dry-run dispatches nothing even when recent GA4 data exists', function () {
    seedRecentGaRow();

    $exitCode = Artisan::call('agents:run-ad-optimisation', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
    Queue::assertNothingPushed();
});

it('SKIP-IF-PENDING — exits 0 and dispatches nothing when a pending ad_optimisation Suggestion already exists', function () {
    seedRecentGaRow();               // GA4 data present — would normally dispatch
    seedAdOptSuggestion();           // but a pending ad_optimisation advice is unactioned

    $exitCode = Artisan::call('agents:run-ad-optimisation');

    expect($exitCode)->toBe(0);
    Queue::assertNothingPushed();    // no Claude spend, no new row
});

it('an APPROVED ad_optimisation Suggestion does NOT block a fresh dispatch', function () {
    seedRecentGaRow();
    seedAdOptSuggestion(Suggestion::STATUS_APPROVED);

    $exitCode = Artisan::call('agents:run-ad-optimisation');

    expect($exitCode)->toBe(0);
    Queue::assertPushed(RunAdOptimisationJob::class, 1);
});

it('a REJECTED ad_optimisation Suggestion does NOT block a fresh dispatch', function () {
    seedRecentGaRow();
    seedAdOptSuggestion(Suggestion::STATUS_REJECTED);

    $exitCode = Artisan::call('agents:run-ad-optimisation');

    expect($exitCode)->toBe(0);
    Queue::assertPushed(RunAdOptimisationJob::class, 1);
});

it('a pending suggestion of a DIFFERENT kind does NOT block ad_optimisation dispatch', function () {
    seedRecentGaRow();
    Suggestion::create([
        'kind' => 'margin_change',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => 'test-margin-'.uniqid(),
        'payload' => [],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    $exitCode = Artisan::call('agents:run-ad-optimisation');

    expect($exitCode)->toBe(0);
    Queue::assertPushed(RunAdOptimisationJob::class, 1);
});
