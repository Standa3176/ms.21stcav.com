<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for WordPress REST API endpoints (`/wp-json/wp/v2/...`).
 *
 * Distinct from WooClient — the WC consumer key/secret only authenticates
 * `/wc/v3/*` endpoints, NOT `/wp/v2/*`. To write WordPress taxonomy terms
 * and post-type taxonomy assignments (e.g. the `product_brand` taxonomy
 * that drives meetingstore.co.uk's clickable Brand: link), we need a
 * WordPress Application Password (Basic Auth username:app_password).
 *
 * Operator creates the app password in WP Admin → Profile → Application
 * Passwords; value goes into .env as WP_REST_USERNAME + WP_REST_APP_PASSWORD
 * (password MUST be double-quoted, has spaces).
 *
 * Responses come back as plain arrays (json decoded). Errors throw
 * \RuntimeException with the HTTP status + truncated body so callers can
 * try/catch without parsing exception classes — keeps the surface small
 * for the one taxonomy use-case this currently exists for.
 */
// Not `final` so the feature suite can bind a subclass stub via
// app()->instance (mirrors the WooClient read-only-guard stub in
// ReconcileWooMaintenanceCommandTest — writes throw). No behaviour change.
class WpRestClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $username,
        private readonly ?string $appPassword,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->decode($this->request('GET', $path, query: $query));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->decode($this->request('POST', $path, body: $body));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
     */
    public function put(string $path, array $body): array
    {
        return $this->decode($this->request('PUT', $path, body: $body));
    }

    public function delete(string $path): bool
    {
        return $this->request('DELETE', $path)->successful();
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $req = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();

        if ($this->username !== null && $this->appPassword !== null) {
            $req = $req->withBasicAuth($this->username, $this->appPassword);
        }

        if ($query !== []) {
            $req = $req->withQueryParameters($query);
        }

        $response = match ($method) {
            'GET' => $req->get($url),
            'DELETE' => $req->delete($url),
            'POST' => $req->post($url, $body ?? []),
            'PUT' => $req->put($url, $body ?? []),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };

        if ($response->failed()) {
            Log::warning('wp_rest.request_failed', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body_preview' => mb_substr((string) $response->body(), 0, 300),
            ]);
            throw new \RuntimeException(sprintf(
                'WP REST %s %s failed (HTTP %d): %s',
                $method,
                $path,
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }

        return $response;
    }

    /** @return array<int|string, mixed> */
    private function decode(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
