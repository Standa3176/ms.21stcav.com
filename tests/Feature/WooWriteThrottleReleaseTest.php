<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Listeners\PushPriceChangeToWoo;
use App\Domain\Pricing\Services\WooRegularPriceFormatter;
use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Jobs\ProcessAutoCreateImageJob;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\ProductAutoCreate\Listeners\PushProductFieldsToWoo;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Concerns\HandlesWooWriteThrottle;
use App\Domain\Sync\Exceptions\WooWriteThrottleException;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Support\WooWriteMetrics;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| 260822-rmo — a throttled Woo write DEFERS; it does not consume a life
|--------------------------------------------------------------------------
|
| Regression cover for the 2026-08-18 → 2026-08-22 price-loss incident:
| 5,319 failed_jobs rows, every one a WooWriteThrottleException that ate all
| three of PushPriceChangeToWoo's attempts inside the burst window (T+0,
| T+30, T+150) and killed the job — losing the price permanently.
|
| The contract under test:
|   - throttle  → release($retryAfter), NO exception escapes
|   - genuine   → exception propagates, normal failure path
|   - retryUntil bounds the deferral window
|   - maxExceptions is what still terminates genuine failures
*/

/**
 * Minimal queue-job double. Records release() so we can assert the job was
 * DEFERRED rather than failed, without a real queue driver.
 */
function throttleFakeJob(int $attempts = 1): object
{
    return new class($attempts) implements JobContract
    {
        public ?int $releasedWith = null;

        public bool $wasDeleted = false;

        public bool $wasFailed = false;

        public function __construct(private int $attemptCount) {}

        public function release($delay = 0)
        {
            $this->releasedWith = (int) $delay;
        }

        public function attempts()
        {
            return $this->attemptCount;
        }

        public function delete()
        {
            $this->wasDeleted = true;
        }

        public function fail($e = null)
        {
            $this->wasFailed = true;
        }

        // ── Unused JobContract surface ─────────────────────────────────────
        public function uuid()
        {
            return 'fake-uuid';
        }

        public function getJobId()
        {
            return 'fake-job-id';
        }

        public function payload()
        {
            return [];
        }

        public function maxTries()
        {
            return 3;
        }

        public function maxExceptions()
        {
            return 3;
        }

        public function backoff()
        {
            return null;
        }

        public function retryUntil()
        {
            return null;
        }

        public function timeout()
        {
            return null;
        }

        public function shouldFailOnTimeout()
        {
            return false;
        }

        public function isDeleted()
        {
            return $this->wasDeleted;
        }

        public function isReleased()
        {
            return $this->releasedWith !== null;
        }

        public function isDeletedOrReleased()
        {
            return $this->wasDeleted || $this->isReleased();
        }

        public function hasFailed()
        {
            return $this->wasFailed;
        }

        public function markAsFailed()
        {
            $this->wasFailed = true;
        }

        public function getName()
        {
            return 'fake';
        }

        public function resolveName()
        {
            return 'fake';
        }

        public function getConnectionName()
        {
            return 'redis';
        }

        public function getQueue()
        {
            return 'woo-writes';
        }

        public function getRawBody()
        {
            return '';
        }

        public function fire()
        {
            // Never invoked — the listener is called directly.
        }

        public function resolveQueuedJobClass()
        {
            return null;
        }
    };
}

/**
 * WooClient stub whose put() throws whatever we hand it.
 */
function throwingWooClient(\Throwable $toThrow): WooClient
{
    return new class($toThrow) extends WooClient
    {
        public int $putCalls = 0;

        public function __construct(private \Throwable $toThrow)
        {
            // Skip parent constructor — no logger/resolver needed for a stub
            // (mirrors PushDivergenceToWooCommandTest's bindDivergenceStub).
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->putCalls++;

            throw $this->toThrow;
        }
    };
}

/**
 * ProductPriceChanged carries margin/resolution metadata this suite does not
 * exercise — one helper keeps the throttle cases readable.
 */
function priceChangedEvent(int $productId, string $sku, int $newPennies = 1299): ProductPriceChanged
{
    return new ProductPriceChanged(
        productId: $productId,
        variantId: null,
        sku: $sku,
        oldPennies: 1000,
        newPennies: $newPennies,
        marginBasisPoints: 2000,
        resolutionSource: 'default_tier',
    );
}

function pricePushListener(WooClient $woo): PushPriceChangeToWoo
{
    return new PushPriceChangeToWoo($woo, app(WooRegularPriceFormatter::class));
}

// ── (a) The incident path: throttle must DEFER ────────────────────────────

it('releases the price push instead of throwing when the Woo write ceiling is hit', function (): void {
    $product = Product::factory()->create([
        'sku' => 'THR-001',
        'woo_product_id' => 5001,
        'status' => 'publish',
    ]);

    $woo = throwingWooClient(new WooWriteThrottleException(
        'Woo live-write rate ceiling (60/min) reached — deferring; window resets in 19s.',
        retryAfterSeconds: 19,
    ));

    $listener = pricePushListener($woo);
    $job = throttleFakeJob();
    $listener->setJob($job);

    // No exception escapes — that is the whole point. A thrown throttle is
    // what burned the attempt budget and killed 5,319 pushes.
    $listener->handle(priceChangedEvent((int) $product->id, 'THR-001'));

    expect($job->releasedWith)->toBe(19)
        ->and($job->wasFailed)->toBeFalse();
});

it('releases for the exact window the throttle reports, never zero', function (): void {
    $product = Product::factory()->create([
        'sku' => 'THR-002',
        'woo_product_id' => 5002,
        'status' => 'publish',
    ]);

    // A throttle that reports no delay at all must still defer by >= 1s,
    // otherwise the single woo-writes worker spins against a closed window.
    $woo = throwingWooClient(new WooWriteThrottleException('ceiling reached'));

    $listener = pricePushListener($woo);
    $job = throttleFakeJob();
    $listener->setJob($job);

    $listener->handle(priceChangedEvent((int) $product->id, 'THR-002'));

    expect($job->releasedWith)->toBe(WooWriteThrottleException::DEFAULT_RETRY_AFTER_SECONDS)
        ->and($job->releasedWith)->toBeGreaterThanOrEqual(1);
});

it('counts a deferral as deferred, not as a failure', function (): void {
    $product = Product::factory()->create([
        'sku' => 'THR-003',
        'woo_product_id' => 5003,
        'status' => 'publish',
    ]);

    $before = WooWriteMetrics::read(WooWriteMetrics::DEFERRED);

    $listener = pricePushListener(throwingWooClient(
        new WooWriteThrottleException('ceiling', retryAfterSeconds: 12),
    ));
    $listener->setJob(throttleFakeJob());

    $listener->handle(priceChangedEvent((int) $product->id, 'THR-003'));

    expect(WooWriteMetrics::read(WooWriteMetrics::DEFERRED))->toBe($before + 1)
        ->and(WooWriteMetrics::read(WooWriteMetrics::FAILED))->toBe(0);
});

it('logs a deferral at info level, never as an error', function (): void {
    $product = Product::factory()->create([
        'sku' => 'THR-004',
        'woo_product_id' => 5004,
        'status' => 'publish',
    ]);

    // A repricing burst produces thousands of these; at warning/error level
    // they would drown the log and page the operator for normal behaviour.
    Log::shouldReceive('info')->atLeast()->once()
        ->withArgs(fn (string $message): bool => $message === 'woo.write_throttled_deferred');
    Log::shouldReceive('error')->never();
    Log::shouldReceive('warning')->never();
    Log::shouldReceive('debug')->zeroOrMoreTimes();

    $listener = pricePushListener(throwingWooClient(
        new WooWriteThrottleException('ceiling', retryAfterSeconds: 7),
    ));
    $listener->setJob(throttleFakeJob());

    $listener->handle(priceChangedEvent((int) $product->id, 'THR-004'));
});

// ── (b) Genuine failures still fail ───────────────────────────────────────

it('still throws on a genuine Woo error so the normal failure path runs', function (): void {
    $product = Product::factory()->create([
        'sku' => 'THR-005',
        'woo_product_id' => 5005,
        'status' => 'publish',
    ]);

    $listener = pricePushListener(throwingWooClient(new RuntimeException('Woo 500 Internal Server Error')));
    $job = throttleFakeJob();
    $listener->setJob($job);

    expect(fn () => $listener->handle(priceChangedEvent((int) $product->id, 'THR-005')))->toThrow(RuntimeException::class);

    // NOT released — a genuine error must consume the maxExceptions budget.
    expect($job->releasedWith)->toBeNull();
});

it('records a terminal failure with enough detail to diagnose and re-drive it', function (): void {
    $product = Product::factory()->create([
        'sku' => 'THR-006',
        'woo_product_id' => 5006,
        'status' => 'publish',
    ]);

    $before = WooWriteMetrics::read(WooWriteMetrics::FAILED);

    $listener = pricePushListener(throwingWooClient(new RuntimeException('boom')));
    $listener->failed(
        priceChangedEvent((int) $product->id, 'THR-006'),
        new RuntimeException('Woo 500 Internal Server Error'),
    );

    expect(WooWriteMetrics::read(WooWriteMetrics::FAILED))->toBe($before + 1);

    // The audit row carries the SKU, the Woo id and the price that was lost —
    // before 260822-rmo a dead push left nothing but a failed_jobs row.
    $activity = DB::table('activity_log')->latest('id')->first();
    expect($activity)->not->toBeNull();
    expect($activity->description)->toBe('pricing.woo_push_failed');

    $properties = json_decode((string) $activity->properties, true);
    expect($properties['sku'])->toBe('THR-006')
        ->and($properties['woo_product_id'])->toBe(5006)
        ->and($properties['intended_regular_price'])->toBe('12.99')
        ->and($properties['error_class'])->toBe(RuntimeException::class);
});

// ── (c) The retryUntil / maxExceptions contract ───────────────────────────

it('bounds the deferral window with retryUntil rather than retrying forever', function (): void {
    config(['services.woo.write_retry_until_minutes' => 180]);

    $deadline = (new PushPriceChangeToWoo(app(WooClient::class), app(WooRegularPriceFormatter::class)))->retryUntil();

    expect($deadline->getTimestamp())->toBeGreaterThan(now()->getTimestamp())
        // Bounded: a stuck job cannot outlive the window and collide with the
        // next daily repricing run.
        ->and($deadline->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(180)->getTimestamp() + 5);
});

it('honours a shortened retry-until window from config', function (): void {
    config(['services.woo.write_retry_until_minutes' => 10]);

    $deadline = (new PushPriceChangeToWoo(app(WooClient::class), app(WooRegularPriceFormatter::class)))->retryUntil();

    expect($deadline->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(10)->getTimestamp() + 5);
});

it('requires every throttle-aware Woo writer to declare a maxExceptions budget', function (string $class): void {
    // retryUntil() makes the queue SKIP the attempts check entirely
    // (Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts). maxExceptions is
    // then the ONLY thing that still terminates a genuinely broken job — a
    // class that adopts the trait without it would retry a hard error for the
    // whole deferral window.
    expect(class_uses_recursive($class))->toContain(HandlesWooWriteThrottle::class);

    $maxExceptions = (new ReflectionClass($class))->getDefaultProperties()['maxExceptions'] ?? null;

    expect($maxExceptions)->toBeInt()->toBeGreaterThan(0);
})->with([
    PushPriceChangeToWoo::class,
    PublishProductJob::class,
    CreateWooProductJob::class,
    ProcessAutoCreateImageJob::class,
    PushProductFieldsToWoo::class,
]);

// ── (d) The preflight probe ───────────────────────────────────────────────

it('reports no wait when the Woo write window is open', function (): void {
    config(['services.woo.write_enabled' => true]);
    config(['services.woo.write_max_per_minute' => 60]);

    expect(app(WooClient::class)->writeThrottleRetryAfter())->toBeNull();
});

it('reports a wait once the per-minute ceiling is exhausted', function (): void {
    config(['services.woo.write_enabled' => true]);
    config(['services.woo.write_max_per_minute' => 2]);

    RateLimiter::hit('woo-write-rate', 60);
    RateLimiter::hit('woo-write-rate', 60);

    $wait = app(WooClient::class)->writeThrottleRetryAfter();

    expect($wait)->toBeInt()->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
});

it('never reports a wait in shadow mode — a SyncDiff hits no external system', function (): void {
    config(['services.woo.write_enabled' => false]);
    config(['services.woo.write_max_per_minute' => 1]);

    RateLimiter::hit('woo-write-rate', 60);
    RateLimiter::hit('woo-write-rate', 60);

    expect(app(WooClient::class)->writeThrottleRetryAfter())->toBeNull();
});
