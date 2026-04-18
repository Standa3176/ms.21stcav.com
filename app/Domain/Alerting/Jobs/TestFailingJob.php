<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by `php artisan alerts:test-failure`. Always throws so the
 * failed-job alert path can be verified end-to-end.
 *
 * tries=1: we want the failure signal on the very first attempt, not after
 * retries, so operators can time the "how fast does the alert land?" test.
 */
final class TestFailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        // onQueue in constructor (Plan 04 pattern — PHP 8.4 trait property conflict if we redeclare $queue).
        $this->onQueue('default');
    }

    public function handle(): void
    {
        throw new \RuntimeException('Deliberate failure — alerts:test-failure');
    }
}
