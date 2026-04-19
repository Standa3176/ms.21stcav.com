<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\RecomputePriceJob;
use App\Domain\Pricing\Services\PriceRecomputer;
use App\Domain\Pricing\Services\RecomputeOutcome;
use App\Domain\Pricing\Services\RecomputeOutcomeKind;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 04 Task 2 — RecomputePriceJob contract tests.
|--------------------------------------------------------------------------
|
| The job is a thin wrapper around PriceRecomputer::recompute() that:
|   - runs on the `sync-bulk` queue (Phase 1 D-09 + Pitfall 8 — isolated
|     from webhook handlers and the Woo PUT path)
|   - implements ShouldBeUnique so two concurrent batches cannot race the
|     same SKU (uniqueFor = 300s covers a typical 5-min retry window)
|   - carries the five constructor args into the recompute() call
*/

// ══════════════════════════════════════════════════════════════════════════════
// Test J1 — ShouldQueue + ShouldBeUnique
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceJob implements ShouldQueue and ShouldBeUnique', function () {
    $ref = new ReflectionClass(RecomputePriceJob::class);
    expect($ref->implementsInterface(ShouldQueue::class))->toBeTrue();
    expect($ref->implementsInterface(ShouldBeUnique::class))->toBeTrue();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test J2 — sync-bulk queue assignment
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceJob dispatches onto the sync-bulk queue', function () {
    $job = new RecomputePriceJob(
        wooProductId: 1,
        wooVariationId: null,
        sku: 'TEST-SKU',
        correlationId: '11111111-2222-4333-8444-555555555555',
        persist: true,
    );

    expect($job->queue)->toBe('sync-bulk');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test J3 — uniqueFor bounded
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceJob uniqueFor property is 300 seconds', function () {
    $job = new RecomputePriceJob(
        wooProductId: 1,
        wooVariationId: null,
        sku: 'TEST-SKU',
        correlationId: '11111111-2222-4333-8444-555555555555',
        persist: true,
    );

    expect($job->uniqueFor)->toBe(300);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test J4 — uniqueId shape
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceJob uniqueId reflects productId + variant-or-parent', function () {
    $parent = new RecomputePriceJob(
        wooProductId: 42,
        wooVariationId: null,
        sku: 'SKU-PARENT',
        correlationId: '11111111-2222-4333-8444-555555555501',
        persist: true,
    );

    $variant = new RecomputePriceJob(
        wooProductId: 42,
        wooVariationId: 5,
        sku: 'SKU-VAR',
        correlationId: '11111111-2222-4333-8444-555555555502',
        persist: true,
    );

    expect($parent->uniqueId())->toBe('recompute-price:42:parent');
    expect($variant->uniqueId())->toBe('recompute-price:42:5');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test J5 — handle() delegates to PriceRecomputer with the 5 constructor args
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceJob::handle delegates to PriceRecomputer::recompute with all constructor args', function () {
    $mock = Mockery::mock(PriceRecomputer::class);
    $mock->shouldReceive('recompute')
        ->once()
        ->with(101, 202, 'SKU-J5', '11111111-2222-4333-8444-555555555505', false)
        ->andReturn(new RecomputeOutcome(
            kind: RecomputeOutcomeKind::Unchanged,
            productId: 999,
            variantId: null,
            oldPennies: 0,
            newPennies: 0,
            resolutionSource: 'default_tier',
            marginBasisPoints: 3500,
        ));

    $job = new RecomputePriceJob(
        wooProductId: 101,
        wooVariationId: 202,
        sku: 'SKU-J5',
        correlationId: '11111111-2222-4333-8444-555555555505',
        persist: false,
    );

    $job->handle($mock);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test J6 — tries = 3 (Horizon retry contract)
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceJob tries property is 3', function () {
    $job = new RecomputePriceJob(
        wooProductId: 1,
        wooVariationId: null,
        sku: 'TEST-SKU',
        correlationId: '11111111-2222-4333-8444-555555555555',
        persist: true,
    );

    expect($job->tries)->toBe(3);
});
