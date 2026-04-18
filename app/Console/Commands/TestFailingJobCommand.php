<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Alerting\Jobs\TestFailingJob;
use Illuminate\Console\Command;

/**
 * Operator tool: dispatches a deliberately-failing job so the full
 * failed-job alert path can be exercised end-to-end.
 *
 * Expected flow:
 *   1. `php artisan alerts:test-failure`
 *   2. Queue worker picks up TestFailingJob
 *   3. Job throws RuntimeException('Deliberate failure — alerts:test-failure')
 *   4. Illuminate\Queue\Events\JobFailed fires
 *   5. ThrottledFailedJobNotifier sends ONE mail to every active AlertRecipient
 *   6. A second `alerts:test-failure` within 5 min sends ZERO mails (D-13 dedup)
 */
class TestFailingJobCommand extends Command
{
    protected $signature = 'alerts:test-failure';

    protected $description = 'Dispatches a deliberately-failing job to exercise the failed-job alert path.';

    public function handle(): int
    {
        TestFailingJob::dispatch();

        $this->info('Test job dispatched. After the queue processes it, a single alert email should land at each active AlertRecipient (dedup window: 5 minutes).');

        return self::SUCCESS;
    }
}
