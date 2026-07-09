<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Integrations\Enums\IntegrationCredentialKind;
use App\Domain\Integrations\Exceptions\IntegrationCredentialMissingException;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Integrations\Services\IntegrationTestResult;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Quick task 260607-hxa — EAN-search.org reverse MPN → GTIN lookup client.
 *
 * Default GTIN backfill provider for products:backfill-merchant-feed (replaces
 * IcecatClient in that role; Icecat stays the image-lookup source).
 *
 * Endpoint (api.ean-search.org/api, GET):
 *   ?token=X&op=barcode-search&format=json&search=<urlencoded query>&language=en
 *
 * Response: JSON array of objects `[{"ean":"5033588057222","name":"…","category":"…"}]`
 * OR empty array `[]` on no match. Some error responses return an object with
 * an `error` key (invalid token, malformed query, etc.) — treat both as
 * silent no-match (return null, no throw).
 *
 * Brand-match logic (the API has no native brand filter): if a brand string
 * is supplied, pick the first row whose `name` field contains the brand
 * (case-insensitive). If no row matches or brand is null/empty, take the
 * first row.
 *
 * Mirrors IcecatClient's silent-degrade-to-null pattern. Every failure mode
 * returns null (not a throw) so BackfillMerchantFeedCommand keeps moving
 * through the candidate SKU list.
 *
 * Token redaction: log() helper replaces request_body.token with '***' before
 * passing to IntegrationLogger so live tokens never land in integration_events
 * (T-260607hxa-01 mitigation).
 */
class EanSearchClient
{
    public function __construct(
        private IntegrationCredentialResolver $resolver,
        private IntegrationLogger $logger,
    ) {}

    /**
     * Reverse lookup: brand+MPN → first matching GTIN string.
     *
     * Returns the raw EAN string from the API — the caller is responsible
     * for piping it through App\Console\Concerns\NormalisesEan::normaliseEan
     * before persisting (placeholders like "N/A", "0" can appear).
     *
     * Returns null when:
     *   - MPN is blank (no HTTP call issued — API requires non-empty `search`).
     *   - Token not configured (credentials() returns null).
     *   - HTTP transport throws / non-2xx response.
     *   - Response is empty array OR error-shaped object.
     *   - No row has a non-empty `ean` field.
     */
    public function lookupGtinByMpn(?string $brand, ?string $mpn): ?string
    {
        $brand = $brand !== null ? trim($brand) : '';
        $mpn = $mpn !== null ? trim($mpn) : '';
        if ($mpn === '') {
            return null;
        }

        $ean = $this->queryBarcode($brand, $mpn);

        // Region-localized SKUs (HP '#ABU' UK, '#ABB', '#AC3', '#UUZ', …) — EAN
        // databases list the GTIN under the BASE part number, not the localized
        // code. Retry ONCE with the suffix stripped (everything before the FIRST
        // '#'). Only '#' is stripped; '/' and spaces are real part-number chars
        // (e.g. CONVBDC/SDI/HDMI12G) and are left intact.
        if ($ean === null && str_contains($mpn, '#')) {
            $base = trim((string) strstr($mpn, '#', true));
            if ($base !== '' && $base !== $mpn) {
                $ean = $this->queryBarcode($brand, $base);
            }
        }

        return $ean;
    }

    /**
     * One EAN-search barcode-search query for a single search term. Returns the
     * first valid GTIN (brand-matched where possible) or null. Logging happens
     * here so every attempt — full term AND base retry — lands in
     * integration_events.
     */
    private function queryBarcode(string $brand, string $search): ?string
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return null;
        }

        $query = [
            'token' => $creds['token'],
            'op' => 'barcode-search',
            'format' => 'json',
            'search' => $search,
            'language' => 'en',
        ];

        $start = microtime(true);
        try {
            $resp = Http::timeout(10)->retry(2, 250)->get($this->baseUrl(), $query);
        } catch (\Throwable $e) {
            $this->log($query, 0, 'failed', ['exception' => $e::class, 'message' => $e->getMessage()], 0);

            return null;
        }

        $latency = (int) round((microtime(true) - $start) * 1000);

        if (! $resp->successful()) {
            $this->log($query, $resp->status(), 'failed', [
                'reason' => 'non_2xx',
                'body' => Str::limit((string) $resp->body(), 400, ''),
            ], $latency);

            return null;
        }

        $json = $resp->json();
        if (! is_array($json)) {
            $this->log($query, $resp->status(), 'success', ['reason' => 'non_array_response'], $latency);

            return null;
        }

        // EAN-search error-object shape: {"error": "..."} (not a list of rows).
        if (isset($json['error'])) {
            $this->log($query, $resp->status(), 'failed', [
                'reason' => 'api_error',
                'error' => Str::limit((string) $json['error'], 200, ''),
            ], $latency);

            return null;
        }

        if ($json === []) {
            $this->log($query, $resp->status(), 'success', ['reason' => 'no_match'], $latency);

            return null;
        }

        // Brand-match: pick the first row whose `name` contains the brand
        // (case-insensitive). Otherwise fall through to the first row.
        $picked = null;
        if ($brand !== '') {
            foreach ($json as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if ($name !== '' && mb_stripos($name, $brand) !== false) {
                    $picked = $row;
                    break;
                }
            }
        }
        if ($picked === null) {
            $first = $json[0] ?? null;
            if (! is_array($first)) {
                $this->log($query, $resp->status(), 'success', ['reason' => 'malformed_first_row'], $latency);

                return null;
            }
            $picked = $first;
        }

        $ean = trim((string) ($picked['ean'] ?? ''));
        if ($ean === '') {
            $this->log($query, $resp->status(), 'success', ['reason' => 'empty_ean_field'], $latency);

            return null;
        }

        $this->log($query, $resp->status(), 'success', ['reason' => 'match', 'ean' => $ean], $latency);

        return $ean;
    }

    /**
     * Probe reachability + auth. 200 + JSON body proves the endpoint + token
     * are accepted (404 / 401 / 403 / explicit error object → failed).
     */
    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        $creds = $this->credentials();
        if ($creds === null) {
            return IntegrationTestResult::failed('EAN-search.org token not configured.', 0);
        }

        try {
            $resp = Http::timeout(10)->get($this->baseUrl(), [
                'token' => $creds['token'],
                'op' => 'barcode-search',
                'format' => 'json',
                'search' => 'Sony FW-50EZ20L',
                'language' => 'en',
            ]);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($resp->status() === 401 || $resp->status() === 403) {
                return IntegrationTestResult::failed("EAN-search.org auth: HTTP {$resp->status()}", $latency);
            }

            $json = $resp->json();
            if (is_array($json) && isset($json['error'])) {
                return IntegrationTestResult::failed(
                    'EAN-search.org auth: '.Str::limit((string) $json['error'], 200, ''),
                    $latency,
                );
            }

            if ($resp->successful() && (is_array($json) || $json === null)) {
                return IntegrationTestResult::ok($latency);
            }

            return IntegrationTestResult::failed(
                "EAN-search.org HTTP {$resp->status()}: ".Str::limit((string) $resp->body(), 200, ''),
                $latency,
            );
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }

    /**
     * `protected` so test doubles can override via anonymous subclass.
     *
     * @return array{token:string}|null
     */
    protected function credentials(): ?array
    {
        try {
            $c = $this->resolver->for(IntegrationCredentialKind::EanSearch);
        } catch (IntegrationCredentialMissingException) {
            return null;
        }

        $token = trim((string) ($c['token'] ?? ''));
        if ($token === '') {
            return null;
        }

        return ['token' => $token];
    }

    private function baseUrl(): string
    {
        return (string) config('services.ean_search.base_url', 'https://api.ean-search.org/api');
    }

    /**
     * Token redaction (T-260607hxa-01): live token is replaced with '***'
     * before passing the request payload to IntegrationLogger so it never
     * lands in integration_events.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    private function log(array $request, int $status, string $outcome, array $response, int $latencyMs): void
    {
        // Defensive deep-copy + redact — never mutate the caller's array,
        // and never let a future refactor accidentally leak the token.
        $redacted = $request;
        if (isset($redacted['token'])) {
            $redacted['token'] = '***';
        }

        $this->logger->log([
            'channel' => 'ean_search',
            'operation' => 'barcode_search.lookup',
            'method' => 'GET',
            'endpoint' => $this->baseUrl(),
            'request_body' => $redacted,
            'response_body' => $response,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'status' => $outcome,
            'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
        ]);
    }
}
