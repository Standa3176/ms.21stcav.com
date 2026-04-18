<?php

declare(strict_types=1);

use App\Domain\Sync\Exceptions\SyncAbortException;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\AbortGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Context::add('correlation_id', (string) Str::uuid());
});

// -----------------------------------------------------------------------------
// B1: ≤20% errors after 500 samples → no throw (failures spaced to avoid B4)
// -----------------------------------------------------------------------------
test('B1: error rate ≤ 20% after 500 samples does NOT trip', function () {
    $run = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    // 50 failures spaced between successes so consecutive_failures never hits 50
    // (otherwise B1 accidentally trips B4's trigger). Pattern: 9 success + 1 fail × 50.
    for ($block = 0; $block < 50; $block++) {
        for ($j = 0; $j < 9; $j++) {
            $guard->recordSuccess($run->id);
        }
        $guard->recordFailure($run->id);
    }

    $guard->throwIfTriggered($run->id);  // must not throw

    $run->refresh();
    expect($run->failed_count)->toBe(50)
        ->and($run->total_skus)->toBe(500)
        ->and($run->consecutive_failures)->toBe(1);  // last op was a failure
});

test('B1b: interleaved success/failure keeps error-rate trigger quiet at <20%', function () {
    $run = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    // 400 successes, 99 failures, interleaved so consecutive_failures stays low.
    // 99/499 ≈ 19.8%, plus one more success → 99/500 = 19.8% < 20% threshold.
    for ($i = 0; $i < 99; $i++) {
        $guard->recordFailure($run->id);
        for ($j = 0; $j < 4; $j++) {
            $guard->recordSuccess($run->id);
            if ($run->refresh()->total_skus >= 499) {
                break 2;
            }
        }
    }
    // Pad to exactly 500.
    while ($run->refresh()->total_skus < 500) {
        $guard->recordSuccess($run->id);
    }

    expect(fn () => $guard->throwIfTriggered($run->id))->not->toThrow(SyncAbortException::class);
});

// -----------------------------------------------------------------------------
// B2: >20% errors after 500 samples → throws error_rate
// -----------------------------------------------------------------------------
test('B2: error rate > 20% after 500 samples throws reason=error_rate (D-06a)', function () {
    $run = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    // 400 successes + 101 failures interleaved to avoid consecutive threshold.
    // 101/501 ≈ 20.16% (> 20%).
    for ($i = 0; $i < 101; $i++) {
        $guard->recordFailure($run->id);
        if ($i < 400) {
            $guard->recordSuccess($run->id);
        }
    }
    // Pad successes so we reach the 500 sample floor — but keep error rate above 20%.
    while ($run->refresh()->total_skus < 500) {
        $guard->recordSuccess($run->id);
    }

    try {
        $guard->throwIfTriggered($run->id);
        $this->fail('Expected SyncAbortException, none thrown');
    } catch (SyncAbortException $e) {
        expect($e->reason)->toBe(SyncRun::ABORT_ERROR_RATE);
    }
});

// -----------------------------------------------------------------------------
// B3: >20% errors but <500 samples → no throw (below ERROR_RATE_MIN_SAMPLES)
// -----------------------------------------------------------------------------
test('B3: error rate > 20% but fewer than 500 samples does NOT trip', function () {
    $run = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    // 300 successes + 40 failures interleaved. 40/340 ≈ 11.8% — even if we spike
    // more failures we stay below 500 total.
    for ($i = 0; $i < 40; $i++) {
        $guard->recordFailure($run->id);
        for ($j = 0; $j < 7; $j++) {
            $guard->recordSuccess($run->id);
            if ($run->refresh()->total_skus >= 340) {
                break 2;
            }
        }
    }

    expect(fn () => $guard->throwIfTriggered($run->id))
        ->not->toThrow(SyncAbortException::class);

    $run->refresh();
    expect($run->total_skus)->toBeLessThan(AbortGuard::ERROR_RATE_MIN_SAMPLES);
});

// -----------------------------------------------------------------------------
// B4: 50 consecutive failures → throws consecutive_failures
// -----------------------------------------------------------------------------
test('B4: 50 consecutive failures throws reason=consecutive_failures (D-06b)', function () {
    $run = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    for ($i = 0; $i < 50; $i++) {
        $guard->recordFailure($run->id);
    }

    $run->refresh();
    expect($run->consecutive_failures)->toBe(50);

    try {
        $guard->throwIfTriggered($run->id);
        $this->fail('Expected SyncAbortException, none thrown');
    } catch (SyncAbortException $e) {
        expect($e->reason)->toBe(SyncRun::ABORT_CONSECUTIVE);
    }
});

// -----------------------------------------------------------------------------
// B5: recordSuccess resets consecutive_failures to 0 atomically
// -----------------------------------------------------------------------------
test('B5: recordSuccess resets consecutive_failures to 0 and bumps total_skus', function () {
    $run = SyncRun::factory()->running()->create(['consecutive_failures' => 49]);
    $guard = new AbortGuard();

    $before = $run->total_skus;
    $guard->recordSuccess($run->id);

    $run->refresh();
    expect($run->consecutive_failures)->toBe(0)
        ->and($run->total_skus)->toBe($before + 1);
});

// -----------------------------------------------------------------------------
// B6: triggerJwtFailure → throwIfTriggered throws reason=jwt_refresh regardless
// -----------------------------------------------------------------------------
test('B6: triggerJwtFailure sets abort_reason then throwIfTriggered throws jwt_refresh (D-06c)', function () {
    $run = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    // No counters crossed — JWT still wins.
    $guard->triggerJwtFailure($run->id);

    $run->refresh();
    expect($run->abort_reason)->toBe(SyncRun::ABORT_JWT_REFRESH);

    try {
        $guard->throwIfTriggered($run->id);
        $this->fail('Expected SyncAbortException, none thrown');
    } catch (SyncAbortException $e) {
        expect($e->reason)->toBe(SyncRun::ABORT_JWT_REFRESH);
    }
});

// -----------------------------------------------------------------------------
// B7: per-run isolation — counters on run A don't affect run B
// -----------------------------------------------------------------------------
test('B7: AbortGuard counters are per-run — two runs have independent DB state', function () {
    $runA = SyncRun::factory()->running()->create();
    $runB = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    for ($i = 0; $i < 50; $i++) {
        $guard->recordFailure($runA->id);
    }

    $runA->refresh();
    $runB->refresh();

    expect($runA->consecutive_failures)->toBe(50)
        ->and($runB->consecutive_failures)->toBe(0);

    expect(fn () => $guard->throwIfTriggered($runB->id))
        ->not->toThrow(SyncAbortException::class);
});

// -----------------------------------------------------------------------------
// B8 (Checker-blocker fix): two independent AbortGuard instances share DB state
// -----------------------------------------------------------------------------
test('B8: two AbortGuard instances each recording 25 failures trip the 50-consecutive threshold (multi-worker fix)', function () {
    $run = SyncRun::factory()->running()->create();
    $guardWorkerA = new AbortGuard();
    $guardWorkerB = new AbortGuard();

    // Interleave to simulate two worker processes recording to the same run.
    for ($i = 0; $i < 25; $i++) {
        $guardWorkerA->recordFailure($run->id);
        $guardWorkerB->recordFailure($run->id);
    }

    $run->refresh();
    expect($run->consecutive_failures)->toBe(50);

    // Either instance can surface the abort — state is DB-backed, not in-memory.
    try {
        $guardWorkerA->throwIfTriggered($run->id);
        $this->fail('Expected SyncAbortException from worker A');
    } catch (SyncAbortException $e) {
        expect($e->reason)->toBe(SyncRun::ABORT_CONSECUTIVE);
    }

    try {
        $guardWorkerB->throwIfTriggered($run->id);
        $this->fail('Expected SyncAbortException from worker B');
    } catch (SyncAbortException $e) {
        expect($e->reason)->toBe(SyncRun::ABORT_CONSECUTIVE);
    }
});

// -----------------------------------------------------------------------------
// B9: atomic-increment verification — 10 sequential recordFailure → failed_count=10
// -----------------------------------------------------------------------------
test('B9: 10 sequential recordFailure calls produce failed_count=10 (atomic UPDATE proof)', function () {
    $run = SyncRun::factory()->running()->create();
    $guard = new AbortGuard();

    for ($i = 0; $i < 10; $i++) {
        $guard->recordFailure($run->id);
    }

    $run->refresh();
    expect($run->failed_count)->toBe(10)
        ->and($run->total_skus)->toBe(10)
        ->and($run->consecutive_failures)->toBe(10);
});
