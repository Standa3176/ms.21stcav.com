<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase 6 Plan 02 — AUTO-03 (supplier image fallback chain with Pitfall P6-A
 * supply-chain guards).
 *
 * Contract:
 *   fetch(string $primaryUrl, array $fallbackUrls = []): ?string
 *     → returns an absolute binary path of a downloaded image file, OR null
 *       if every URL falls through (caller substitutes the placeholder URL
 *       + sets requires_manual_image_review=true).
 *
 * Per-URL flow (Pitfall P6-A mitigations — ALL must be present):
 *   1. HEAD request first (allow_redirects max=3 hops) — confirm 200 + Content-Type
 *      starts with "image/". Reject on non-image Content-Type + fall through.
 *   2. GET request — download bytes with timeout.
 *   3. Size guard — reject < config('product_auto_create.min_image_bytes', 5 KB)
 *      (HTML error-page guard) OR > max_image_bytes (DoS / large-file guard).
 *   4. Write to tempnam() in sys_get_temp_dir() with .bin suffix — caller is
 *      responsible for unlink() after Processor consumes the bytes.
 *   5. IntegrationLogger entry per attempt with operation='image.fetch.attempt.N'
 *      — channel='woo-auto-create' for Filament filter scoping.
 *
 * Transport failures (timeouts, DNS, malformed TLS) are caught + logged +
 * fall through. Decode validation is OUT OF SCOPE here — the Processor owns
 * the binary-decode step so this class stays pure transport.
 *
 * This class is PURE TRANSPORT + VALIDATION. No image decoding, no resize,
 * no EXIF-strip. Those live in ProductImageProcessor.
 */
final class ProductImageFetcher
{
    public function __construct(
        private IntegrationLogger $logger,
    ) {}

    /**
     * Walk the primary URL + fallbacks in order; return the first validated tmp-file path.
     * Returns null when every URL falls through — caller uses the placeholder.
     *
     * @param  array<int, string|null>  $fallbackUrls
     */
    public function fetch(string $primaryUrl, array $fallbackUrls = []): ?string
    {
        $candidates = array_merge([$primaryUrl], $fallbackUrls);

        foreach ($candidates as $n => $url) {
            $attemptNum = $n + 1;
            if ($url === null || $url === '') {
                $this->logAttempt(
                    url: '(empty)',
                    attemptNum: $attemptNum,
                    method: 'SKIP',
                    status: 0,
                    outcome: 'failed',
                    extra: ['reason' => 'empty_url'],
                    latencyMs: 0,
                );

                continue;
            }

            $path = $this->attemptFetch((string) $url, $attemptNum);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Try one URL. HEAD (with redirect follow, max 3 hops) → Content-Type check
     * → GET → size bounds check → tempfile write. Returns the tmp path on
     * success or null on any guard failure.
     */
    private function attemptFetch(string $url, int $attemptNum): ?string
    {
        $headStart = microtime(true);
        try {
            // Pitfall P6-A — HEAD pre-flight (3-hop redirect budget)
            $headTimeout = (int) config('product_auto_create.image_fetch_timeout_seconds', 10);
            $head = Http::timeout($headTimeout)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->head($url);

            $headLatency = (int) round((microtime(true) - $headStart) * 1000);
            $contentType = (string) $head->header('Content-Type', '');

            if (! $head->successful()) {
                $this->logAttempt(
                    url: $url,
                    attemptNum: $attemptNum,
                    method: 'HEAD',
                    status: $head->status(),
                    outcome: 'failed',
                    extra: ['reason' => 'non_200', 'content_type' => $contentType],
                    latencyMs: $headLatency,
                );

                return null;
            }

            if (! str_starts_with($contentType, 'image/')) {
                $this->logAttempt(
                    url: $url,
                    attemptNum: $attemptNum,
                    method: 'HEAD',
                    status: $head->status(),
                    outcome: 'failed',
                    extra: ['reason' => 'non_image_content_type', 'content_type' => $contentType],
                    latencyMs: $headLatency,
                );

                return null;
            }

            // GET — body download with size bounds
            $getStart = microtime(true);
            $getTimeout = max(30, $headTimeout * 3);
            $get = Http::timeout($getTimeout)
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($url);

            $getLatency = (int) round((microtime(true) - $getStart) * 1000);

            if (! $get->successful()) {
                $this->logAttempt(
                    url: $url,
                    attemptNum: $attemptNum,
                    method: 'GET',
                    status: $get->status(),
                    outcome: 'failed',
                    extra: ['reason' => 'get_non_200'],
                    latencyMs: $getLatency,
                );

                return null;
            }

            $bytes = (string) $get->body();
            $size = strlen($bytes);

            $min = (int) config('product_auto_create.min_image_bytes', 5 * 1024);
            $max = (int) config('product_auto_create.max_image_bytes', 10 * 1024 * 1024);

            if ($size < $min) {
                $this->logAttempt(
                    url: $url,
                    attemptNum: $attemptNum,
                    method: 'GET',
                    status: $get->status(),
                    outcome: 'failed',
                    extra: ['reason' => 'size_below_floor', 'size' => $size, 'min' => $min],
                    latencyMs: $getLatency,
                );

                return null;
            }

            if ($size > $max) {
                $this->logAttempt(
                    url: $url,
                    attemptNum: $attemptNum,
                    method: 'GET',
                    status: $get->status(),
                    outcome: 'failed',
                    extra: ['reason' => 'size_above_ceiling', 'size' => $size, 'max' => $max],
                    latencyMs: $getLatency,
                );

                return null;
            }

            // Success — write to tempfile; caller unlinks after Processor runs.
            $tmpBase = tempnam(sys_get_temp_dir(), 'auto-image-');
            if ($tmpBase === false) {
                $this->logAttempt(
                    url: $url,
                    attemptNum: $attemptNum,
                    method: 'GET',
                    status: $get->status(),
                    outcome: 'failed',
                    extra: ['reason' => 'tempnam_failed'],
                    latencyMs: $getLatency,
                );

                return null;
            }

            $tmp = $tmpBase.'.bin';
            $written = @file_put_contents($tmp, $bytes);
            // tempnam() created $tmpBase as empty file — clean it up (we used $tmp).
            @unlink($tmpBase);

            if ($written === false) {
                @unlink($tmp);
                $this->logAttempt(
                    url: $url,
                    attemptNum: $attemptNum,
                    method: 'GET',
                    status: $get->status(),
                    outcome: 'failed',
                    extra: ['reason' => 'write_failed'],
                    latencyMs: $getLatency,
                );

                return null;
            }

            $this->logAttempt(
                url: $url,
                attemptNum: $attemptNum,
                method: 'GET',
                status: $get->status(),
                outcome: 'success',
                extra: ['size' => $size, 'content_type' => $contentType],
                latencyMs: $getLatency,
            );

            return $tmp;
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $headStart) * 1000);
            $this->logAttempt(
                url: $url,
                attemptNum: $attemptNum,
                method: 'HEAD/GET',
                status: 0,
                outcome: 'failed',
                extra: [
                    'reason' => 'exception',
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
                latencyMs: $latencyMs,
            );

            return null;
        }
    }

    private function logAttempt(
        string $url,
        int $attemptNum,
        string $method,
        int $status,
        string $outcome,
        array $extra,
        int $latencyMs,
    ): void {
        $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();

        $this->logger->log([
            'channel' => 'woo-auto-create',
            'operation' => "image.fetch.attempt.{$attemptNum}",
            'method' => $method,
            'endpoint' => $url,
            'request_body' => [],
            'response_body' => $extra,
            'http_status' => $status,
            'latency_ms' => $latencyMs,
            'status' => $outcome,
            'correlation_id' => $correlationId,
        ]);
    }
}
