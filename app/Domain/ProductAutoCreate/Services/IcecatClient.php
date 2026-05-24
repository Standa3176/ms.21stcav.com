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
 * Icecat JSON Product Request client — product images by GTIN/EAN (then
 * Brand + ProductCode/MPN fallback).
 *
 * Endpoint (live.icecat.biz/api, GET):
 *   ?lang=EN&shopname={username}&GTIN={ean}
 *   ?lang=EN&shopname={username}&Brand={brand}&ProductCode={mpn}
 *
 * Auth (see config/services.php 'icecat'):
 *   - Open Icecat: username (shopname) only — sponsored brands.
 *   - Full Icecat: + `api-token` (product data) and `content-token` (image/
 *     asset access) HTTP HEADERS — needed for non-sponsored brands (Sony,
 *     Barco, ViewSonic, etc.) and for the image URLs to resolve.
 *
 * Response: data.Image (main: HighPic/Pic500x500/LowPic) + data.Gallery[]
 * (each: Pic/Pic500x500/LowPic, No serial, IsMain "Y"). We return ordered,
 * deduped HIGH-RES URLs. Per Icecat ToS the caller MUST download + re-host
 * the bytes (links may be IP-restricted) — that is the source-images command's
 * job (it runs server-side, so whitelist the server IP in the Icecat account).
 *
 * Pure read client. Returns [] on miss / not-configured / API error so the
 * caller degrades to other candidate sources rather than failing the run.
 */
final class IcecatClient
{
    public function __construct(
        private IntegrationCredentialResolver $resolver,
        private IntegrationLogger $logger,
    ) {}

    /**
     * Ordered, deduped high-res image URLs for a product. GTIN first, then
     * Brand+ProductCode. Empty array when nothing matches or Icecat is
     * unconfigured/unreachable.
     *
     * @return array<int, string>
     */
    public function fetchImageUrls(?string $ean, ?string $brand, ?string $mpn, int $limit = 8): array
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return [];
        }
        [$username, $apiToken, $contentToken] = $creds;

        $urls = [];

        $ean = $ean !== null ? trim($ean) : '';
        if ($ean !== '') {
            $urls = $this->request($username, $apiToken, $contentToken, ['GTIN' => $ean]);
        }

        $brand = $brand !== null ? trim($brand) : '';
        $mpn = $mpn !== null ? trim($mpn) : '';
        if ($urls === [] && $brand !== '' && $mpn !== '') {
            $urls = $this->request($username, $apiToken, $contentToken, [
                'Brand' => $brand,
                'ProductCode' => $mpn,
            ]);
        }

        return array_slice($urls, 0, max(1, $limit));
    }

    /**
     * Probe reachability + auth. A JSON response (even "product not found")
     * proves the endpoint + username are accepted; an explicit account/auth
     * error message or a 401/403 is a failure.
     */
    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        $creds = $this->credentials();
        if ($creds === null) {
            return IntegrationTestResult::failed('Icecat username not configured.', 0);
        }
        [$username, $apiToken, $contentToken] = $creds;

        try {
            $resp = Http::timeout(15)
                ->withHeaders($this->headers($apiToken, $contentToken))
                ->get($this->baseUrl(), [
                    'lang' => $this->language(),
                    'shopname' => $username,
                    // Dummy GTIN — we only care that the account is accepted.
                    'GTIN' => '00000000000000',
                ]);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($resp->status() === 401 || $resp->status() === 403) {
                return IntegrationTestResult::failed("Icecat HTTP {$resp->status()} (auth).", $latency);
            }
            if (! $resp->successful()) {
                return IntegrationTestResult::failed("Icecat HTTP {$resp->status()}.", $latency);
            }

            $json = $resp->json();
            if (! is_array($json)) {
                return IntegrationTestResult::failed('Icecat returned a non-JSON body.', $latency);
            }

            // Product genuinely present → auth definitely fine.
            if (isset($json['data']) && is_array($json['data'])) {
                return IntegrationTestResult::ok($latency);
            }

            $msg = $this->extractMessage($json);
            if ($msg !== '' && preg_match('/access|denied|unauthor|not known|invalid|blocked|forbidden/i', $msg) === 1) {
                return IntegrationTestResult::failed("Icecat: {$msg}", $latency);
            }

            // "No product found" for the dummy GTIN is the expected happy path.
            return IntegrationTestResult::ok($latency);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }

    /**
     * @return array{0:string,1:string,2:string}|null  [username, apiToken, contentToken]
     */
    private function credentials(): ?array
    {
        try {
            $c = $this->resolver->for(IntegrationCredentialKind::Icecat);
        } catch (IntegrationCredentialMissingException) {
            return null;
        }

        $username = trim((string) ($c['username'] ?? ''));
        if ($username === '') {
            return null;
        }

        return [
            $username,
            trim((string) ($c['api_token'] ?? '')),
            trim((string) ($c['content_token'] ?? '')),
        ];
    }

    /**
     * @param  array<string, string>  $identifier  GTIN, or Brand+ProductCode
     * @return array<int, string>
     */
    private function request(string $username, string $apiToken, string $contentToken, array $identifier): array
    {
        $query = array_merge([
            'lang' => $this->language(),
            'shopname' => $username,
        ], $identifier);

        $start = microtime(true);
        try {
            $resp = Http::timeout(20)
                ->withHeaders($this->headers($apiToken, $contentToken))
                ->get($this->baseUrl(), $query);
        } catch (\Throwable $e) {
            $this->log($identifier, 0, 'failed', ['exception' => $e::class, 'message' => $e->getMessage()], 0);

            return [];
        }

        $latency = (int) round((microtime(true) - $start) * 1000);

        if (! $resp->successful()) {
            $this->log($identifier, $resp->status(), 'failed', ['reason' => 'non_2xx'], $latency);

            return [];
        }

        $json = $resp->json();
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        if (! is_array($data)) {
            $this->log($identifier, $resp->status(), 'success', [
                'reason' => 'no_data',
                'msg' => Str::limit($this->extractMessage(is_array($json) ? $json : []), 200, ''),
            ], $latency);

            return [];
        }

        $urls = $this->extractUrls($data);
        $this->log($identifier, $resp->status(), 'success', ['images_found' => count($urls)], $latency);

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $data  Icecat `data` object
     * @return array<int, string>
     */
    private function extractUrls(array $data): array
    {
        $out = [];

        $main = $data['Image'] ?? null;
        if (is_array($main)) {
            $u = $this->bestUrl($main);
            if ($u !== null) {
                $out[] = $u;
            }
        }

        $gallery = $data['Gallery'] ?? [];
        if (is_array($gallery)) {
            // Primary first, then by serial No ascending (lower = higher priority).
            usort($gallery, static function ($a, $b): int {
                $aMain = (string) (($a['IsMain'] ?? '')) === 'Y' ? 0 : 1;
                $bMain = (string) (($b['IsMain'] ?? '')) === 'Y' ? 0 : 1;
                if ($aMain !== $bMain) {
                    return $aMain <=> $bMain;
                }

                return (int) ($a['No'] ?? 9999) <=> (int) ($b['No'] ?? 9999);
            });

            foreach ($gallery as $g) {
                if (! is_array($g)) {
                    continue;
                }
                $u = $this->bestUrl($g);
                if ($u !== null) {
                    $out[] = $u;
                }
            }
        }

        // Dedupe, preserve order.
        return array_values(array_unique($out));
    }

    /**
     * Prefer the highest-resolution URL available on an Image/Gallery node.
     *
     * @param  array<string, mixed>  $node
     */
    private function bestUrl(array $node): ?string
    {
        foreach (['HighPic', 'Pic', 'Pic500x500', 'LowPic'] as $key) {
            $u = $node[$key] ?? null;
            if (is_string($u) && trim($u) !== '') {
                return trim($u);
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function headers(string $apiToken, string $contentToken): array
    {
        return array_filter([
            'api-token' => $apiToken !== '' ? $apiToken : null,
            'content-token' => $contentToken !== '' ? $contentToken : null,
        ]);
    }

    /** @param array<string, mixed> $json */
    private function extractMessage(array $json): string
    {
        foreach (['msg', 'Message', 'StatusMessage'] as $key) {
            if (isset($json[$key]) && is_string($json[$key])) {
                return $json[$key];
            }
        }
        $errors = $json['ContentErrors'] ?? null;
        if (is_string($errors)) {
            return $errors;
        }

        return '';
    }

    private function baseUrl(): string
    {
        return (string) config('services.icecat.base_url', 'https://live.icecat.biz/api');
    }

    private function language(): string
    {
        return (string) config('services.icecat.language', 'EN');
    }

    /**
     * @param  array<string, string>  $identifier
     * @param  array<string, mixed>  $response
     */
    private function log(array $identifier, int $status, string $outcome, array $response, int $latencyMs): void
    {
        $this->logger->log([
            'channel' => 'icecat',
            'operation' => 'product.request',
            'method' => 'GET',
            'endpoint' => $this->baseUrl().'?'.http_build_query($identifier),
            'request_body' => $identifier,
            'response_body' => $response,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'status' => $outcome,
            'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
        ]);
    }
}
