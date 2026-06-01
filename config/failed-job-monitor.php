<?php

declare(strict_types=1);

use App\Domain\Alerting\Notifiables\NullNotifiable;
use Spatie\FailedJobMonitor\Notification;

/*
|--------------------------------------------------------------------------
| spatie/laravel-failed-job-monitor configuration
|--------------------------------------------------------------------------
|
| T-05-06 (double-send suppression): Plan 05 owns end-to-end failed-job
| alerting via App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier
| (registered on Illuminate\Queue\Events\JobFailed in EventServiceProvider).
|
| This config SUPPRESSES the spatie package's auto-listener by sending its
| notification to App\Domain\Alerting\Notifiables\NullNotifiable, whose
| notify() method is a no-op.
|
| Previously this was `notifiable => null`, but the package's
| FailedJobNotifier unconditionally calls
|   $notifiable = app(config('failed-job-monitor.notifiable'));
|   ...
|   $notifiable->notify($notification);
| With a null config value, `app(null)` returns the Illuminate Application
| instance, and Application::notify() doesn't exist → BadMethodCallException
| on every job failure. The exception cascaded into job-failure paths
| (e.g. PublishProductJob retries), hiding the original exception. Fixed
| 2026-06-01.
|
| Do NOT restore the default `\Spatie\FailedJobMonitor\Notifiable::class`
| unless you also unregister our listener — that would double-send alerts.
*/

return [

    /*
     * The notification that will be sent when a job fails. Our custom listener
     * re-uses this class so the email template + channels are consistent.
     */
    'notification' => Notification::class,

    /*
     * Package's auto-listener notifiable is a NO-OP sink — our listener
     * (ThrottledFailedJobNotifier in EventServiceProvider) owns dispatch.
     */
    'notifiable' => NullNotifiable::class,

    'notificationFilter' => null,

    /*
     * D-10: email only (Slack explicitly rejected). Our AlertDistribution
     * Notifiable enforces this at the route level too — routeNotificationForSlack
     * returns null even if this list drifts.
     */
    'channels' => ['mail'],

    'mail' => [
        // Routes resolve dynamically via AlertDistribution (D-11); leave empty here.
        'to' => [],
    ],

    'slack' => [
        'webhook_url' => null,
    ],
];
