<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Listeners;

use App\Domain\Products\Events\ProductFieldsChangedEvent;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooProductWriter;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Quick task 260611-s2d — event-driven MS→Woo PUT for
 * stock_quantity / buy_price / sell_price / category_id.
 *
 * Queue: sync-woo-push (FOUND-09; sync-woo-push-supervisor caps at ≤3
 * processes for Woo's ~100 req/min headroom — tries=5 in the supervisor,
 * but the listener pins tries=4 LOCALLY so the explicit retry budget
 * surfaces in code review).
 *
 * Fires only when cutover.event_driven_push_enabled=true (gated at the
 * ProductObserver dispatch boundary). When the flag is OFF the event
 * never dispatches; the listener stays cold.
 *
 * Outcomes:
 *   - status='pushed'         → return (audit logged, Horizon job success).
 *   - status='woo_not_found'  → return (audit logged, NO retry — Woo product
 *                                deleted between event + handle is NOT a
 *                                retryable condition).
 *   - status='error'          → throw RuntimeException (Horizon retries
 *                                with $tries=4 budget — 1 attempt + 3
 *                                retries — until the supervisor failed_jobs
 *                                row appears).
 *   - Product::find returns null → return silently. The Product was
 *                                soft-deleted between dispatch and handle
 *                                (single-digit-second window). Audit is
 *                                skipped because the event never reached
 *                                the writer; the 23:00 auto-sync backstop
 *                                closes any persistent drift.
 *
 * Echo-loop note: WooWebhookController has no Product writes today
 * (260611-s2d Task 1 probe — grep clean). If that changes, gate dispatch
 * on `Context::get('source') !== 'woo-webhook'` at the observer boundary
 * to prevent listener-driven PUTs from reflecting Woo-webhook-driven
 * writes back to Woo (incident-class echo loop).
 */
final class PushProductFieldsToWoo implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Queue name. 260719-wth — moved to the dedicated single-worker 'woo-writes'
     * queue (the queue the 260611-s2d note anticipated but that didn't exist
     * yet; this task creates the woo-writes Horizon supervisor). Keeps auto-create
     * field pushes off the shared sync-woo-push pool.
     */
    public string $queue = 'woo-writes';

    /**
     * Retry budget: 1 attempt + 3 retries. Pinned locally (not deferred
     * to sync-woo-push-supervisor's tries=5) so the listener's explicit
     * budget is visible in code review.
     */
    public int $tries = 4;

    public function __construct(private readonly WooProductWriter $writer) {}

    public function handle(ProductFieldsChangedEvent $event): void
    {
        $product = Product::find($event->productId);
        if ($product === null) {
            // Soft-deleted between dispatch + handle (single-digit-second
            // window). No audit / no error / no retry — the 23:00 auto-sync
            // backstop closes any persistent drift.
            return;
        }

        $result = $this->writer->putProductFields(
            $product,
            $event->changedFields,
            $event->correlationId,
        );

        app(Auditor::class)->record('events.product_pushed', [
            'product_id' => $event->productId,
            'sku' => $event->sku,
            'changed_fields' => $event->changedFields,
            'result' => $result['status'],
            'fields_pushed' => $result['fields_pushed'] ?? [],
            'http_status' => $result['http_status'] ?? null,
            'reason' => $result['reason'] ?? null,
        ]);

        if ($result['status'] === 'woo_not_found') {
            // No retry — Woo product gone is terminal, not transient.
            return;
        }

        if ($result['status'] === 'error') {
            throw new \RuntimeException('Woo PUT failed: '.($result['reason'] ?? '?'));
        }

        // status === 'pushed' — return normally; Horizon marks the job
        // succeeded.
    }
}
