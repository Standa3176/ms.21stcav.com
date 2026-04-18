<?php

/*
|--------------------------------------------------------------------------
| spatie/laravel-failed-job-monitor configuration
|--------------------------------------------------------------------------
|
| T-05-06 (double-send suppression): Plan 05 owns end-to-end failed-job
| alerting via App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier
| (registered on Illuminate\Queue\Events\JobFailed in EventServiceProvider).
|
| This config SUPPRESSES the spatie package's auto-listener by setting
| `notifiable => null`. With a null notifiable, the package's built-in
| JobFailed listener short-circuits before notifying, leaving our custom
| ThrottledFailedJobNotifier as the only email path.
|
| Do NOT restore the default `\Spatie\FailedJobMonitor\Notifiable::class`
| unless you also unregister our listener — that would double-send alerts.
*/

return [

    /*
     * The notification that will be sent when a job fails. Our custom listener
     * re-uses this class so the email template + channels are consistent.
     */
    'notification' => \Spatie\FailedJobMonitor\Notification::class,

    /*
     * Package's auto-listener notifiable is DISABLED — our listener owns dispatch.
     */
    'notifiable' => null,

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
