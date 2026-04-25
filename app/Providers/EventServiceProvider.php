<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Agents\Events\AgentRunFailed;
use App\Domain\Agents\Listeners\NotifyOnAgentRunFailed;
use App\Domain\Agents\Listeners\NotifyOnGuardrailBlocked;
use App\Domain\Agents\Listeners\NotifyOnMonthlyBudgetExceeded;
use App\Domain\Alerting\Listeners\ThrottledFailedJobNotifier;
use App\Domain\Competitor\Events\CompetitorPriceRecorded;
use App\Domain\Competitor\Listeners\DispatchMarginAnalyserJob;
use App\Domain\Competitor\Listeners\IncrementSkuSalesCount;
use App\Domain\CRM\Listeners\HandleCustomerRegistered;
use App\Domain\CRM\Listeners\HandleOrderReceived;
use App\Domain\Pricing\Listeners\RecomputePriceListener;
use App\Domain\ProductAutoCreate\Listeners\ApplyPinsDuringSync;
use App\Domain\ProductAutoCreate\Listeners\HandleNewSupplierSku;
use App\Domain\ProductAutoCreate\Listeners\RecomputeCompletenessOnSupplierChange;
use App\Domain\Sync\Events\NewSupplierSkuDetected;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Events\SupplierStockChanged;
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

        // Phase 6 Plan 03 — real AUTO-01 listener (replaces Phase 2
        // StubNewSupplierSkuListener). Skip-rule gate (D-04) + dispatches
        // CreateWooProductJob on every unfiltered event.
        NewSupplierSkuDetected::class => [
            HandleNewSupplierSku::class,
        ],

        // Phase 3 Plan 02 — Phase 2's supplier-side price diff triggers the
        // Phase 3 pricing recompute. Listener runs on the `default` queue
        // (not sync-woo-push — that queue is for the downstream Woo PUT
        // emitted by Phase 2 on ProductPriceChanged).
        //
        // Phase 6 Plan 03 (A3 FINDING mitigation) — the listener strategy
        // replaces the Eloquent-observer approach because Phase 2's
        // forceFill + saveQuietly path suppresses both saving + saved events.
        // RecomputeCompletenessOnSupplierChange subscribes to all 3 supplier
        // events via named handler methods.
        //
        // Phase 6 Plan 05 (D-11 pin enforcement) — ApplyPinsDuringSync subscribes
        // to the SAME 3 events to issue revert PUTs for any pinned field whose
        // value would otherwise be overwritten by Phase 2's supplier-sync write.
        // Runs AFTER the Phase 2 SyncChunkJob has already written to Woo (events
        // are ShouldDispatchAfterCommit); revert window is milliseconds. Phase 2
        // `SyncChunkJob` is NEVER modified (D-11 mandate).
        SupplierPriceChanged::class => [
            RecomputePriceListener::class,
            RecomputeCompletenessOnSupplierChange::class.'@handlePriceChanged',
            ApplyPinsDuringSync::class.'@handlePriceChanged',
        ],
        SupplierStockChanged::class => [
            RecomputeCompletenessOnSupplierChange::class.'@handleStockChanged',
            ApplyPinsDuringSync::class.'@handleStockChanged',
        ],
        SupplierSkuMissing::class => [
            RecomputeCompletenessOnSupplierChange::class.'@handleSkuMissing',
            ApplyPinsDuringSync::class.'@handleSkuMissing',
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
        // Phase 9 Plan 04 Task 3 — UpdateCustomerGroupOnUserRoleChange runs
        // alongside Phase 4's HandleCustomerRegistered (separate concerns):
        // Phase 4 pushes the customer to Bitrix CRM, Phase 9 denormalises
        // the Woo role -> users.customer_group_id for trade-pricing
        // resolution. UPDATE-ONLY per B-04 — the listener never creates
        // User rows from webhook payloads (cold-start is the explicit job
        // of `b2b:backfill-customer-groups` in Plan 09-06).
        CustomerRegistered::class => [
            HandleCustomerRegistered::class,
            \App\Domain\TradePricing\Listeners\UpdateCustomerGroupOnUserRoleChange::class,
        ],

        // Phase 5 Plan 03 Task 2 — DispatchMarginAnalyserJob debounces via
        // Cache::add per (competitor_id, sku, YYYY-MM-DD) to prevent N-per-CSV
        // analysis. On first-of-day, dispatches ComputeMarginSuggestionJob
        // which runs the 3-threshold gate and creates margin_change Suggestions.
        CompetitorPriceRecorded::class => [
            DispatchMarginAnalyserJob::class,
        ],

        // Phase 8 Plan 05 Task 4 (BLOCKER 2) — AlertRecipient notifications
        // for the AgentRunFailed event. Three listeners:
        //   - NotifyOnMonthlyBudgetExceeded: filters status=monthly_budget_blocked
        //     + first-of-month dedup
        //   - NotifyOnGuardrailBlocked: filters status=guardrail_blocked +
        //     first-of-day-per-guardrail dedup
        //   - NotifyOnAgentRunFailed: catches everything ELSE (failed +
        //     budget_exceeded) with 5-min dedup; defers to the dedicated
        //     listeners above for the two specific kinds (no double-notify).
        // Order: dedicated listeners first (early-exit on non-match), generic
        // last. Each enforces its own dedup so order doesn't change semantics.
        AgentRunFailed::class => [
            NotifyOnMonthlyBudgetExceeded::class,
            NotifyOnGuardrailBlocked::class,
            NotifyOnAgentRunFailed::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
