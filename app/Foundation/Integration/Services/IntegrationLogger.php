<?php

declare(strict_types=1);

namespace App\Foundation\Integration\Services;

use App\Foundation\Integration\Models\IntegrationEvent;
use Illuminate\Support\Facades\Context;

/**
 * Writes rows to integration_events.
 *
 * Every outbound API client (WooClient, BitrixClient, SupplierClient, etc.)
 * MUST call IntegrationLogger::log() on success AND failure. The logger:
 *   1. Auto-attaches correlation_id from Context if not explicitly passed
 *   2. Redacts sensitive headers (authorization, x-wc-webhook-signature, cookie,
 *      x-bitrix-signature, x-api-key, x-auth-token)
 *   3. Sets created_at to now() if absent
 *   4. Returns the persisted IntegrationEvent for callers that need the ID
 *
 * FOUND-05 compliance: every external call is logged here.
 * T-03-01 mitigation: sensitive headers replaced with ['***REDACTED***'] before persist.
 */
final class IntegrationLogger
{
    /** Header names (lower-cased) whose values get replaced with ['***REDACTED***']. */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'x-wc-webhook-signature',
        'cookie',
        'x-bitrix-signature',
        'x-api-key',
        'x-auth-token',
    ];

    public function log(array $data): IntegrationEvent
    {
        $defaults = [
            'correlation_id' => Context::get('correlation_id'),
            'direction' => 'outbound',
            'attempt' => 1,
            'created_at' => now(),
        ];

        if (isset($data['request_headers']) && is_array($data['request_headers'])) {
            $data['request_headers'] = $this->redactHeaders($data['request_headers']);
        }

        return IntegrationEvent::create(array_merge($defaults, $data));
    }

    /** Lower-cases every header name and masks values on the sensitive list. */
    private function redactHeaders(array $headers): array
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
