<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier;
use App\Domain\Pricing\Listeners\RecomputePriceListener;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Listeners\StubNewSupplierSkuListener;
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

        // Phase 2 Plan 03 (D-09 stub) — Phase 6 wires the real CreateWooProductJob listener.
        // Present here so the event doesn't pile up in failed_jobs waiting for a handler.
        NewSupplierSkuDetected::class => [
            StubNewSupplierSkuListener::class,
        ],

        // Phase 3 Plan 02 — Phase 2's supplier-side price diff triggers the
        // Phase 3 pricing recompute. Listener runs on the `default` queue
        // (not sync-woo-push — that queue is for the downstream Woo PUT
        // emitted by Phase 2 on ProductPriceChanged).
        SupplierPriceChanged::class => [
            RecomputePriceListener::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
