<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
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
        private IntegrationCredentialResolver $resolver,
        private ?AutomatticClient $inner = null,
    ) {}

    /**
     * Phase 09.1 — memoised SDK keyed by base_url + consumer_key hash so
     * credential rotation transparently rebinds within ≤60s (Resolver cache TTL).
     *
     * @var array<string, AutomatticClient>
     */
    private array $sdkCache = [];

    /**
     * Resolve the Automattic SDK for the current resolver-supplied credentials.
     *
     * If $this->inner was injected (test path) it is returned verbatim — preserves
     * Mockery / fake-binding workflows that pre-stage a stubbed AutomatticClient.
     *
     * Otherwise builds a fresh SDK from the resolver payload, memoised per
     * base_url + consumer_key hash so credential rotation rebinds without
     * leaking SDK state across rotations.
     */
    private function sdk(): AutomatticClient
    {
        if ($this->inner !== null) {
            return $this->inner;
        }

        $creds = $this->resolver->for(IntegrationCredentialKind::WooRest);
        $cacheKey = md5($creds['base_url'] . '|' . $creds['consumer_key']);

        if (! isset($this->sdkCache[$cacheKey])) {
            $this->sdkCache[$cacheKey] = new AutomatticClient(
                $creds['base_url'],
                $creds['consumer_key'],
                $creds['consumer_secret'],
                [
                    'version' => 'wc/v3',
                    'timeout' => 30,
                    'verify_ssl' => app()->isProduction(),
                ],
            );
        }

        return $this->sdkCache[$cacheKey];
    }

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
        // Phase 09.1 — sdk() throws IntegrationCredentialMissingException via the
        // resolver when no DB row + no env fallback exists. Same fail-loud semantics
        // as the previous "$inner === null" guard.
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $start = microtime(true);

        try {
            $response = $this->sdk()->get($endpoint, $query);
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
        // WAF compatibility: many WP hosts block HTTP PUT to /wp-json/* at the
        // Apache layer (CWP/Imunify/mod_security defaults) while letting POST
        // through. WP-REST treats POST and PUT identically for resource-update
        // endpoints (WP_REST_Server::EDITABLE), so we route PUT through POST
        // when services.woo.use_post_for_updates is true (default).
        $method = config('services.woo.use_post_for_updates', true) ? 'POST' : 'PUT';

        return $this->writeOrShadow($method, $endpoint, $payload);
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
        // Mirrors the 260530-clv `use_post_for_updates` PUT precedent (see put() above).
        //
        // 2026-06-13 incident — without this, `brands:dedupe --delete-empty-woo-terms`
        // returns 403 from nginx for every term and the operator must hand-delete
        // every duplicate brand via wp-admin. CWP / Imunify360 / generic mod_security
        // default rules block HTTP DELETE to /wp-json/* at the Apache layer; the
        // SDK then surfaces the HTML 403 body as "JSON ERROR: Syntax error" which
        // is invisible to the operator running brands:dedupe.
        //
        // WP-REST honours the `_method=DELETE` query-string method override
        // identically to a real DELETE (it's the documented client-tunnel
        // convention), so routing through POST + `?_method=DELETE` is
        // semantically equivalent for the server while bypassing the WAF.
        //
        // Drift-prevention contract: the literal `_method=DELETE` must appear in
        // EXACTLY ONE file under app/Domain/ (this one). A grep test in
        // tests/Feature/WooClientDeleteTest.php enforces the invariant.
        if (config('services.woo.use_post_for_deletes', true)) {
            $separator = str_contains($endpoint, '?') ? '&' : '?';

            return $this->writeOrShadow('POST', $endpoint.$separator.'_method=DELETE', $payload);
        }

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
        // Phase 09.1 — sdk() throws via resolver when both DB + env are empty.
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
        $sdk = $this->sdk();

        return match ($method) {
            'POST' => $sdk->post($endpoint, $payload),
            'PUT' => $sdk->put($endpoint, $payload),
            'DELETE' => $sdk->delete($endpoint, $payload),
            // PATCH is not exposed by Automattic\WooCommerce\Client (as of 3.1.0).
            // Route through the underlying HttpClient which does support it via request().
            'PATCH' => $sdk->http->request($endpoint, 'PATCH', $payload),
            default => throw new \InvalidArgumentException("Unsupported HTTP method for Woo write: {$method}"),
        };
    }

    /**
     * Read the status code off the last response via $inner->http->getResponse().
     * Returns null if the client / response object isn't accessible (e.g. fresh mock).
     */
    private function readResponseCode(): ?int
    {
        // Phase 09.1 — read via sdk() which auto-resolves through resolver
        // (or returns the test-injected $inner when set).
        try {
            $sdk = $this->sdk();
        } catch (\Throwable) {
            return null;
        }

        $http = $sdk->http ?? null;
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
        try {
            $sdk = $this->sdk();
        } catch (\Throwable) {
            return null;
        }
        $http = $sdk->http ?? null;
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

    /**
     * Phase 09.1 Plan 01 (D-11) — Test connection for the WooCommerce REST API.
     *
     * Performs a single GET /products?per_page=1 against the resolver-supplied
     * base_url + consumer_key + consumer_secret. Any successful HTTP call is
     * a green tick — Woo returns an array on success, the SDK throws
     * HttpClientException on 4xx/5xx.
     */
    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        try {
            $response = $this->sdk()->get('products', ['per_page' => 1]);
            $latency = (int) round((microtime(true) - $start) * 1000);

            // Any non-throwing response is reachability-OK.
            if (is_array($response) || is_object($response)) {
                return IntegrationTestResult::ok($latency);
            }

            return IntegrationTestResult::failed('Empty response from /products', $latency);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }
}
