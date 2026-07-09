<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Jobs\RunAutoCreatePipelineJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/*
|--------------------------------------------------------------------------
| 260709-gov — RunAutoCreatePipelineJob queue/uniqueness hardening.
|--------------------------------------------------------------------------
|
| Mirrors tests/Feature/Pricing/RecomputePriceJobTest.php (J2/J3/J4/J6).
| This is a config/property contract test only — it never runs the pipeline
| nor asserts handle() behaviour. It locks in the reliability fix:
|   - the job runs on `sync-bulk` (1800s/512MB/1 worker), not `default`
|     (120s/256MB) which silently killed long auto-create batches
|   - ShouldBeUnique now carries a bounded uniqueFor=1800 so a crashed/OOM'd
|     worker's lock auto-expires instead of blocking re-dispatch forever
|   - timeout aligned to the sync-bulk worker timeout (1800), tries stays 1
|   - uniqueId() stays stable + SKU-keyed (the dedup key is unchanged)
|
| Constructor signature (verified against the class):
|   __construct(array $skus, bool $sourceImages, bool $autoPublish, int $triggeredByUserId)
*/

// ══════════════════════════════════════════════════════════════════════════════
// Test H1 — ShouldQueue + ShouldBeUnique (unchanged contract)
// ══════════════════════════════════════════════════════════════════════════════

it('RunAutoCreatePipelineJob implements ShouldQueue and ShouldBeUnique', function () {
    $ref = new ReflectionClass(RunAutoCreatePipelineJob::class);
    expect($ref->implementsInterface(ShouldQueue::class))->toBeTrue();
    expect($ref->implementsInterface(ShouldBeUnique::class))->toBeTrue();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test H2 — sync-bulk queue assignment
// ══════════════════════════════════════════════════════════════════════════════

it('RunAutoCreatePipelineJob dispatches onto the sync-bulk queue', function () {
    $job = new RunAutoCreatePipelineJob(
        ['SKU-1'],
        sourceImages: false,
        autoPublish: false,
        triggeredByUserId: 1,
    );

    expect($job->queue)->toBe('sync-bulk');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test H3 — uniqueFor bounded (the crash-lock fix)
// ══════════════════════════════════════════════════════════════════════════════

it('RunAutoCreatePipelineJob uniqueFor property is 1800 seconds', function () {
    $job = new RunAutoCreatePipelineJob(
        ['SKU-1'],
        sourceImages: false,
        autoPublish: false,
        triggeredByUserId: 1,
    );

    expect($job->uniqueFor)->toBe(1800);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test H4 — timeout aligned to the sync-bulk worker timeout; tries unchanged
// ══════════════════════════════════════════════════════════════════════════════

it('RunAutoCreatePipelineJob timeout is 1800 and tries stays 1', function () {
    $job = new RunAutoCreatePipelineJob(
        ['SKU-1'],
        sourceImages: false,
        autoPublish: false,
        triggeredByUserId: 1,
    );

    expect($job->timeout)->toBe(1800);
    expect($job->tries)->toBe(1);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test H5 — uniqueId() is stable + SKU-keyed (dedup key unchanged)
// ══════════════════════════════════════════════════════════════════════════════

it('RunAutoCreatePipelineJob uniqueId is stable for the same SKUs and differs for different SKUs', function () {
    $a1 = new RunAutoCreatePipelineJob(
        ['SKU-1', 'SKU-2'],
        sourceImages: false,
        autoPublish: false,
        triggeredByUserId: 1,
    );

    // Same SKU set, different flags/actor → same dedup identity.
    $a2 = new RunAutoCreatePipelineJob(
        ['SKU-1', 'SKU-2'],
        sourceImages: true,
        autoPublish: true,
        triggeredByUserId: 99,
    );

    $b = new RunAutoCreatePipelineJob(
        ['SKU-3'],
        sourceImages: false,
        autoPublish: false,
        triggeredByUserId: 1,
    );

    expect($a1->uniqueId())->toBe($a2->uniqueId());
    expect($a1->uniqueId())->not->toBe($b->uniqueId());
});
