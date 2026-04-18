<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Sync\Exceptions\RateLimitExceededException;
use App\Domain\Sync\Models\SyncDiff;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\HttpClientException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Thin wrapper around Woo REST client — Phase 1 skeleton + Phase 2 live-write extension.
 *
 * Every write method (put/post/patch/delete) routes through writeOrShadow():
 *   1. services.woo.write_enabled=false → record SyncDiff + return (Phase 1 behaviour)
 *   2. services.woo.write_enabled=true  → writeLive() with 429 backoff (Phase 2)
 *
 * Read methods (get) always hit the real client; there is no shadow-read semantics.
 *
 * Only this class writes to Woo (per ARCHITECTURE.md anti-pattern 6, SYNC-04).
 */
class WooClient
{
    public function __construct(
        private IntegrationLogger $logger,
        private ?AutomatticClient $inner = null,
    ) {}

    // ══════════════════════════════════════════════════════════════════
    // Read path — SYNC-01 (Phase 2 addition)
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET call against Woo REST. Returns the decoded payload as an array.
     *
     * Every call is logged to integration_events (success + failure) with:
     *   channel=woo, method=GET, endpoint=$endpoint, http_status, latency_ms,
     *   correlation_id (auto-threaded from Context, fallback UUID).
     *
     * @throws \RuntimeException         If $inner is null (misconfigured DI).
     * @throws HttpClientException       If Woo returns 4xx/5xx.
     */
    public function get(string $endpoint, array $query = []): array
    {
        if ($this->inner === null) {
            throw new \RuntimeException(
                'WooClient $inner not bound; resolve via the container or pass an AutomatticClient to the constructor.'
            );
        }

        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $start = microtime(true);

        try {
            $response = $this->inner->get($endpoint, $query);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $httpStatus = $this->readResponseCode() ?? 200;

            $this->logger->log([
                'channel' => 'woo',
                'operation' => "GET {$endpoint}",
                'method' => 'GET',
                'endpoint' => $endpoint,
                'request_body' => $query,
                'response_body' => $this->normaliseResponseBody($response),
                'http_status' => $httpStatus,
                'latency_ms' => $latencyMs,
                'status' => 'success',
                'correlation_id' => $correlationId,
            ]);

            return $this->normaliseResponseBody($response);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $start) * 1000);
            $httpStatus = $this->readExceptionStatus($e);

            $this->logger->log([
                'channel' => 'woo',
                'operation' => "GET {$endpoint}",
                'method' => 'GET',
                'endpoint' => $endpoint,
                'request_body' => $query,
                'response_body' => ['error' => $e->getMessage()],
                'http_status' => $httpStatus,
                'latency_ms' => $latencyMs,
                'status' => 'failed',
                'correlation_id' => $correlationId,
            ]);

            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Write path — Phase 1 shadow gate + Phase 2 live implementation
    // ══════════════════════════════════════════════════════════════════

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
        // FOUND-08: shadow-mode gate. Default false; flipping to true is a Phase 7 cutover action.
        if (! (bool) config('services.woo.write_enabled', false)) {
            return $this->recordDiff($method, $endpoint, $payload);
        }

        return $this->writeLive($method, $endpoint, $payload);
    }

    /**
     * SYNC-10: live Woo write with exponential backoff on 429 + Retry-After honouring + jitter.
     *
     * Retry schedule: 500, 1500, 4500, 13500 ms (base × 3^(attempt-1)); capped at 30s; jitter 0-500ms.
     * Retry-After header (seconds) overrides when larger than computed delay.
     *
     * After 5 failed attempts, throws RateLimitExceededException — caller treats this as a
     * single SyncError (does NOT increment consecutive-failures counter five times).
     *
     * @throws \RuntimeException              If $inner is null.
     * @throws RateLimitExceededException     After 5 retries exhausted on 429.
     * @throws HttpClientException            For non-429 Woo errors.
     */
    private function writeLive(string $method, string $endpoint, array $payload): array
    {
        if ($this->inner === null) {
            throw new \RuntimeException(
                'WooClient $inner not bound; live writes require an AutomatticClient instance.'
            );
        }

        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $attempt = 0;
        $maxAttempts = 5;
        $baseDelayMs = 500;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $start = microtime(true);

            try {
                $response = $this->dispatchWrite($method, $endpoint, $payload);
                $latencyMs = (int) round((microtime(true) - $start) * 1000);
                $httpStatus = $this->readResponseCode() ?? 200;

                $this->logger->log([
                    'channel' => 'woo',
                    'operation' => "{$method} {$endpoint}",
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'request_body' => $payload,
                    'response_body' => $this->normaliseResponseBody($response),
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'status' => 'success',
                    'correlation_id' => $correlationId,
                    'attempt' => $attempt,
                ]);

                return $this->normaliseResponseBody($response);
            } catch (HttpClientException $e) {
                $httpStatus = $this->readExceptionStatus($e);
                $latencyMs = (int) round((microtime(true) - $start) * 1000);

                $this->logger->log([
                    'channel' => 'woo',
                    'operation' => "{$method} {$endpoint}",
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'request_body' => $payload,
                    'response_body' => ['error' => $e->getMessage(), 'attempt' => $attempt],
                    'http_status' => $httpStatus,
                    'latency_ms' => $latencyMs,
                    'status' => 'failed',
                    'correlation_id' => $correlationId,
                    'attempt' => $attempt,
                ]);

                // Non-429 errors propagate immediately — only 429 triggers backoff.
                if ($httpStatus !== 429 || $attempt >= $maxAttempts) {
                    if ($httpStatus === 429 && $attempt >= $maxAttempts) {
                        throw new RateLimitExceededException(
                            "Woo 429 after {$maxAttempts} attempts: {$method} {$endpoint}",
                            429,
                            $e,
                        );
                    }

                    throw $e;
                }

                // 429 — compute delay (max of Retry-After header and exponential backoff), jitter, usleep.
                $retryAfterSeconds = $this->readRetryAfterSeconds($e);
                $computedDelayMs = $baseDelayMs * (3 ** ($attempt - 1));
                $delayMs = max($retryAfterSeconds * 1000, $computedDelayMs);
                $delayMs = min($delayMs, 30_000); // cap 30s

                $jitterMicros = random_int(0, 500_000);
                $sleepMicros = $delayMs * 1000 + $jitterMicros;
                $this->sleepMicros($sleepMicros);
            }
        }

        // Defensive — loop exits via throws; this should be unreachable.
        throw new RateLimitExceededException(
            "Woo 429 after {$maxAttempts} attempts: {$method} {$endpoint}"
        );
    }

    /**
     * Dispatch a write via the Automattic client. Handles PATCH which the client does
     * not expose natively by calling the underlying HttpClient::request() directly.
     */
    private function dispatchWrite(string $method, string $endpoint, array $payload): mixed
    {
        $method = strtoupper($method);

        return match ($method) {
            'POST' => $this->inner->post($endpoint, $payload),
            'PUT' => $this->inner->put($endpoint, $payload),
            'DELETE' => $this->inner->delete($endpoint, $payload),
            // PATCH is not exposed by Automattic\WooCommerce\Client (as of 3.1.0).
            // Route through the underlying HttpClient which does support it via request().
            'PATCH' => $this->inner->http->request($endpoint, 'PATCH', $payload),
            default => throw new \InvalidArgumentException("Unsupported HTTP method for Woo write: {$method}"),
        };
    }

    /**
     * Read the status code off the last response via $inner->http->getResponse().
     * Returns null if the client / response object isn't accessible (e.g. fresh mock).
     */
    private function readResponseCode(): ?int
    {
        if ($this->inner === null) {
            return null;
        }

        $http = $this->inner->http ?? null;
        if ($http === null) {
            return null;
        }

        try {
            if (method_exists($http, 'getResponse')) {
                $response = $http->getResponse();
                if ($response !== null && method_exists($response, 'getCode')) {
                    return (int) $response->getCode();
                }
            }
            // Fallback: some mocks stub ->response directly.
            if (property_exists($http, 'response') && is_object($http->response ?? null)) {
                $resp = $http->response;
                if (method_exists($resp, 'getCode')) {
                    return (int) $resp->getCode();
                }
                if (isset($resp->code)) {
                    return (int) $resp->code;
                }
            }
        } catch (\Throwable) {
            // Mock didn't stub getResponse — fine, we fall through.
        }

        return null;
    }

    /**
     * Extract HTTP status from a thrown exception. HttpClientException carries its
     * own Response via getResponse(); fall back to exception code.
     */
    private function readExceptionStatus(\Throwable $e): int
    {
        if ($e instanceof HttpClientException) {
            $response = $e->getResponse();
            if ($response !== null && method_exists($response, 'getCode')) {
                $code = (int) $response->getCode();
                if ($code > 0) {
                    return $code;
                }
            }
        }

        $code = (int) $e->getCode();
        return $code > 0 ? $code : 500;
    }

    /**
     * Read Retry-After header (seconds, integer). Returns 0 if absent/malformed.
     * The header may be present either on the exception's response or on
     * $inner->http->getResponse()->getHeaders().
     */
    private function readRetryAfterSeconds(\Throwable $e): int
    {
        $headerSources = [];

        if ($e instanceof HttpClientException) {
            $response = $e->getResponse();
            if ($response !== null && method_exists($response, 'getHeaders')) {
                $headerSources[] = $response->getHeaders();
            }
        }

        $httpResponse = $this->readHttpResponseObject();
        if ($httpResponse !== null && method_exists($httpResponse, 'getHeaders')) {
            $headerSources[] = $httpResponse->getHeaders();
        }

        foreach ($headerSources as $headers) {
            if (! is_array($headers)) {
                continue;
            }

            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) !== 'retry-after') {
                    continue;
                }
                if (is_array($value)) {
                    $value = $value[0] ?? null;
                }
                if (is_numeric($value)) {
                    return (int) $value;
                }
            }
        }

        return 0;
    }

    private function readHttpResponseObject(): ?object
    {
        if ($this->inner === null) {
            return null;
        }
        $http = $this->inner->http ?? null;
        if ($http === null) {
            return null;
        }

        try {
            if (method_exists($http, 'getResponse')) {
                $response = $http->getResponse();
                if (is_object($response)) {
                    return $response;
                }
            }
            if (property_exists($http, 'response') && is_object($http->response ?? null)) {
                return $http->response;
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    /**
     * Normalise an Automattic client response (stdClass or array) to a plain array
     * so downstream consumers get predictable types.
     */
    private function normaliseResponseBody(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }
        if ($response === null) {
            return [];
        }
        // Recursively cast stdClass → array via json round-trip.
        $encoded = json_encode($response);
        if ($encoded === false) {
            return [];
        }
        $decoded = json_decode($encoded, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Test seam: allows overriding usleep() in tests that assert timing without
     * actually sleeping. Default delegates to the real sleep.
     */
    protected function sleepMicros(int $micros): void
    {
        if ($micros > 0) {
            usleep($micros);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Shadow path (Phase 1) — unchanged
    // ══════════════════════════════════════════════════════════════════

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
            'correlation_id' => $correlationId,
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
