<?php

declare(strict_types=1);

namespace App\Domain\CRM\Listeners;

use App\Domain\CRM\Jobs\PushCustomerToBitrixJob;
use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 Plan 03 D-08 — first real listener on the Phase 1 CustomerRegistered event.
 *
 * Runs on the `crm-bitrix` queue. Loads the WebhookReceipt, reads the topic,
 * dispatches PushCustomerToBitrixJob. Supported topics: customer.created /
 * customer.updated.
 */
final class HandleCustomerRegistered implements ShouldQueue
{
    public string $queue = 'crm-bitrix';

    private const SUPPORTED_TOPICS = ['customer.created', 'customer.updated'];

    public function handle(CustomerRegistered $event): void
    {
        $receipt = WebhookReceipt::findOrFail($event->webhookReceiptId);
        $topic = $this->extractTopic($receipt);

        if (! in_array($topic, self::SUPPORTED_TOPICS, true)) {
            Log::warning('HandleCustomerRegistered: unsupported topic skipped', [
                'webhook_receipt_id' => $receipt->id,
                'topic' => $topic,
                'correlation_id' => $event->correlationId,
            ]);

            return;
        }

        PushCustomerToBitrixJob::dispatch($receipt->id, $topic);
    }

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

        return (string) ($receipt->topic ?? '');
    }
}
