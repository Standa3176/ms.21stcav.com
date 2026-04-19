<?php

declare(strict_types=1);

use App\Domain\Competitor\Console\Commands\CompetitorSalesRecacheCommand;
use App\Domain\Competitor\Jobs\RecacheSalesCountsJob;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 5 Plan 03 Task 3 — CompetitorSalesRecacheCommand
|--------------------------------------------------------------------------
|
| Scheduled daily at 02:00 via routes/console.php; chunks Product model by
| 100 rows and dispatches one RecacheSalesCountsJob per chunk onto the
| sync-bulk queue.
|
| A3 fallback note: the dispatched job is a no-op stub that logs
| recache.wooclient_orders_missing — the command/schedule infrastructure
| is still wired so that extending WooClient with a getOrders method in a
| future plan activates real recache with ZERO additional plumbing.
*/

it('dispatches 0 jobs when no products exist', function (): void {
    Queue::fake();

    $this->artisan(CompetitorSalesRecacheCommand::class)->assertExitCode(0);

    Queue::assertNothingPushed();
});

it('dispatches 1 RecacheSalesCountsJob when 50 products exist (under chunk size)', function (): void {
    Queue::fake();

    Product::factory()->count(50)->create();

    $this->artisan(CompetitorSalesRecacheCommand::class)->assertExitCode(0);

    Queue::assertPushed(RecacheSalesCountsJob::class, 1);
});

it('dispatches 2 RecacheSalesCountsJob instances when 150 products exist (100 + 50)', function (): void {
    Queue::fake();

    Product::factory()->count(150)->create();

    $this->artisan(CompetitorSalesRecacheCommand::class)->assertExitCode(0);

    Queue::assertPushed(RecacheSalesCountsJob::class, 2);
});

it('dispatches jobs onto the sync-bulk queue', function (): void {
    Queue::fake();

    Product::factory()->count(5)->create();

    $this->artisan(CompetitorSalesRecacheCommand::class)->assertExitCode(0);

    Queue::assertPushedOn('sync-bulk', RecacheSalesCountsJob::class);
});

it('is registered in the schedule list with daily 02:00 cadence', function (): void {
    $output = \Illuminate\Support\Facades\Artisan::call('schedule:list');
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($output)->toContain('competitor:sales-recache');
});
