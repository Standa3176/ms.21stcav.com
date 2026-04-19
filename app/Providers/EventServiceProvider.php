<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier;
use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Listeners\DispatchMarginAnalyserJob;
use App\Domain\Competitor\Listeners\IncrementSkuSalesCount;
use App\Domain\CRM\Listeners\HandleCustomerRegistered;
use App\Domain\CRM\Listeners\HandleOrderReceived;
use App\Domain\Pricing\Listeners\RecomputePriceListener;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Listeners\StubNewSupplierSkuListener;
use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Events\OrderReceived;
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

        // Phase 4 Plan 03 D-08 — first real listeners on the Phase 1
        // webhook events. Both run on the `crm-bitrix` Horizon queue and
        // dispatch PushOrderToBitrixJob / PushCustomerToBitrixJob.
        //
        // Phase 5 Plan 03 Task 1 — IncrementSkuSalesCount is the real-time
        // half of the hybrid sales-counter strategy. Runs on the `default`
        // queue; walks raw_body.line_items and increments
        // products.last_sales_count_90d by 1 per line item (W1 semantics —
        // NOT multiplied by quantity). Identical aggregation in the nightly
        // recache job prevents drift between the two paths.
        OrderReceived::class => [
            HandleOrderReceived::class,
            IncrementSkuSalesCount::class,
        ],
        CustomerRegistered::class => [
            HandleCustomerRegistered::class,
        ],

        // Phase 5 Plan 03 Task 2 — DispatchMarginAnalyserJob debounces via
        // Cache::add per (competitor_id, sku, YYYY-MM-DD) to prevent N-per-CSV
        // analysis. On first-of-day, dispatches ComputeMarginSuggestionJob
        // which runs the 3-threshold gate and creates margin_change Suggestions.
        CompetitorPriceRecorded::class => [
            DispatchMarginAnalyserJob::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
