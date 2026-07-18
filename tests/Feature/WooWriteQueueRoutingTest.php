<?php

declare(strict_types=1);

use App\Domain\Pricing\Listeners\PushPriceChangeToWoo;
use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\ProductAutoCreate\Listeners\PushProductFieldsToWoo;
use App\Domain\Sync\Jobs\SyncChunkJob;

/**
 * 260719-wth — Task 3. The live-write jobs/listeners must dispatch onto the
 * dedicated, single-worker 'woo-writes' queue so writes (a) serialise at the
 * queue level and (b) stay OFF the shared worker pool (a write backlog can't
 * starve sync/crm/agents). Belt-and-braces with the Task-2 app-level lock.
 */
it('routes PushPriceChangeToWoo onto the woo-writes queue', function () {
    $listener = app(PushPriceChangeToWoo::class);

    expect($listener->viaQueue())->toBe('woo-writes');
});

it('routes PushProductFieldsToWoo onto the woo-writes queue', function () {
    $listener = app(PushProductFieldsToWoo::class);

    expect($listener->queue)->toBe('woo-writes');
});

it('routes PublishProductJob onto the woo-writes queue', function () {
    $job = new PublishProductJob(productId: 1, publishedByUserId: 1);

    expect($job->queue)->toBe('woo-writes');
});

it('routes CreateWooProductJob onto the woo-writes queue', function () {
    $job = new CreateWooProductJob(sku: 'TST-1');

    expect($job->queue)->toBe('woo-writes');
});

it('routes SyncChunkJob onto the woo-writes queue', function () {
    $job = new SyncChunkJob(runId: 1, page: 1, skus: [], supplierFeed: []);

    expect($job->queue)->toBe('woo-writes');
});

// ─── Horizon supervisor config sanity ───────────────────────────────────────

it('defines a production woo-writes supervisor with maxProcesses=1', function () {
    $supervisor = config('horizon.environments.production.woo-writes-supervisor');

    expect($supervisor)->toBeArray()
        ->and($supervisor['queue'])->toContain('woo-writes')
        ->and($supervisor['maxProcesses'])->toBe(1);
});

it('includes the woo-writes queue in the local all-in-one supervisor', function () {
    $queues = config('horizon.environments.local.all-in-one.queue');

    expect($queues)->toContain('woo-writes');
});
