<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

/**
 * 260719-wth — thrown by WooClient when a LIVE Woo write cannot be admitted
 * through the throttle without violating the safety invariants:
 *
 *   - the serialization lock ('woo:write') could not be acquired within
 *     services.woo.write_lock_wait_seconds (another worker is mid-write); or
 *   - the per-minute rate ceiling (services.woo.write_max_per_minute) is hit.
 *
 * This is a RETRYABLE condition: the correct response is to requeue the job so
 * the write is attempted again later, NEVER to write un-serialised / un-paced.
 * On a box shared with the WP storefront an un-throttled write burst is a
 * self-DoS (2026-07-19 incident: load spiked to 55, storefront went down).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * 260822-rmo — $retryAfterSeconds
 *
 * "Requeue" used to be left to the queue's own tries/backoff, which does NOT
 * do what the word implies: a thrown exception CONSUMES a queue attempt, so a
 * job with tries=3 exhausted its whole budget inside the burst window and
 * died (5,319 such failed_jobs rows on prod between 2026-08-18 and 08-22).
 *
 * The exception now carries how long the caller should wait, so a queued
 * caller can `release($e->retryAfterSeconds())` — a deferral, which does not
 * count as an application failure. Callers use HandlesWooWriteThrottle.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * The one synchronous caller (products:push-status-to-woo) still surfaces it
 * as a per-row error and continues — no un-serialised write happens either way.
 */
final class WooWriteThrottleException extends \RuntimeException
{
    /**
     * Fallback delay when the throttle could not report a precise reset —
     * one full limiter window, so the retry lands after the ceiling clears.
     */
    public const DEFAULT_RETRY_AFTER_SECONDS = 60;

    public function __construct(
        string $message,
        private readonly ?int $retryAfterSeconds = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Seconds to wait before re-attempting the write.
     *
     * Always >= 1 so a caller can never release() with a zero delay and spin
     * the worker against a still-closed window.
     */
    public function retryAfterSeconds(): int
    {
        return max(1, $this->retryAfterSeconds ?? self::DEFAULT_RETRY_AFTER_SECONDS);
    }
}
