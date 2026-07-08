<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260708-jou — RunCatalogueGapFixJob Pest test
|--------------------------------------------------------------------------
|
| RunCatalogueGapFixJob runs ONE chunk of a Catalogue Gaps bulk fix in the
| background on the sync-bulk queue. This proves the safety guardrails:
|
|   1. An ALLOWED command with SKUs → Artisan::call invoked once with the
|      joined --skus CSV (the REAL command never runs — Artisan::shouldReceive
|      spy, matching the existing CatalogueGapsPageTest convention; this
|      Laravel build has no Artisan::fake()).
|   2. A DISALLOWED command → Artisan::call NOT invoked (defence-in-depth
|      allow-list; the job silently refuses + logs).
|   3. Empty / blank SKUs → Artisan::call NOT invoked (no-op).
|   4. The job dispatches onto the 'sync-bulk' queue (Horizon single worker
|      = the throttle).
*/

use App\Domain\Products\Jobs\RunCatalogueGapFixJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

it('runs an allowed command once with the joined --skus CSV', function (): void {
    Artisan::shouldReceive('call')
        ->once()
        ->with('products:resync-to-woo', ['--skus' => 'SKU-1,SKU-2,SKU-3'])
        ->andReturn(0);

    (new RunCatalogueGapFixJob('products:resync-to-woo', ['SKU-1', 'SKU-2', 'SKU-3']))->handle();
});

it('accepts every allow-listed command', function (string $command): void {
    Artisan::shouldReceive('call')
        ->once()
        ->with($command, ['--skus' => 'SKU-A'])
        ->andReturn(0);

    (new RunCatalogueGapFixJob($command, ['SKU-A']))->handle();
})->with(RunCatalogueGapFixJob::ALLOWED_COMMANDS);

it('refuses a disallowed command and never calls Artisan', function (): void {
    Artisan::shouldReceive('call')->never();

    (new RunCatalogueGapFixJob('migrate:fresh', ['SKU-1']))->handle();
});

it('is a no-op when the SKUs are empty or blank', function (): void {
    Artisan::shouldReceive('call')->never();

    (new RunCatalogueGapFixJob('products:resync-to-woo', []))->handle();
    (new RunCatalogueGapFixJob('products:resync-to-woo', ['', '']))->handle();
});

it('drops blank SKUs from the CSV but still runs when at least one is valid', function (): void {
    Artisan::shouldReceive('call')
        ->once()
        ->with('products:source-images', ['--skus' => 'SKU-KEEP'])
        ->andReturn(0);

    (new RunCatalogueGapFixJob('products:source-images', ['', 'SKU-KEEP', '']))->handle();
});

it('dispatches onto the sync-bulk queue', function (): void {
    Queue::fake();

    RunCatalogueGapFixJob::dispatch('products:resync-to-woo', ['SKU-1']);

    Queue::assertPushedOn('sync-bulk', RunCatalogueGapFixJob::class);
});

it('never auto-retries a money-costing batch (tries=1)', function (): void {
    $job = new RunCatalogueGapFixJob('products:resync-to-woo', ['SKU-1']);

    expect($job->tries)->toBe(1);
});
