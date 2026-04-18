<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Http\Controllers;

use App\Domain\Webhooks\Events\CustomerRegistered;
use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Woo inbound webhook endpoint.
 *
 * By the time a request reaches this controller:
 *   - VerifyWooHmacSignature has confirmed HMAC match on the raw body (401 otherwise)
 *   - AttachCorrelationId has stamped Context['correlation_id'] (global middleware, Plan 03)
 *
 * Handler responsibilities:
 *   1. Persist webhook_receipts row (redact sensitive inbound headers — Gemini MEDIUM)
 *   2. Dedup on (source, delivery_id) unique index — duplicate retry returns
 *      {status: duplicate} and does NOT re-fire the event
 *   3. Dispatch domain event; listener work happens async in Phase 2+
 *   4. Respond 200 within 200ms (FOUND-07 acceptance)
 */
final class WooWebhookController
{
    public function order(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            'order',
            fn ($receipt) => OrderReceived::dispatch($receipt->id, $receipt->delivery_id)
        );
    }

    public function customer(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            'customer',
            fn ($receipt) => CustomerRegistered::dispatch($receipt->id, $receipt->delivery_id)
        );
    }

    private function handle(Request $request, string $topic, \Closure $dispatch): JsonResponse
    {
        $deliveryId = $request->header('X-WC-Webhook-Delivery-ID') ?? (string) Str::uuid();

        try {
            $receipt = WebhookReceipt::create([
                'source' => 'woo',
                'topic' => $topic,
                'delivery_id' => $deliveryId,
                // Defense-in-depth: redact sensitive inbound headers (Gemini Concern MEDIUM).
                // Mirrors IntegrationLogger's outbound redaction so inbound + outbound logs are symmetric.
                'headers' => WebhookReceipt::redactHeaders($request->headers->all()),
                'raw_body' => $request->getContent(),
                'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Duplicate (source, delivery_id) — Woo retried. Return 200 so it stops.
            if ($this->isDuplicateKeyError($e)) {
                return response()->json(['status' => 'duplicate']);
            }
            throw $e;
        }

        $dispatch($receipt);

        return response()->json(['status' => 'accepted']);
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        // MySQL SQLSTATE 23000 = integrity constraint violation (unique index)
        return $e->getCode() === '23000';
    }
}
