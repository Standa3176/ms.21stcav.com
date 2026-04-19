<?php

declare(strict_types=1);

use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Jobs\ComputeMarginSuggestionJob;
use App\Domain\Competitor\Listeners\DispatchMarginAnalyserJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 2 — Cache::add atomic debounce (D-06)
|--------------------------------------------------------------------------
|
| Debounce key format: competitor.analyser.debounce.{competitor_id}.{sku}.{YYYY-MM-DD}
|
| Cache::add(key, true, 24h) — if key exists returns false → listener exits
| silently. Prevents N-per-CSV margin analysis on repeated scrapes of the
| same SKU on the same day (e.g. CSV dropped twice by n8n).
*/

it('uses the exact cache key format competitor.analyser.debounce.{competitorId}.{sku}.{YYYY-MM-DD}', function (): void {
    Cache::shouldReceive('add')
        ->once()
        ->withArgs(function (string $key, bool $value, $ttl) {
            $today = now()->format('Y-m-d');
            expect($key)->toBe("competitor.analyser.debounce.1.SKU-1.{$today}");
            expect($value)->toBeTrue();

            return true;
        })
        ->andReturn(true);

    Queue::fake();

    $event = new CompetitorPriceRecorded(
        competitorId: 1,
        sku: 'SKU-1',
        priceGrossPennies: 8999,
        priceExVatPennies: 7499,
        ingestRunId: 1,
    );

    (new DispatchMarginAnalyserJob())->handle($event);
});

it('dispatches ComputeMarginSuggestionJob on first invocation (Cache::add returns true)', function (): void {
    Cache::flush();
    Queue::fake();

    $event = new CompetitorPriceRecorded(
        competitorId: 2,
        sku: 'SKU-2',
        priceGrossPennies: 8999,
        priceExVatPennies: 7499,
        ingestRunId: 1,
    );

    (new DispatchMarginAnalyserJob())->handle($event);

    Queue::assertPushed(ComputeMarginSuggestionJob::class, function (ComputeMarginSuggestionJob $job) {
        return $job->competitorId === 2 && $job->sku === 'SKU-2';
    });
});

it('does NOT dispatch a second job when the debounce key already exists (same day)', function (): void {
    Cache::flush();
    Queue::fake();

    $event = new CompetitorPriceRecorded(
        competitorId: 3,
        sku: 'SKU-3',
        priceGrossPennies: 8999,
        priceExVatPennies: 7499,
        ingestRunId: 1,
    );

    // First invocation
    (new DispatchMarginAnalyserJob())->handle($event);
    Queue::assertPushed(ComputeMarginSuggestionJob::class, 1);

    // Second invocation same day — debounced
    (new DispatchMarginAnalyserJob())->handle($event);
    Queue::assertPushed(ComputeMarginSuggestionJob::class, 1); // still 1, not 2
});

it('routes the dispatched job onto the default queue', function (): void {
    Cache::flush();
    Queue::fake();

    $event = new CompetitorPriceRecorded(
        competitorId: 4,
        sku: 'SKU-4',
        priceGrossPennies: 8999,
        priceExVatPennies: 7499,
        ingestRunId: 1,
    );

    (new DispatchMarginAnalyserJob())->handle($event);

    Queue::assertPushedOn('default', ComputeMarginSuggestionJob::class);
});
