<?php

declare(strict_types=1);

namespace App\Domain\Sync\Concerns;

use App\Domain\Sync\Exceptions\WooWriteThrottleException;
use App\Domain\Sync\Support\WooWriteMetrics;
use App\Domain\Sync\Support\WooWriteWindow;
use Illuminate\Support\Facades\Log;

/**
 * 260822-rmo — throttle-aware queue behaviour for every job/listener that
 * writes to Woo through WooClient.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 *
 * WooClient::throttlePace() signals "too fast" by THROWING
 * WooWriteThrottleException. A thrown exception CONSUMES a queue attempt, so
 * a job with tries=3 / backoff=[30,120,300] spent its entire budget inside
 * the burst window and died at T+150s. Verified on prod: 5,319 failed_jobs
 * rows between 2026-08-18 and 2026-08-22, every one of them a throttle
 * signal, every one a permanently lost Woo write.
 *
 * A throttle is NOT a failure. It is back-pressure. The correct response is
 * to DEFER — release the job for as long as the ceiling stays closed.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE TWO-KNOB CONTRACT (both required — verified against laravel/framework 12.56.0)
 *
 *   retryUntil()   Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts() skips
 *                  the attempts check entirely while retryUntil is in the
 *                  future (Worker.php:563-571). This is what lets a deferred
 *                  job outlive `tries` during a long repricing burst.
 *
 *   maxExceptions  Worker::markJobAsFailedIfWillExceedMaxExceptions() counts
 *                  UNCAUGHT exceptions in a separate cache counter
 *                  (Worker.php:608-624), independent of retryUntil. This is
 *                  what still terminates a genuinely broken job.
 *
 * Using retryUntil WITHOUT maxExceptions would make a genuine Woo 500 retry
 * for the whole window. Using maxExceptions WITHOUT retryUntil leaves the
 * attempts counter free to kill a merely-throttled job. Any class adopting
 * this trait MUST declare both — see the audit test in
 * tests/Feature/WooWriteThrottleReleaseTest.php.
 *
 * Queued LISTENERS work too: Events\Dispatcher::propagateListenerOptions()
 * (Dispatcher.php:705-733) copies retryUntil + maxExceptions onto
 * CallQueuedListener, and setJobInstanceIfNecessary() injects the job so
 * release() reaches the real queue job.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Requires the using class to also `use InteractsWithQueue` (for release()).
 */
trait HandlesWooWriteThrottle
{
    /**
     * Deadline after which a still-throttled job gives up and fails.
     *
     * Bounded on purpose — this is a deferral window, NOT an infinite retry
     * loop. The default (3h) comfortably outlasts a full-catalogue repricing
     * burst at the 60/min ceiling (~10,800 writes) while still guaranteeing
     * the job cannot linger past the next day's run.
     *
     * Computed at DISPATCH time for queued listeners (propagateListenerOptions
     * calls it eagerly), so the deadline is anchored to when the price change
     * happened — exactly the semantics we want.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(
            max(1, (int) config('services.woo.write_retry_until_minutes', 180)),
        );
    }

    /**
     * Defer this job because Woo write capacity is momentarily unavailable.
     *
     * Logged at INFO, never warning/error — throttling is the system working
     * as designed, and a burst would otherwise emit thousands of alert-level
     * lines. The daily counter is what answers "how much are we throttling?".
     *
     * @return true always — so callers can `return $this->releaseForWooThrottle($e);`
     *              inside a void handler via a bare `if (...) { ...; return; }`.
     */
    protected function releaseForWooThrottle(WooWriteThrottleException $e, array $context = []): bool
    {
        $delay = $e->retryAfterSeconds();

        WooWriteMetrics::increment(WooWriteMetrics::DEFERRED);

        Log::info('woo.write_throttled_deferred', array_merge([
            'job' => static::class,
            'release_seconds' => $delay,
            'reason' => $e->getMessage(),
        ], $context));

        // InteractsWithQueue::release() — no-op when the class is executed
        // synchronously (dispatchSync / direct call), which keeps the CLI and
        // test paths working unchanged.
        $this->release($delay);

        return true;
    }

    /**
     * Defer BEFORE doing any local work, when the write window is already shut.
     *
     * For jobs that mutate local state before reaching the Woo write
     * (CreateWooProductJob creates the local Product row first, which its own
     * duplicate gate would then reject on a re-run), releasing at the write
     * site would strand half-built state. Those jobs call this first.
     *
     * Returns true when the job was released and handle() must return
     * immediately; false when it is clear to proceed.
     */
    protected function releaseIfWooWriteWindowClosed(array $context = []): bool
    {
        $retryAfter = WooWriteWindow::retryAfterSeconds();

        if ($retryAfter === null) {
            return false;
        }

        return $this->releaseForWooThrottle(
            new WooWriteThrottleException(
                'Woo live-write window already closed at job start — deferring before any local work.',
                retryAfterSeconds: $retryAfter,
            ),
            $context,
        );
    }
}
