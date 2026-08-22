<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 260822-rmo — daily counters for the Woo write path.
 *
 * Answers the operator questions that had no answer during the 2026-08-18→22
 * price-push loss (5,319 failed_jobs rows, discovered only by reading
 * failed_jobs by hand):
 *
 *   deferred — Woo writes throttled and released today
 *   failed   — Woo writes that genuinely failed today
 *
 * Cache-backed (Redis in prod) at `woo:write:metrics:{name}:{Y-m-d}`, 48h TTL.
 * Read back in the `products:push-divergence-to-woo` summary table.
 *
 * Deliberately a counter and not a new subsystem: enough to tell "healthy"
 * from "the write path is jammed again" without another table to prune.
 *
 * A plain final class rather than static methods on HandlesWooWriteThrottle —
 * PHP 8.1+ deprecates calling a static method directly on a trait name, and
 * console commands need to read these counters without using the trait.
 */
final class WooWriteMetrics
{
    public const DEFERRED = 'deferred';

    public const FAILED = 'failed';

    public static function increment(string $name, int $by = 1): void
    {
        $key = self::key($name);

        try {
            // add() seeds the key WITH its TTL; increment() alone on a missing
            // key creates it without an expiry on some stores.
            Cache::add($key, 0, now()->addHours(48));
            Cache::increment($key, $by);
        } catch (\Throwable $e) {
            // Metrics must NEVER break a write path.
            Log::debug('woo.write_metric_failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    public static function read(string $name, ?string $date = null): int
    {
        try {
            return (int) Cache::get(self::key($name, $date), 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function key(string $name, ?string $date = null): string
    {
        return 'woo:write:metrics:'.$name.':'.($date ?? now()->format('Y-m-d'));
    }
}
