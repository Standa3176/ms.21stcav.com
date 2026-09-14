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
 * Brand + ProductCode/MPN fallback), and a GTIN lookup helper for the
 * reverse direction (brand+MPN → GTIN, used by products:backfill-merchant-feed).
 *
 * Endpoint (live.icecat.biz/api, GET):
 *   ?lang=EN&shopname={username}&app_key={appKey}&GTIN={ean}
 *   ?lang=EN&shopname={username}&app_key={appKey}&Brand={brand}&ProductCode={mpn}
 *
 * Auth (see config/services.php 'icecat'):
 *   - Open Icecat: username (shopname) only — sponsored brands.
 *   - Full Icecat: + an `app_key` (QUERY param) — found on the Icecat "My
 *     Profile" page; this is what unlocks Full Icecat content (Sony, Barco,
 *     ViewSonic, Huddly, …). Icecat returns HTTP 400 "an app_key is required"
 *     without it.
 *   - Optionally also `api-token` / `content-token` HTTP HEADERS (the newer
 *     token scheme). These MUST be UUIDs — Icecat 400s ("API Token is not
 *     valid UUID") on a non-UUID value, so we only send them when they look
 *     like UUIDs and silently drop junk.
 *
 * Response: data.Image (main: HighPic/Pic500x500/LowPic) + data.Gallery[]
 * (each: Pic/Pic500x500/LowPic, No serial, IsMain "Y"). We return ordered,
 * deduped HIGH-RES URLs. Per Icecat ToS the caller MUST download + re-host
 * the bytes (links may be IP-restricted) — that is the source-images command's
 * job (it runs server-side, so whitelist the server IP in the Icecat account).
 *
 * The same response body also carries GTIN candidates under three legacy/newer
 * schema variants: data.GeneralInfo.GTIN (string), data.GeneralInfo.GTINs[].Value
 * (array of objects), and data.EANCodes[] (array of strings). lookupGtinByMpn
 * tolerates all three.
 *
 * Pure read client. Returns [] / null on miss / not-configured / API error so
 * the caller degrades to other candidate sources rather than failing the run.
 */
class IcecatClient
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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

        $urls = [];

        $ean = $ean !== null ? trim($ean) : '';
        if ($ean !== '') {
            $urls = $this->request($creds, ['GTIN' => $ean]);
        }

        $brand = $brand !== null ? trim($brand) : '';
        $mpn = $mpn !== null ? trim($mpn) : '';
        if ($urls === [] && $brand !== '' && $mpn !== '') {
            $urls = $this->request($creds, ['Brand' => $brand, 'ProductCode' => $mpn]);
        }

        return array_slice($urls, 0, max(1, $limit));
    }

    /**
     * Product description + structured specs, for grounding generated content.
     *
     * 260914-j0x — the whole reason generated copy was thin. `supplier_products`
     * has FOURTEEN columns and not one of them holds prose: sku, title,
     * manufacturer, mpn, stock, price, rrp, ean, and sync metadata. So
     * GenerateProductDraftsCommand::detectDetailColumns() returns [] for every
     * supplier, permanently, and the model was left describing products from a
     * part number. On the 2026-09-13 B-Tech pilot that produced "Pro Monitor Arm
     * Accessory ... designed to complement compatible B-Tech solutions" — and,
     * for a bare code, an invented product type.
     *
     * This client ALREADY fetched the full Icecat record and threw all of it
     * away except the image URLs. Measured coverage on 30 random published
     * products: 21 (70%) return data, with descriptions up to 6,042 characters
     * and up to 19 feature groups. Philips, Epson, Samsung, Poly, Barco, iiyama,
     * Chief, Yealink, SMART, LINDY, Neomounts, StarTech, ViewSonic, BenQ and LG
     * all resolve; Logitech, Neat, Panasonic, Belkin and Huddly are
     * brand-restricted on this account.
     *
     * GTIN first, then Brand+ProductCode — same order as fetchImageUrls().
     *
     * Returns null rather than throwing: grounding is an ENRICHMENT. A product
     * Icecat does not carry must still be creatable from its title.
     *
     * @return array{description: string, features: array<string, string>}|null
     */
    public function fetchProductFacts(?string $ean, ?string $brand, ?string $mpn): ?array
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return null;
        }

        $data = null;

        $ean = $ean !== null ? trim($ean) : '';
        if ($ean !== '') {
            $data = $this->requestRawData($creds, ['GTIN' => $ean]);
        }

        $brand = $brand !== null ? trim($brand) : '';
        $mpn = $mpn !== null ? trim($mpn) : '';
        if ($data === null && $brand !== '' && $mpn !== '') {
            $data = $this->requestRawData($creds, ['Brand' => $brand, 'ProductCode' => $mpn]);
        }

        if ($data === null) {
            return null;
        }

        $description = $this->extractDescription($data);
        $features = $this->extractFeatures($data);

        // Nothing usable is the same as nothing — do not hand the model an
        // empty shell and imply it was grounded.
        if ($description === '' && $features === []) {
            return null;
        }

        return ['description' => $description, 'features' => $features];
    }

    /**
     * Longest available prose, preferring the full description.
     *
     * Icecat exposes several shapes and a product may carry only some of them —
     * LH85WMBWLGCXEN returns 0 description characters but 11 feature groups.
     *
     * @param  array<string, mixed>  $data
     */
    private function extractDescription(array $data): string
    {
        $gi = $data['GeneralInfo'] ?? [];
        if (! is_array($gi)) {
            return '';
        }

        $candidates = [
            $gi['Description']['LongDesc'] ?? null,
            $gi['SummaryDescription']['LongSummaryDescription'] ?? null,
            $gi['SummaryDescription']['ShortSummaryDescription'] ?? null,
            $gi['Description']['MiddleDesc'] ?? null,
        ];

        foreach ($candidates as $c) {
            $text = trim(strip_tags((string) ($c ?? '')));
            if ($text !== '') {
                // 4000 is generous prose and still well inside the prompt
                // budget alongside the feature table.
                return Str::limit($text, 4000, '');
            }
        }

        return '';
    }

    /**
     * Flatten Icecat FeaturesGroups into name => value pairs.
     *
     * The structured specs matter as much as the prose: they are what let the
     * model write "VESA 400x400, 45kg capacity" instead of "professional
     * mounting solution".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function extractFeatures(array $data): array
    {
        $out = [];
        foreach ((array) ($data['FeaturesGroups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ((array) ($group['Features'] ?? []) as $feature) {
                if (! is_array($feature)) {
                    continue;
                }
                $name = trim((string) ($feature['Feature']['Name']['Value'] ?? $feature['LocalName'] ?? ''));
                $value = trim(strip_tags((string) ($feature['PresentationValue'] ?? $feature['Value'] ?? '')));
                if ($name === '' || $value === '') {
                    continue;
                }
                $out[$name] = Str::limit($value, 120, '');
                if (count($out) >= 60) {
                    return $out;      // a spec table, not a datasheet dump
                }
            }
        }

        return $out;
    }

    /**
     * Lookup the first candidate GTIN/EAN for a brand+MPN pair.
     *
     * Returns the raw string from Icecat — the caller MUST pipe it through
     * App\Console\Concerns\NormalisesEan::normaliseEan before persisting,
     * because Icecat occasionally returns placeholder values ("N/A", "0",
     * "—") that look like strings but aren't valid GTINs.
     *
     * Reads the three known schema shapes in priority order:
     *   1. data.GeneralInfo.GTIN              (string)
     *   2. data.GeneralInfo.GTINs[0].Value    (array of objects — newer)
     *   3. data.EANCodes[0]                   (array of strings — older)
     *
     * Returns null when:
     *   - Both brand AND mpn are blank (no HTTP call issued).
     *   - Icecat is not configured (credentials() returns null).
     *   - The HTTP call fails (already logged via IntegrationLogger).
     *   - The product has no data sub-object (Icecat "not found").
     *   - None of the three GTIN field shapes are present in the response.
     *
     * No exception is ever raised — failures degrade to null so the caller
     * (BackfillMerchantFeedCommand::backfillEan) can move on to the next SKU.
     */
    public function lookupGtinByMpn(?string $brand, ?string $mpn): ?string
    {
        $brand = $brand !== null ? trim($brand) : '';
        $mpn = $mpn !== null ? trim($mpn) : '';
        if ($brand === '' && $mpn === '') {
            return null;
        }

        $creds = $this->credentials();
        if ($creds === null) {
            return null;
        }

        $identifier = [];
        if ($brand !== '') {
            $identifier['Brand'] = $brand;
        }
        if ($mpn !== '') {
            $identifier['ProductCode'] = $mpn;
        }

        $data = $this->requestRawData($creds, $identifier);
        if ($data === null) {
            return null;
        }

        return $this->extractGtin($data);
    }

    /**
     * Pull the first GTIN candidate from the decoded `data` sub-object.
     * Tolerates the three known Icecat schemas (see lookupGtinByMpn docblock).
     *
     * @param  array<string, mixed>  $data
     */
    private function extractGtin(array $data): ?string
    {
        // 1) data.GeneralInfo.GTIN — flat string
        $generalInfo = $data['GeneralInfo'] ?? null;
        if (is_array($generalInfo)) {
            $flat = $generalInfo['GTIN'] ?? null;
            if (is_string($flat) && trim($flat) !== '') {
                return trim($flat);
            }

            // 2) data.GeneralInfo.GTINs[0].Value — newer schema
            $gtins = $generalInfo['GTINs'] ?? null;
            if (is_array($gtins)) {
                foreach ($gtins as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $value = $entry['Value'] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                }
            }
        }

        // 3) data.EANCodes[] — older schema (array of strings OR array of objects)
        $eanCodes = $data['EANCodes'] ?? null;
        if (is_array($eanCodes)) {
            foreach ($eanCodes as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    return trim($entry);
                }
                // Tolerate the rare [{ "Value": "..." }] variant some feeds emit.
                if (is_array($entry)) {
                    $value = $entry['Value'] ?? ($entry['EAN'] ?? null);
                    if (is_string($value) && trim($value) !== '') {
                        return trim($value);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Probe reachability + auth. A JSON response (even "product not found")
     * proves the endpoint + account are accepted; an explicit account/auth
     * error message or a 401/403 is a failure.
     */
    public function testConnection(): IntegrationTestResult
    {
        $start = microtime(true);

        $creds = $this->credentials();
        if ($creds === null) {
            return IntegrationTestResult::failed('Icecat username not configured.', 0);
        }

        try {
            // 260914-j0x — probe SEVERAL products, not one.
            //
            // This used to test a single GTIN (0711719709695, a Sony
            // accessory). Sony is brand-restricted on an Open Icecat account,
            // so Icecat replied "To access Full Icecat content, an app_key is
            // required" — which the auth regex below matched on BOTH `access`
            // and `app_key` and reported as an auth failure. The integration was
            // switched off on 2026-05-24 at 11:15 and sat dormant for 113 days,
            // while the account worked perfectly for the brands we actually
            // sell: measured 2026-09-14, 21 of 30 random published products
            // return full data on the username alone.
            //
            // The second GTIN is a Barco ClickShare CX-30, verified as Open
            // Icecat content on this account. If ANY probe returns data the
            // integration is working.
            $probes = ['5415334038875', '0711719709695'];
            $latency = 0;
            $resp = null;
            $json = null;
            $msg = '';
            foreach ($probes as $gtin) {
                $resp = Http::timeout(15)
                    ->withHeaders($this->headers($creds))
                    ->get($this->baseUrl(), $this->query($creds, ['GTIN' => $gtin]));
                $latency = (int) round((microtime(true) - $start) * 1000);
                $json = $resp->json();
                $msg = is_array($json) ? $this->extractMessage($json) : '';

                if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                    return IntegrationTestResult::ok($latency);
                }
            }

            $detail = $msg !== '' ? $msg : Str::limit((string) $resp?->body(), 200, '');

            // "Not covered for THIS product" is not "your account is bad".
            // Brand restrictions and Full-Icecat tier notices are normal on an
            // Open Icecat account and must never disable the integration.
            $notAnAuthProblem = $msg !== '' && preg_match(
                '/brand restriction|access is limited|full icecat|app_key is required|not found|no product|does not exist/i',
                $msg,
            ) === 1;

            $authProblem = ! $notAnAuthProblem
                && ($resp?->status() === 401
                    || $resp?->status() === 403
                    || ($msg !== '' && preg_match('/denied|unauthor|not known|shopname|invalid (?:user|shop|account|username)|blocked|forbidden/i', $msg) === 1));
            if ($authProblem) {
                return IntegrationTestResult::failed('Icecat auth/access: '.($detail !== '' ? $detail : "HTTP {$resp->status()}"), $latency);
            }

            if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                return IntegrationTestResult::ok($latency);
            }

            if ($resp->successful()) {
                return IntegrationTestResult::ok($latency);
            }

            return IntegrationTestResult::failed("Icecat HTTP {$resp->status()}: ".($detail !== '' ? $detail : '(empty body)'), $latency);
        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);

            return IntegrationTestResult::failed($e->getMessage(), $latency);
        }
    }

    /**
     * `protected` (was `private`) so the IcecatClientLookupGtinByMpnTest can
     * override via an anonymous subclass and skip the real resolver boundary
     * (mirrors the runDumpCommand override pattern from 260607-9c6 / the
     * BackfillMerchantFeedCommand test surface).
     *
     * @return array{username:string, app_key:string, api_token:string, content_token:string}|null
     */
    protected function credentials(): ?array
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
            'username' => $username,
            'app_key' => trim((string) ($c['app_key'] ?? '')),
            'api_token' => trim((string) ($c['api_token'] ?? '')),
            'content_token' => trim((string) ($c['content_token'] ?? '')),
        ];
    }

    /**
     * Build the query string: lang + shopname + (app_key if set) + identifier.
     *
     * @param  array{username:string, app_key:string, api_token:string, content_token:string}  $creds
     * @param  array<string, string>  $identifier  GTIN, or Brand+ProductCode
     * @return array<string, string>
     */
    private function query(array $creds, array $identifier): array
    {
        $query = [
            'lang' => $this->language(),
            'shopname' => $creds['username'],
        ];
        if ($creds['app_key'] !== '') {
            $query['app_key'] = $creds['app_key'];
        }

        return array_merge($query, $identifier);
    }

    /**
     * api-token / content-token headers — only sent when they are valid UUIDs
     * (Icecat 400s on a non-UUID value).
     *
     * @param  array{username:string, app_key:string, api_token:string, content_token:string}  $creds
     * @return array<string, string>
     */
    private function headers(array $creds): array
    {
        $headers = [];
        if (preg_match(self::UUID_REGEX, $creds['api_token']) === 1) {
            $headers['api-token'] = $creds['api_token'];
        }
        if (preg_match(self::UUID_REGEX, $creds['content_token']) === 1) {
            $headers['content-token'] = $creds['content_token'];
        }

        return $headers;
    }

    /**
     * @param  array{username:string, app_key:string, api_token:string, content_token:string}  $creds
     * @param  array<string, string>  $identifier  GTIN, or Brand+ProductCode
     * @return array<int, string>
     */
    private function request(array $creds, array $identifier): array
    {
        $data = $this->requestRawData($creds, $identifier);
        if ($data === null) {
            return [];
        }

        $urls = $this->extractUrls($data);
        // Re-emit a count-of-images log line so the existing observability
        // (integration_events.images_found) is preserved. Status + latency
        // come from the most recent transport call.
        $this->log($identifier, $this->lastStatus, 'success', ['images_found' => count($urls)], $this->lastLatencyMs);

        return $urls;
    }

    /**
     * Status + latency from the most recent requestRawData() transport call —
     * exposed so callers (request()) can re-emit a per-payload log line that
     * preserves real latency rather than reporting 0ms.
     */
    private int $lastStatus = 0;

    private int $lastLatencyMs = 0;

    /**
     * Shared transport for fetchImageUrls + lookupGtinByMpn — issues the GET,
     * logs failures via IntegrationLogger, and returns the decoded `data`
     * sub-object (or null on any failure / no-data).
     *
     * Refactored out 2026-06-07 (quick task 260607-g25) so the new GTIN lookup
     * shares auth/error/log paths with the image-URL path; fetchImageUrls
     * behaviour stays byte-identical (`request()` still returns the URL list,
     * still logs `images_found` with real status + latency).
     *
     * @param  array{username:string, app_key:string, api_token:string, content_token:string}  $creds
     * @param  array<string, string>  $identifier  GTIN, or Brand+ProductCode
     * @return array<string, mixed>|null decoded `data` object, or null on miss / error
     */
    protected function requestRawData(array $creds, array $identifier): ?array
    {
        $start = microtime(true);
        try {
            $resp = Http::timeout(20)
                ->withHeaders($this->headers($creds))
                ->get($this->baseUrl(), $this->query($creds, $identifier));
        } catch (\Throwable $e) {
            $this->lastStatus = 0;
            $this->lastLatencyMs = 0;
            $this->log($identifier, 0, 'failed', ['exception' => $e::class, 'message' => $e->getMessage()], 0);

            return null;
        }

        $latency = (int) round((microtime(true) - $start) * 1000);
        $this->lastStatus = $resp->status();
        $this->lastLatencyMs = $latency;

        if (! $resp->successful()) {
            $body = $resp->json();
            $message = is_array($body)
                ? $this->extractMessage($body)
                : (string) $resp->body();
            $this->log($identifier, $resp->status(), 'failed', [
                'reason' => 'non_2xx',
                // Capture Icecat's actual complaint so a 400/403 is diagnosable
                // from integration_events without re-running.
                'icecat_message' => Str::limit($message, 400, ''),
            ], $latency);

            return null;
        }

        $json = $resp->json();
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        if (! is_array($data)) {
            $this->log($identifier, $resp->status(), 'success', [
                'reason' => 'no_data',
                'msg' => Str::limit($this->extractMessage(is_array($json) ? $json : []), 200, ''),
            ], $latency);

            return null;
        }

        return $data;
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
