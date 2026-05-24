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
 * Web image-search client — finds candidate product-image URLs by query
 * (e.g. "Sony FW-50EZ20L"). Default provider: Serper.dev (Google Images).
 *
 *   POST https://google.serper.dev/images
 *   Header: X-API-KEY: {key}
 *   Body:   {"q": "...", "gl": "uk", "num": N}
 *   → {"images": [{imageUrl, imageWidth, imageHeight, source, domain, link, ...}]}
 *
 * Result handling (manufacturer-first, competitor-safe):
 *   - drop results whose domain is in config('services.image_search.blocked_domains')
 *     (e.g. your competitors) so we never pull a rival's product photography;
 *   - prefer results whose domain contains the brand token (official sites);
 *   - then prefer larger images (width × height);
 *   - return the ordered, deduped direct image URLs.
 *
 * The downstream Claude-vision validator is the second line of defence: it
 * rejects watermarks, overlay text, competitor branding and wrong products.
 *
 * Pure read client. Returns [] on miss / not-configured / API error so the
 * caller degrades gracefully.
 */
final class WebImageSearchClient
{
    public function __construct(
        private IntegrationCredentialResolver $resolver,
        private IntegrationLogger $logger,
    ) {}

    /**
     * Ordered, deduped candidate image URLs for a query. Empty when the
     * provider is unconfigured/unreachable.
     *
     * @return array<int, string>
     */
    public function searchImageUrls(string $query, int $limit = 8, ?string $brand = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return [];
        }

        $start = microtime(true);
        try {
            $resp = Http::timeout(20)
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->asJson()
                ->post($this->baseUrl().'/images', [
                    'q' => $query,
                    'gl' => (string) config('services.image_search.country', 'uk'),
                    'num' => max($limit, 10),
                ]);
        } catch (\Throwable $e) {
            $this->log($query, 0, 'failed', ['exception' => $e::class, 'message' => $e->getMessage()], 0);

            return [];
        }

        $latency = (int) round((microtime(true) - $start) * 1000);

        if (! $resp->successful()) {
            $this->log($query, $resp->status(), 'failed', [
                'reason' => 'non_2xx',
                'body' => Str::limit((string) $resp->body(), 300, ''),
            ], $latency);

            return [];
        }

        $images = $resp->json('images');
        if (! is_array($images)) {
            $this->log($query, $resp->status(), 'success', ['reason' => 'no_images'], $latency);

            return [];
        }

        $urls = $this->rankAndExtract($images, $brand);
        $this->log($query, $resp->status(), 'success', [
            'results' => count($images),
            'usable' => count($urls),
        ], $latency);

        return array_slice($urls, 0, max(1, $limit));
    }

    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return IntegrationTestResult::failed('Image-search API key not configured.', 0);
        }

        try {
            $resp = Http::timeout(15)
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->asJson()
                ->post($this->baseUrl().'/images', ['q' => 'logitech', 'num' => 1]);

            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($resp->status() === 401 || $resp->status() === 403) {
                return IntegrationTestResult::failed("Image search HTTP {$resp->status()} (bad API key).", $latency);
            }
            if (! $resp->successful()) {
                return IntegrationTestResult::failed("Image search HTTP {$resp->status()}: ".Str::limit((string) $resp->body(), 160, ''), $latency);
            }
            if (! is_array($resp->json('images'))) {
                return IntegrationTestResult::failed('Image search returned no "images" array.', $latency);
            }

            return IntegrationTestResult::ok($latency);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }

    /**
     * @param  array<int, mixed>  $images  Serper images[] payload
     * @return array<int, string>
     */
    private function rankAndExtract(array $images, ?string $brand): array
    {
        $blocked = (array) config('services.image_search.blocked_domains', []);
        $brandToken = $brand !== null ? strtolower(preg_replace('/[^a-z0-9]/i', '', $brand) ?? '') : '';

        $rows = [];
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $url = (string) ($img['imageUrl'] ?? '');
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            $domain = strtolower((string) ($img['domain'] ?? ($img['source'] ?? '')));

            // Drop competitor / blocked domains outright.
            foreach ($blocked as $b) {
                $b = strtolower(trim((string) $b));
                if ($b !== '' && str_contains($domain, $b)) {
                    continue 2;
                }
            }

            $brandMatch = $brandToken !== '' && str_contains(preg_replace('/[^a-z0-9]/i', '', $domain) ?? '', $brandToken);
            $area = (int) ($img['imageWidth'] ?? 0) * (int) ($img['imageHeight'] ?? 0);

            $rows[] = ['url' => $url, 'brand_match' => $brandMatch ? 1 : 0, 'area' => $area];
        }

        // Official (brand-in-domain) first, then largest image first.
        usort($rows, static function (array $a, array $b): int {
            if ($a['brand_match'] !== $b['brand_match']) {
                return $b['brand_match'] <=> $a['brand_match'];
            }

            return $b['area'] <=> $a['area'];
        });

        $urls = [];
        foreach ($rows as $r) {
            $urls[] = $r['url'];
        }

        return array_values(array_unique($urls));
    }

    private function apiKey(): ?string
    {
        try {
            $c = $this->resolver->for(IntegrationCredentialKind::ImageSearch);
        } catch (IntegrationCredentialMissingException) {
            return null;
        }
        $key = trim((string) ($c['api_key'] ?? ''));

        return $key !== '' ? $key : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.image_search.base_url', 'https://google.serper.dev'), '/');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function log(string $query, int $status, string $outcome, array $response, int $latencyMs): void
    {
        $this->logger->log([
            'channel' => 'image-search',
            'operation' => 'images.search',
            'method' => 'POST',
            'endpoint' => $this->baseUrl().'/images',
            'request_body' => ['q' => $query],
            'response_body' => $response,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'status' => $outcome,
            'correlation_id' => Context::get('correlation_id') ?? (string) Str::uuid(),
        ]);
    }
}
