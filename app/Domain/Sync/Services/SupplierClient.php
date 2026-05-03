<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
use App\Domain\Sync\Exceptions\JwtRefreshFailedException;
use App\Foundation\Integration\Services\IntegrationLogger;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SYNC-01 + SYNC-02 — 21stcav.com JWT-authed catalogue client.
 *
 * JWT lifecycle (PITFALLS Pitfall 12 + Pitfall P2-B):
 *   - Token cached keyed by md5(username) so credential rotation invalidates automatically
 *   - TTL = 50 minutes (10-min safety margin on assumed 60-min server-side token TTL — A2)
 *   - On 401 from a data call: forget cached token, fetch fresh via /generate_token.php,
 *     retry the same data call ONCE. A second 401 throws JwtRefreshFailedException (D-06c).
 *
 * Every outbound HTTP call is logged to integration_events via IntegrationLogger
 * (preserves FOUND-05 invariant). The logger auto-redacts the Authorization header;
 * the password field is explicitly masked in the logged request_body.
 */
class SupplierClient
{
    // Phase 09.1 Plan 01 — `final` removed so Mockery can mock testConnection()
    // for TestIntegrationActionTest (Test 3.4). Production code does NOT subclass.
    private const DATA_ENDPOINT = '/api/index.php';
    private const TOKEN_ENDPOINT = '/generate_token.php';

    private const DEFAULT_PAGE_SIZE = 500;
    private const DEFAULT_TOKEN_TTL_MINUTES = 50; // 10-min safety margin on 60-min server TTL
    private const DEFAULT_HTTP_TIMEOUT = 30;
    private const DEFAULT_TOKEN_TIMEOUT = 10;

    public function __construct(
        private IntegrationLogger $logger,
        private CacheRepository $cache,
        private IntegrationCredentialResolver $resolver,
    ) {}

    /**
     * Phase 09.1 — credentials sourced from IntegrationCredentialResolver
     * (DB row wins; .env fallback). Replaces direct config('services.supplier.*')
     * reads. Resolver is internally cached for 60s per kind so repeated calls
     * during a single sync run do NOT hit the DB N times.
     *
     * @return array{base_url: string, username: string, password: string}
     */
    private function credentials(): array
    {
        return $this->resolver->for(IntegrationCredentialKind::SupplierApi);
    }

    /**
     * Fetch the full supplier catalogue.
     *
     * @return array<string, array{price: string, stock: int}> SKU-keyed hashmap.
     *
     * @throws JwtRefreshFailedException If authentication fails irrecoverably.
     */
    public function fetchAllProducts(): array
    {
        $out = [];
        $page = 1;

        do {
            $response = $this->authed(fn () => $this->fetchPage($page));

            $data = $response->json('data') ?? [];
            foreach ($data as $row) {
                if (empty($row['sku'])) {
                    continue;
                }
                $out[(string) $row['sku']] = [
                    'price' => (string) ($row['price'] ?? ''),
                    'stock' => (int) ($row['stock'] ?? 0),
                ];
            }

            $nextPage = $response->json('next_page');
            $hasMore = $nextPage !== null && $nextPage !== '';
            $page++;
        } while ($hasMore);

        return $out;
    }

    /**
     * Phase 6 Plan 01 — fetch the FULL decoded supplier record for a single SKU.
     *
     * Unlike fetchAllProducts() which aggressively narrows each row to
     * {price, stock}, this method returns the raw decoded first-row payload
     * so Plan 06-02 / 06-03 can see every field the supplier exposes
     * (image_url, brand, category, description, name, specs, etc).
     *
     * Routes through the same authed() JWT-refresh + IntegrationLogger path
     * as fetchPage() — so 401 → single retry → JwtRefreshFailedException on
     * the second 401 (D-06c). Every call lands in integration_events.
     *
     * @return array<string, mixed> Raw first-row of response.data (empty if none).
     *
     * @throws JwtRefreshFailedException If authentication fails irrecoverably.
     */
    public function fetchSingleProduct(string $sku): array
    {
        $response = $this->authed(fn () => $this->fetchSingle($sku));

        $data = $response->json('data') ?? [];

        // API returns `data: []` on miss — return empty array to caller.
        return $data[0] ?? [];
    }

    /**
     * Phase 6 Plan 01 — single-SKU lookup against /api/index.php.
     *
     * Called via authed() so 401 triggers a one-shot token refresh (same
     * pattern as fetchPage). NEVER filters the response row — callers see
     * every supplier field.
     *
     * @throws RequestException  On 401 (caught + retried by authed()).
     * @throws \RuntimeException On non-401 HTTP error.
     */
    private function fetchSingle(string $sku): Response
    {
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $start = microtime(true);

        $response = Http::baseUrl($this->credentials()['base_url'])
            ->withToken($this->getToken())
            ->timeout(self::DEFAULT_HTTP_TIMEOUT)
            ->retry(
                times: 2,
                sleepMilliseconds: 500,
                when: fn (\Throwable $e) => $e instanceof ConnectionException,
            )
            ->get(self::DATA_ENDPOINT, [
                'endpoint' => 'products',
                'sku' => $sku,
                'per_page' => 1,
            ]);

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $this->logger->log([
            'channel' => 'supplier',
            'operation' => 'GET /api/index.php?sku='.$sku,
            'method' => 'GET',
            'endpoint' => self::DATA_ENDPOINT,
            'request_body' => ['endpoint' => 'products', 'sku' => $sku, 'per_page' => 1],
            'request_headers' => ['authorization' => ['***REDACTED***']],
            'response_body' => $response->json() ?? [],
            'http_status' => $response->status(),
            'latency_ms' => $latencyMs,
            'status' => $response->successful() ? 'success' : 'failed',
            'correlation_id' => $correlationId,
        ]);

        if ($response->status() === 401) {
            $response->throw();
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Supplier API returned HTTP {$response->status()} for single-sku fetch {$sku}"
            );
        }

        return $response;
    }

    /**
     * Single paginated fetch. Called via authed() so 401 triggers a one-shot token refresh.
     *
     * @throws RequestException  On 401 (caught + retried by authed()).
     * @throws \RuntimeException On non-401 HTTP error.
     */
    private function fetchPage(int $page): Response
    {
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $start = microtime(true);

        $response = Http::baseUrl($this->credentials()['base_url'])
            ->withToken($this->getToken())
            ->timeout(self::DEFAULT_HTTP_TIMEOUT)
            ->retry(
                times: 2,
                sleepMilliseconds: 500,
                when: fn (\Throwable $e) => $e instanceof ConnectionException,
            )
            ->get(self::DATA_ENDPOINT, [
                'endpoint' => 'products',
                'page' => $page,
                'per_page' => self::DEFAULT_PAGE_SIZE,
            ]);

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $this->logger->log([
            'channel' => 'supplier',
            'operation' => "fetch.page.{$page}",
            'method' => 'GET',
            'endpoint' => self::DATA_ENDPOINT,
            'request_body' => ['endpoint' => 'products', 'page' => $page, 'per_page' => self::DEFAULT_PAGE_SIZE],
            'request_headers' => ['authorization' => ['***REDACTED***']],
            'response_body' => $response->json() ?? [],
            'http_status' => $response->status(),
            'latency_ms' => $latencyMs,
            'status' => $response->successful() ? 'success' : 'failed',
            'correlation_id' => $correlationId,
        ]);

        if ($response->status() === 401) {
            // Let authed() catch + refresh + retry.
            $response->throw();
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Supplier API returned HTTP {$response->status()} for page {$page}"
            );
        }

        return $response;
    }

    /**
     * Execute a closure that calls the supplier API. On 401, invalidate the cached
     * token, fetch a fresh one, and retry exactly ONCE. A second 401 throws
     * JwtRefreshFailedException (D-06c abort trigger — don't loop).
     *
     * @throws JwtRefreshFailedException
     */
    private function authed(Closure $call): Response
    {
        try {
            return $call();
        } catch (RequestException $e) {
            if ($e->response?->status() !== 401) {
                throw $e;
            }

            // D-06(c): purge cached token; single retry; second 401 = abort.
            $this->cache->forget($this->tokenCacheKey());

            try {
                return $call();
            } catch (RequestException $retry) {
                throw new JwtRefreshFailedException(
                    'Supplier API returned 401 after token refresh — credentials likely broken (D-06c).',
                    previous: $retry,
                );
            }
        }
    }

    /**
     * Return a live JWT — from cache if within TTL, else generate + cache.
     *
     * @throws JwtRefreshFailedException If token generation fails.
     */
    private function getToken(): string
    {
        return $this->cache->remember(
            $this->tokenCacheKey(),
            now()->addMinutes(self::DEFAULT_TOKEN_TTL_MINUTES),
            fn (): string => $this->generateToken(),
        );
    }

    /**
     * POST to /generate_token.php, log the call, return the JWT string.
     *
     * @throws JwtRefreshFailedException On HTTP error or missing token in response.
     */
    private function generateToken(): string
    {
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $start = microtime(true);

        $creds = $this->credentials();
        $response = Http::timeout(self::DEFAULT_TOKEN_TIMEOUT)
            ->post(
                rtrim($creds['base_url'], '/') . self::TOKEN_ENDPOINT,
                [
                    'username' => $creds['username'],
                    'password' => $creds['password'],
                ],
            );

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        $this->logger->log([
            'channel' => 'supplier',
            'operation' => 'token.generate',
            'method' => 'POST',
            'endpoint' => self::TOKEN_ENDPOINT,
            // Explicit body redaction: the logger redacts sensitive HEADER values, not body fields.
            'request_body' => [
                'username' => $creds['username'],
                'password' => '***REDACTED***',
            ],
            'response_body' => $response->successful()
                ? ['token' => '***REDACTED***', 'expires_in' => $response->json('expires_in')]
                : ($response->json() ?? ['error' => $response->body()]),
            'http_status' => $response->status(),
            'latency_ms' => $latencyMs,
            'status' => $response->successful() ? 'success' : 'failed',
            'correlation_id' => $correlationId,
        ]);

        if (! $response->successful()) {
            throw new JwtRefreshFailedException(
                "Supplier token generation failed: HTTP {$response->status()}"
            );
        }

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            throw new JwtRefreshFailedException(
                'Supplier token generation returned an empty token'
            );
        }

        return $token;
    }

    /**
     * Cache key — hashed username so credential rotation naturally invalidates
     * (Pitfall P2-B). Rotating SUPPLIER_API_USERNAME produces a fresh cache key.
     */
    private function tokenCacheKey(): string
    {
        // Phase 09.1 — username sourced from resolver (DB-row wins; env fallback).
        // Resolver throw on missing creds is caught + degraded to a stable empty key
        // so the test-environment "no creds at all" path still produces a deterministic
        // cache key (callers will still fail when they actually try to fetch a token).
        try {
            $username = (string) ($this->credentials()['username'] ?? '');
        } catch (\Throwable) {
            $username = '';
        }

        return 'supplier.jwt.' . md5($username);
    }

    /**
     * Phase 09.1 Plan 01 (D-11) — Test connection for the Supplier API.
     *
     * POSTs against /generate_token.php using current resolver-supplied
     * credentials and checks the response carries a non-empty token. Wraps
     * the entire call in a try-catch so the result is never a thrown
     * exception — TestIntegrationAction expects a structured IntegrationTestResult.
     */
    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        try {
            $creds = $this->credentials();
            $response = Http::timeout(self::DEFAULT_TOKEN_TIMEOUT)
                ->post(rtrim($creds['base_url'], '/') . self::TOKEN_ENDPOINT, [
                    'username' => $creds['username'],
                    'password' => $creds['password'],
                ]);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful() && is_string($response->json('token')) && $response->json('token') !== '') {
                return IntegrationTestResult::ok($latency);
            }

            return IntegrationTestResult::failed(
                "HTTP {$response->status()} — token missing/invalid in response",
                $latency,
            );
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }
}
