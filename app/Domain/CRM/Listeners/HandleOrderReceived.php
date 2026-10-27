<?php

declare(strict_types=1);

namespace App\Domain\CRM\Listeners;

use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use App\Foundation\Integration\Services\IntegrationLogger;
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

        if (! $this->sourceAllowed($receipt)) {
            return;
        }

        PushOrderToBitrixJob::dispatch($receipt->id, $topic, 0);
    }

    /**
     * 12.1 D-14 source allowlist. Rationale: 3 stray WebhookReceipt rows in the
     * week of 2026-10-26 came from an unidentified source while live Woo has no
     * webhook to the ops app; clone shops (keep./sonny.) share the code and
     * their order ids collide with live ids, so a clone order could otherwise be
     * adopted onto / created as a live deal. Receipts with no source header
     * (backfill) are allowed.
     */
    private function sourceAllowed(WebhookReceipt $receipt): bool
    {
        $allowed = [];
        foreach ((array) config('services.woo.allowed_webhook_sources', []) as $configured) {
            $host = $this->normaliseHost((string) $configured);
            if ($host !== '') {
                $allowed[] = $host;
            }
        }

        if ($allowed === []) {
            Log::warning('HandleOrderReceived: services.woo.allowed_webhook_sources is empty — source guard disabled', [
                'webhook_receipt_id' => $receipt->id,
            ]);

            return true;
        }

        $headers = $receipt->headers;
        if (! is_array($headers)) {
            $headers = (array) json_decode((string) $headers, true);
        }

        $raw = null;
        foreach (['x-wc-webhook-source', 'X-WC-Webhook-Source'] as $key) {
            if (array_key_exists($key, $headers)) {
                $value = $headers[$key];
                $raw = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                break;
            }
        }

        if ($raw === null || trim($raw) === '') {
            return true;
        }

        $host = $this->normaliseHost($raw);
        if (in_array($host, $allowed, true)) {
            return true;
        }

        Log::warning('HandleOrderReceived: foreign webhook source dropped', [
            'webhook_receipt_id' => $receipt->id,
            'source_host' => $host,
            'correlation_id' => $receipt->correlation_id,
        ]);

        app(IntegrationLogger::class)->log([
            'channel' => 'woo',
            'direction' => 'inbound',
            'method' => 'POST',
            'operation' => 'webhook.order.foreign_source',
            'endpoint' => '/webhooks/woo/order',
            'request_body' => ['webhook_receipt_id' => $receipt->id, 'source_host' => $host],
            'http_status' => 200,
            'correlation_id' => $receipt->correlation_id,
            'status' => 'failed',
        ]);

        return false;
    }

    private function normaliseHost(string $url): string
    {
        $url = trim($url);
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);
        $host = mb_strtolower(is_string($host) ? $host : '');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
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
