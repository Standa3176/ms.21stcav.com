<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

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
final class SupplierClient
{
    private const DATA_ENDPOINT = '/api/index.php';
    private const TOKEN_ENDPOINT = '/generate_token.php';

    private const DEFAULT_PAGE_SIZE = 500;
    private const DEFAULT_TOKEN_TTL_MINUTES = 50; // 10-min safety margin on 60-min server TTL
    private const DEFAULT_HTTP_TIMEOUT = 30;
    private const DEFAULT_TOKEN_TIMEOUT = 10;

    public function __construct(
        private IntegrationLogger $logger,
        private CacheRepository $cache,
    ) {}

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
     * Single paginated fetch. Called via authed() so 401 triggers a one-shot token refresh.
     *
     * @throws RequestException  On 401 (caught + retried by authed()).
     * @throws \RuntimeException On non-401 HTTP error.
     */
    private function fetchPage(int $page): Response
    {
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
        $start = microtime(true);

        $response = Http::baseUrl((string) config('services.supplier.url'))
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

        $response = Http::timeout(self::DEFAULT_TOKEN_TIMEOUT)
            ->post(
                rtrim((string) config('services.supplier.url'), '/') . self::TOKEN_ENDPOINT,
                [
                    'username' => (string) config('services.supplier.username'),
                    'password' => (string) config('services.supplier.password'),
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
                'username' => (string) config('services.supplier.username'),
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
        $username = (string) config('services.supplier.username', '');
        return 'supplier.jwt.' . md5($username);
    }
}
