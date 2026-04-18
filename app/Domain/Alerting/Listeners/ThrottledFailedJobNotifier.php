<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Listeners;

use App\Domain\Alerting\Notifiables\AlertDistribution;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\FailedJobMonitor\Notification as FailedJobMonitorNotification;

/**
 * D-13 dedup: same failure signature within 5 minutes = ONE alert, not N.
 *
 * Fingerprint combines (job class + exception class + exception message);
 * cached via Cache::add (atomic lock) for 5 minutes. Duplicate events
 * short-circuit before Notification::send is called.
 *
 * Why Cache::add instead of has+put: atomic get-or-set. Two failed jobs
 * hitting the listener simultaneously could both pass a `Cache::has` check
 * and each fire a notification. Cache::add returns false if the key
 * already exists, so only one listener wins the lock.
 *
 * T-05-06 (double-send suppression): spatie/laravel-failed-job-monitor's
 * built-in auto-listener is disabled via `notifiable => null` in
 * config/failed-job-monitor.php — we own the only email path.
 */
final class ThrottledFailedJobNotifier
{
    public function handle(JobFailed $event): void
    {
        $signature = $this->fingerprint($event);
        $cacheKey = "failed-job-alert:{$signature}";

        // Atomic lock: Cache::add returns false if the key already exists.
        // Equivalent to `if (has) return; put(...)` but race-safe.
        if (! Cache::add($cacheKey, 1, now()->addMinutes(5))) {
            return;
        }

        Notification::send(
            app(AlertDistribution::class),
            new FailedJobMonitorNotification($event)
        );
    }

    private function fingerprint(JobFailed $event): string
    {
        return md5(implode('|', [
            $event->job->resolveName(),
            $event->exception::class,
            $event->exception->getMessage(),
        ]));
    }
}
