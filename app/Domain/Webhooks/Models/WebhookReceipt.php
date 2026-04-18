<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-friendly inbound webhook receipt row.
 *
 * Populated exclusively by WooWebhookController::handle — HMAC signature is already
 * verified by VerifyWooHmacSignature middleware before we reach insert. The unique
 * (source, delivery_id) index is our idempotency gate: Woo retries of the same
 * X-WC-Webhook-Delivery-ID hit a 23000 violation and we short-circuit to 200.
 */
class WebhookReceipt extends Model
{
    /** Sensitive header names (lower-cased) — value replaced with ['***REDACTED***'] before persist.
     *  Mirrors IntegrationLogger::SENSITIVE_HEADERS for inbound parity (Gemini Concern MEDIUM).
     *  X-WC-Webhook-Signature is HMAC output (not a raw secret) but redacted defensively in case
     *  Woo or a misbehaving proxy ever forwards an Authorization / Cookie / X-Api-Key header.
     */
    public const SENSITIVE_HEADERS = [
        'authorization',
        'x-wc-webhook-signature',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
        'x-session-token',
    ];

    protected $fillable = [
        'source',
        'topic',
        'delivery_id',
        'headers',
        'raw_body',
        'correlation_id',
        'received_at',
        'processed_at',
        'status',
        'error_message',
    ];

    protected $casts = [
        'headers' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Redact sensitive header values before persisting to webhook_receipts.headers.
     * Mirrors IntegrationLogger::redactHeaders() for inbound defense-in-depth.
     * Caller (WooWebhookController) MUST invoke this on $request->headers->all() before insert.
     */
    public static function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            $redacted[$name] = in_array($lower, self::SENSITIVE_HEADERS, true)
                ? ['***REDACTED***']
                : $value;
        }

        return $redacted;
    }
}
