<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Models\SyncDiff;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Thin wrapper around Woo REST client — Phase 1 SKELETON.
 *
 * Every write method (put/post/patch/delete) MUST:
 *   1. Check services.woo.write_enabled — if false, record SyncDiff + return
 *   2. If true (post-cutover), call the real Woo client + log to integration_events
 *
 * Phase 2 populates the Automattic\WooCommerce\Client via constructor injection
 * once WOO_URL / WOO_CONSUMER_KEY / WOO_CONSUMER_SECRET are known. Phase 1 passes null.
 *
 * Only this class writes to Woo (per ARCHITECTURE.md anti-pattern 6).
 */
final class WooClient
{
    public function __construct(
        private IntegrationLogger $logger,
        private mixed $inner = null  // Automattic\WooCommerce\Client — Phase 2 types this properly
    ) {}

    public function put(string $endpoint, array $payload): array
    {
        return $this->writeOrShadow('PUT', $endpoint, $payload);
    }

    public function post(string $endpoint, array $payload): array
    {
        return $this->writeOrShadow('POST', $endpoint, $payload);
    }

    public function patch(string $endpoint, array $payload): array
    {
        return $this->writeOrShadow('PATCH', $endpoint, $payload);
    }

    public function delete(string $endpoint, array $payload = []): array
    {
        return $this->writeOrShadow('DELETE', $endpoint, $payload);
    }

    private function writeOrShadow(string $method, string $endpoint, array $payload): array
    {
        // FOUND-08: shadow-mode gate. Default is false; flipping to true is a Phase 7 cutover action.
        if (! (bool) config('services.woo.write_enabled', false)) {
            return $this->recordDiff($method, $endpoint, $payload);
        }

        // Phase 1 ships NO real-write path — Phase 2 implements this block.
        throw new \LogicException(
            'Phase 1 WooClient does not support real writes; Phase 2 wires Automattic\\WooCommerce\\Client.'
        );
    }

    private function recordDiff(string $method, string $endpoint, array $payload): array
    {
        // Resolve a correlation_id once: prefer Context (HTTP-attached), else a fresh UUIDv4.
        // Thread the SAME id into SyncDiff AND IntegrationLogger so both rows cross-reference.
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();

        $diff = SyncDiff::create([
            'channel' => 'woo',
            'method' => $method,
            'endpoint' => $endpoint,
            'woo_id' => $this->extractWooId($endpoint),
            'payload' => $payload,
            'correlation_id' => $correlationId,
            'created_at' => now(),
            'status' => 'pending',
        ]);

        $this->logger->log([
            'channel' => 'woo',
            'operation' => "{$method} {$endpoint}",
            'endpoint' => $endpoint,
            'method' => $method,
            'request_body' => $payload,
            'status' => 'success',
            'http_status' => 0, // 0 = shadow mode (no real HTTP)
            'response_body' => ['shadow_mode' => true, 'diff_id' => $diff->id],
            'correlation_id' => $correlationId, // explicit — logger defaults to Context but tests may call direct
        ]);

        return ['shadow_mode' => true, 'diff_id' => $diff->id];
    }

    /** Extract "1234" from "products/1234" or "products/1234/variations/5" → returns "1234". */
    private function extractWooId(string $endpoint): ?string
    {
        if (preg_match('#^[a-z_\-]+/(\d+)(?:/|$)#', $endpoint, $m)) {
            return $m[1];
        }

        return null;
    }
}
