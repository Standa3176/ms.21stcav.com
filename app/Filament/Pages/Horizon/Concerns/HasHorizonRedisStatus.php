<?php

declare(strict_types=1);

namespace App\Filament\Pages\Horizon\Concerns;

use Illuminate\Support\Facades\Redis;

/**
 * Phase 7 Plan 02 — D-03 native Horizon Pages (post-09.1 follow-up #3).
 *
 * Shared Redis-availability probe for the 8 native Horizon Filament Pages.
 * Horizon's repository contracts (JobRepository, MetricsRepository, etc.)
 * all read from Redis; without a reachable Redis they throw on every call,
 * cascading into a 500 across every page.
 *
 * The probe wraps Redis::connection(...)->ping() in a try/catch and lets
 * each Page render a Filament warning banner (via {@see getRedisBannerData()})
 * BEFORE attempting any data load. The banner is purely cosmetic — the Page's
 * data-load code (table query / widget getStats / chart getData) MUST also
 * short-circuit when getRedisBannerData() returns non-null, otherwise the
 * underlying repository call still throws.
 *
 * Connection is resolved via config('horizon.use', 'default') so the probe
 * matches whichever Redis connection Horizon itself is configured to use.
 *
 * @internal Trait — only intended for use by App\Filament\Pages\Horizon\* Pages.
 */
trait HasHorizonRedisStatus
{
    /**
     * Probe Redis with a minimal PING command.
     *
     * @return array{ok: bool, error: string|null}
     */
    protected function getRedisStatus(): array
    {
        try {
            Redis::connection(config('horizon.use', 'default'))->ping();

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Return banner payload for the Blade view, or null when Redis is healthy.
     *
     * Pages should treat a non-null return as "do not load data — render the
     * banner only" to avoid the cascade described in the class docblock.
     *
     * @return array{message: string, error: string}|null
     */
    public function getRedisBannerData(): ?array
    {
        $status = $this->getRedisStatus();
        if ($status['ok']) {
            return null;
        }

        return [
            'message' => 'Horizon requires Redis. Currently unreachable.',
            'error' => (string) $status['error'],
        ];
    }
}
