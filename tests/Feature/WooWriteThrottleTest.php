<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Exceptions\WooWriteThrottleException;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\Response as WooResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 260719-wth — throttle at the WooClient chokepoint. All LIVE writes must be
 * (1) serialised to ≤1 concurrent via the 'woo:write' lock and (2) paced by a
 * min-interval + per-minute rate ceiling. Shadow mode must bypass BOTH.
 */
beforeEach(function () {
    config(['services.woo.write_enabled' => true]);
    // put() routes through POST by default; pin off so put() mocks match $sdk->put().
    config(['services.woo.use_post_for_updates' => false]);
    // Deterministic throttle knobs for the tests.
    config(['services.woo.write_max_per_minute' => 60]);
    config(['services.woo.write_min_interval_ms' => 250]);
    config(['services.woo.write_lock_wait_seconds' => 30]);
    config(['services.woo.write_lock_seconds' => 120]);
    Context::add('correlation_id', (string) Str::uuid());
});

/**
 * A WooClient whose sleepMicros() is captured (never actually sleeps) so we can
 * assert the pacing interval without real delays.
 */
function throttleTestClient($mockInner): WooClient
{
    return new class(app(IntegrationLogger::class), app(IntegrationCredentialResolver::class), $mockInner) extends WooClient
    {
        public int $sleptMicros = 0;

        public array $sleptSamples = [];

        protected function sleepMicros(int $micros): void
        {
            $this->sleptMicros += $micros;
            $this->sleptSamples[] = $micros;
        }
    };
}

function throttleSuccessMock(): AutomatticClient
{
    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;
    $mockInner->shouldReceive('put')->andReturn(['id' => 1234]);
    $mockInner->shouldReceive('post')->andReturn(['id' => 1234]);

    return $mockInner;
}

// (a) The serialization lock gates every live write — if another owner holds
//     'woo:write' and it can't be acquired within the wait, we throw a retryable
//     exception rather than writing un-serialised.
it('throws a retryable throttle exception when the woo:write lock is already held', function () {
    config(['services.woo.write_lock_wait_seconds' => 0]);

    // Simulate a concurrent worker mid-write: acquire (and hold) the lock.
    $held = Cache::lock('woo:write', 120);
    expect($held->get())->toBeTrue();

    $mockInner = Mockery::mock(AutomatticClient::class);
    // The SDK write must NEVER be reached while the lock is held.
    $mockInner->shouldReceive('put')->never();
    $mockInner->shouldReceive('post')->never();

    $client = throttleTestClient($mockInner);

    expect(fn () => $client->put('products/1234', ['regular_price' => '9.99']))
        ->toThrow(WooWriteThrottleException::class);

    $held->release();
});

// (a-cont) A live write acquires AND releases the lock (finally) so the next
//          write can proceed — proving single-file serialization, not deadlock.
it('releases the woo:write lock after a successful live write', function () {
    $client = throttleTestClient(throttleSuccessMock());
    $client->put('products/1234', ['regular_price' => '9.99']);

    // Lock is free again: a fresh owner can acquire it.
    $after = Cache::lock('woo:write', 5);
    expect($after->get())->toBeTrue();
    $after->release();
});

// (b) The rate limiter is consulted on every live write. 260726-wtc: the limiter
//     key is 'woo-write-rate' (WooClient::WRITE_RATE_LIMITER_KEY), DISTINCT from
//     the Cache::lock('woo:write') name.
it('consults the woo-write-rate rate limiter on a live write', function () {
    expect(RateLimiter::attempts('woo-write-rate'))->toBe(0);

    $client = throttleTestClient(throttleSuccessMock());
    $client->put('products/1234', ['regular_price' => '9.99']);

    expect(RateLimiter::attempts('woo-write-rate'))->toBe(1);
    // The lock key must NOT be used by the limiter.
    expect(RateLimiter::attempts('woo:write'))->toBe(0);
});

// (b-cont) When the per-minute ceiling is already exhausted, the write is
//          refused with a retryable exception (job requeues) — not sent.
it('refuses the write with a retryable exception when the per-minute ceiling is hit', function () {
    config(['services.woo.write_max_per_minute' => 3]);

    // Exhaust the window on the limiter key (260726-wtc: 'woo-write-rate').
    RateLimiter::hit('woo-write-rate', 60);
    RateLimiter::hit('woo-write-rate', 60);
    RateLimiter::hit('woo-write-rate', 60);

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockInner->shouldReceive('put')->never();
    $mockInner->shouldReceive('post')->never();

    $client = throttleTestClient($mockInner);

    expect(fn () => $client->put('products/1234', ['x' => 1]))
        ->toThrow(WooWriteThrottleException::class);
});

// (b-cont) The min-interval pacing sleeps the remainder when the previous write
//          was too recent — asserted via the captured sleepMicros seam.
it('paces writes by sleeping the min-interval remainder since the last write', function () {
    // Pretend a write just happened (now) → full 250ms interval must be paced.
    Cache::put('woo:write:last_ts', (int) (microtime(true) * 1_000_000), now()->addMinutes(5));

    $client = throttleTestClient(throttleSuccessMock());
    $client->put('products/1234', ['regular_price' => '9.99']);

    // 250ms = 250_000 micros; elapsed is only a few micros so we sleep ~all of it.
    expect($client->sleptMicros)->toBeGreaterThanOrEqual(240_000)
        ->and($client->sleptMicros)->toBeLessThanOrEqual(250_000);
});

it('does not pace the first write when there is no recorded last-write timestamp', function () {
    Cache::forget('woo:write:last_ts');

    $client = throttleTestClient(throttleSuccessMock());
    $client->put('products/1234', ['regular_price' => '9.99']);

    expect($client->sleptMicros)->toBe(0);
    // …but it records the timestamp for the next write to pace against.
    expect(Cache::get('woo:write:last_ts'))->not->toBeNull();
});

// (c) Shadow mode (write_enabled=false) takes NEITHER lock NOR limiter and still
//     routes to a SyncDiff.
it('shadow mode bypasses the lock, the limiter and the pacing entirely', function () {
    config(['services.woo.write_enabled' => false]);
    Cache::forget('woo:write:last_ts');

    $client = throttleTestClient(Mockery::mock(AutomatticClient::class));
    $result = $client->put('products/1234', ['regular_price' => '9.99']);

    // Routed to SyncDiff (shadow behaviour preserved).
    expect($result)->toMatchArray(['shadow_mode' => true]);
    expect(SyncDiff::count())->toBe(1);

    // Limiter never consulted (260726-wtc key + the old shared key both clean).
    expect(RateLimiter::attempts('woo-write-rate'))->toBe(0);
    expect(RateLimiter::attempts('woo:write'))->toBe(0);
    // No pacing timestamp written.
    expect(Cache::get('woo:write:last_ts'))->toBeNull();
    // No sleep.
    expect($client->sleptMicros)->toBe(0);
    // Lock is free (never taken).
    $lock = Cache::lock('woo:write', 5);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

// ══════════════════════════════════════════════════════════════════
// 260726-wtc — cache-key collision fix (unserialize error on every live write)
// ══════════════════════════════════════════════════════════════════

// (d) REGRESSION GUARD — the invariant whose violation caused the 2026-07-26 prod
//     fatal: the Woo-write rate-limiter key MUST NOT equal the Cache::lock name
//     'woo:write'. On Redis a shared key made the limiter unserialize() the lock's
//     raw owner token → "unserialize(): Error at offset 0 of 16 bytes" on EVERY
//     live write. This test FAILS if a future edit re-unifies the two keys.
it('keeps the Woo-write rate-limiter key distinct from the woo:write lock name (260726-wtc guard)', function () {
    $limiterKey = (new ReflectionClassConstant(WooClient::class, 'WRITE_RATE_LIMITER_KEY'))->getValue();

    // The bug: limiter key == lock name. The guard: they must never be equal.
    expect($limiterKey)->not->toBe('woo:write');
    expect($limiterKey)->toBe('woo-write-rate');

    // The four Woo-write cache keys must be mutually distinct at the bare-key level:
    //   lock 'woo:write' · limiter 'woo-write-rate' · limiter-timer 'woo-write-rate:timer'
    //   · pacing 'woo:write:last_ts'.
    $keys = ['woo:write', $limiterKey, $limiterKey.':timer', 'woo:write:last_ts'];
    expect($keys)->toBe(array_unique($keys));
});

// (e) BEHAVIOURAL — a live write completes throttlePace and performs the SDK write
//     even when the lock's raw owner-token value is sitting at cache key 'woo:write'
//     (exactly what Cache::lock('woo:write') writes there during a live write).
//     This proves the limiter reads its OWN key and never touches the lock key.
//
//     NOTE: the *raw-token unserialize()* fatal is Redis-only — the array test
//     store neither serializes cache values nor stores locks at the cache key, so
//     it cannot reproduce the actual "offset 0 of 16 bytes" error. The true
//     integration proof is the prod re-canary (brands:retag-products-on-woo).
it('a live write no longer routes the rate limiter through the woo:write lock key (260726-wtc)', function () {
    // Simulate the lock's raw ~16-byte owner token parked at the lock key.
    $rawOwnerToken = Str::random(16);
    Cache::put('woo:write', $rawOwnerToken, now()->addMinutes(5));

    $client = throttleTestClient(throttleSuccessMock());
    $result = $client->put('products/1234', ['regular_price' => '9.99']);

    // throttlePace raised no error and the SDK write was reached.
    expect($result)->toBe(['id' => 1234]);

    // The limiter incremented its OWN key…
    expect(RateLimiter::attempts('woo-write-rate'))->toBe(1);
    // …and left the lock key's raw token completely untouched (never overwrote it
    // with a counter, never consumed it). On Redis the OLD code read THIS value as
    // an attempts counter and unserialize()d it → fatal; the new key sidesteps it.
    expect(Cache::get('woo:write'))->toBe($rawOwnerToken);
});
