<?php

declare(strict_types=1);

namespace App\Domain\CRM\Listeners;

use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 Plan 03 D-08 — first real listener on the Phase 1 OrderReceived event.
 *
 * Runs on the `crm-bitrix` Horizon queue (Phase 1 FOUND-09). The listener's
 * only job is to (a) load the WebhookReceipt, (b) read the Woo topic from the
 * persisted headers, (c) dispatch PushOrderToBitrixJob with an initial
 * updateMissRetries=0 counter.
 *
 * Rejected topics (anything other than order.created / order.updated) log a
 * warning and return — we explicitly do NOT handle order.deleted (D-08
 * scope decision; cancellations flow via order.updated status map).
 */
final class HandleOrderReceived implements ShouldQueue
{
    public string $queue = 'crm-bitrix';

    private const SUPPORTED_TOPICS = ['order.created', 'order.updated'];

    public function handle(OrderReceived $event): void
    {
        $receipt = WebhookReceipt::findOrFail($event->webhookReceiptId);
        $topic = $this->extractTopic($receipt);

        if (! in_array($topic, self::SUPPORTED_TOPICS, true)) {
            Log::warning('HandleOrderReceived: unsupported topic skipped', [
                'webhook_receipt_id' => $receipt->id,
                'topic' => $topic,
                'correlation_id' => $event->correlationId,
            ]);

            return;
        }

        PushOrderToBitrixJob::dispatch($receipt->id, $topic, 0);
    }

    /** Webhook headers persisted as JSON — values can be a list or scalar. */
    private function extractTopic(WebhookReceipt $receipt): string
    {
        $headers = $receipt->headers;
        if (! is_array($headers)) {
            $headers = (array) json_decode((string) $headers, true);
        }

        foreach (['x-wc-webhook-topic', 'X-WC-Webhook-Topic'] as $key) {
            if (! array_key_exists($key, $headers)) {
                continue;
            }
            $value = $headers[$key];
            if (is_array($value)) {
                return (string) ($value[0] ?? '');
            }

            return (string) $value;
        }

        // Fallback — the Woo topic column may be populated directly on the receipt.
        return (string) ($receipt->topic ?? '');
    }
}
