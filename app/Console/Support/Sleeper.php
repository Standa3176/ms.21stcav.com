<?php

declare(strict_types=1);

namespace App\Console\Support;

/**
 * Thin, injectable sleep seam (260726-slw).
 *
 * Console commands that self-pace (RetagProductsOnWooCommand --slow, the
 * per-PUT throttle, discovery retry-backoff) route ALL waiting through this
 * class instead of calling sleep()/usleep() directly. Production behaviour is
 * byte-identical to a raw sleep()/usleep(); the only reason it exists is
 * testability — Pest binds a recording no-op subclass into the container via
 * `app()->instance(Sleeper::class, $stub)` so the suite never actually waits
 * and can ASSERT the requested pause durations (adaptive-backoff coverage).
 *
 * Mirrors the WooClient::sleepMicros() isolation pattern already used by
 * WooRateLimitTest — same seam, command-layer edition.
 */
class Sleeper
{
    /**
     * Sleep for whole seconds (self-pacing pauses between slow-mode batches).
     */
    public function seconds(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * Sleep for microseconds (per-PUT throttle + discovery retry backoff).
     */
    public function micros(int $micros): void
    {
        if ($micros > 0) {
            usleep($micros);
        }
    }
}
