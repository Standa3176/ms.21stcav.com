<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Listeners;

use App\Domain\Products\Models\Product;
use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 5 Plan 03 Task 1 — real-time half of the hybrid sales-counter strategy.
 *
 * Subscribes to Phase 1's OrderReceived event. The event payload carries only
 * the webhook_receipt_id + delivery_id (primitives per DomainEvent T-03-05) —
 * this listener loads the receipt, parses raw_body JSON, and walks line_items.
 *
 * W1 FROZEN semantics (Plan 05-03 context annotation):
 *   "1 increment per line item per order — NOT multiplied by quantity."
 *
 *   - Line item `{sku: A, quantity: 3}` → SKU A gains +1 (not +3).
 *   - Two line items both `{sku: A, ...}` → SKU A gains +2 (degenerate Woo
 *     payload shape but semantically correct per the invariant).
 *   - Missing / null / empty sku → skip silently.
 *
 * RecacheSalesCountsJob (Task 3, currently stub) MUST use identical W1
 * aggregation to prevent drift between the real-time path and the nightly
 * authoritative recompute.
 *
 * Queue: default — counter writes are cheap and must land quickly so that
 * MarginAnalyser sees up-to-date sales numbers when it runs post-CSV ingest.
 */
class IncrementSkuSalesCount implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(OrderReceived $event): void
    {
        $receipt = WebhookReceipt::find($event->webhookReceiptId);
        if ($receipt === null) {
            // Defensive: event fired but receipt doesn't exist — log-and-move-on
            // semantics preferred over throwing (would poison the queue).
            return;
        }

        $payload = $this->decodePayload($receipt);
        $lineItems = $payload['line_items'] ?? [];

        if (! is_array($lineItems)) {
            return;
        }

        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sku = $item['sku'] ?? null;
            if (! is_string($sku) || $sku === '') {
                continue;
            }

            // W1: 1-per-line-item; DB-level atomic increment avoids read-modify-write races
            // when two orders for the same SKU land on parallel queue workers.
            Product::where('sku', $sku)->increment('last_sales_count_90d');
        }
    }

    /**
     * Webhook raw_body is stored as a JSON string on the webhook_receipts row.
     */
    private function decodePayload(WebhookReceipt $receipt): array
    {
        $raw = $receipt->raw_body;
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
