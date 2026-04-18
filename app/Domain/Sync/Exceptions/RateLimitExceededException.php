<?php

declare(strict_types=1);

namespace App\Domain\Sync\Exceptions;

/**
 * Thrown by WooClient::writeLive() when Woo returns HTTP 429 after exhausting
 * the 5-retry exponential-backoff schedule (500/1500/4500/13500/30000 ms + jitter).
 *
 * Per SYNC-10 + D-06: this is tracked as a single SyncError (does NOT spam the
 * per-run consecutive_failures counter every retry attempt) so one rate-limit
 * burst produces one alert, not five.
 */
final class RateLimitExceededException extends \RuntimeException
{
}
