<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobFailed;

/**
 * Laravel 12 doesn't auto-generate EventServiceProvider — it's created here
 * so Plan 05 has a canonical place to map Queue\Events\JobFailed → our
 * custom ThrottledFailedJobNotifier (D-13 5-minute dedup).
 *
 * T-05-06 (no double-send): spatie/laravel-failed-job-monitor's built-in
 * auto-listener is disabled via `'notifiable' => null` in config/failed-job-monitor.php
 * so only the listener registered below dispatches mail.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        JobFailed::class => [
            ThrottledFailedJobNotifier::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
