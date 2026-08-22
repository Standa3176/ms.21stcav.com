<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 260822-rmo — read-only view of the Woo live-write rate window.
 *
 * "Can a live write be admitted right now?" needs only config plus the
 * RateLimiter — no credentials, no HTTP, no client state. Keeping it OFF
 * WooClient matters in practice: WooClient is mocked strictly by a dozen
 * existing test suites, so an extra instance method would force every one of
 * those mocks to grow an expectation for a call the job under test does not
 * care about.
 *
 * Used by HandlesWooWriteThrottle::releaseIfWooWriteWindowClosed() for jobs
 * that mutate local state BEFORE their Woo write (CreateWooProductJob), which
 * must defer before touching anything rather than at the write site.
 *
 * Consumes NO token and takes NO lock, so it is inherently racy — another
 * worker can take the last token between probe and write. The write-site
 * catch of WooWriteThrottleException remains the real guarantee.
 */
final class WooWriteWindow
{
    /**
     * Seconds until the per-minute window reopens, or null when a write would
     * be admitted now (including shadow mode, where a SyncDiff hits no
     * external system and the throttle is irrelevant).
     */
    public static function retryAfterSeconds(): ?int
    {
        if (! (bool) config('services.woo.write_enabled', false)) {
            return null;
        }

        $maxPerMinute = (int) config('services.woo.write_max_per_minute', 60);
        if ($maxPerMinute <= 0) {
            return null;
        }

        if (! RateLimiter::tooManyAttempts(WooClient::WRITE_RATE_LIMITER_KEY, $maxPerMinute)) {
            return null;
        }

        return max(1, RateLimiter::availableIn(WooClient::WRITE_RATE_LIMITER_KEY));
    }
}
