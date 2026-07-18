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
 * Callers that are queued jobs requeue automatically (tries/backoff). The one
 * synchronous caller (products:push-status-to-woo) surfaces it as a per-row
 * error and continues — no un-serialised write happens either way.
 */
final class WooWriteThrottleException extends \RuntimeException {}
