<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Notifiables;

/**
 * Sink notifiable for spatie/laravel-failed-job-monitor.
 *
 * The package's FailedJobNotifier unconditionally calls
 * `app(config('failed-job-monitor.notifiable'))->notify($notification)` on
 * every JobFailed event — there is NO null-check on the notifiable. Setting
 * `notifiable => null` (the previous attempt to suppress the package's
 * listener) caused `app(null)` to return the Illuminate Application
 * instance, which then crashed with `BadMethodCallException: Method
 * Illuminate\Foundation\Application::notify does not exist.`
 *
 * Pointing `notifiable` here gives the package something it CAN call
 * `notify()` on, and we silently discard the notification. Plan 05's
 * `ThrottledFailedJobNotifier` (registered on the same JobFailed event
 * in EventServiceProvider) is the real owner of failed-job alerting.
 *
 * Do NOT add any side effects in notify() — operator decided in Plan 05
 * (D-10) that ThrottledFailedJobNotifier owns dispatch end-to-end; any
 * fan-out here would re-introduce the double-send risk that T-05-06
 * documented.
 */
final class NullNotifiable
{
    public function notify(object $notification): void
    {
        // Intentionally no-op — see class docblock.
    }
}
