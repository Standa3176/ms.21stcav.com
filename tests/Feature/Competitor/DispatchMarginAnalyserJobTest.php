<?php

declare(strict_types=1);

use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Listeners\DispatchMarginAnalyserJob;
use App\Providers\EventServiceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 2 — DispatchMarginAnalyserJob listener meta
|--------------------------------------------------------------------------
|
| Subscribes to CompetitorPriceRecorded (Plan 05-02 event). Must run on the
| default queue (analyser work is not rate-limited like Woo REST).
*/

it('implements ShouldQueue and runs on default queue', function (): void {
    $listener = new DispatchMarginAnalyserJob();

    expect($listener)->toBeInstanceOf(ShouldQueue::class);
    expect($listener->queue)->toBe('default');
});

it('is registered in EventServiceProvider::$listen for CompetitorPriceRecorded', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $listenProperty = $reflection->getProperty('listen');
    $listenProperty->setAccessible(true);
    $listen = $listenProperty->getValue($provider);

    expect($listen)->toHaveKey(CompetitorPriceRecorded::class);
    expect($listen[CompetitorPriceRecorded::class])->toContain(DispatchMarginAnalyserJob::class);
});
