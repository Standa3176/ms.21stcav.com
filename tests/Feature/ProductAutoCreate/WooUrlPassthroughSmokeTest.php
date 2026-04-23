<?php

declare(strict_types=1);

use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\Response as WooResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Phase 6 Plan 02 — Task 1 (checkpoint:human-verify, Q5 resolution).
 *
 * CANONICAL DOCUMENTED CONTRACT for Plan 03 (CreateWooProductJob) to consume:
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POST /wp-json/wc/v3/products
 * {
 *   "name": "Test Product",
 *   "sku": "TEST-PASSTHROUGH-01",
 *   "type": "simple",
 *   "status": "draft",
 *   "regular_price": "0.00",
 *   "images": [
 *     {
 *       "src":  "https://ops.meetingstore.co.uk/images/sample.jpg",
 *       "name": "Test",
 *       "alt":  "Test alt"
 *     }
 *   ]
 * }
 *
 * ── Woo response (201 Created) ───────────────────────────────────────────
 * {
 *   "id": 99001,
 *   "name": "Test Product",
 *   "slug": "test-product",
 *   "status": "draft",
 *   "images": [
 *     {
 *       "id":   99,
 *       "src":  "https://meetingstore.co.uk/wp-content/uploads/2026/04/sample.jpg",
 *       "name": "Test",
 *       "alt":  "Test alt",
 *       "date_created": "2026-04-23T19:12:00"
 *     }
 *   ]
 * }
 * ─────────────────────────────────────────────────────────────────────────
 *
 * KEY FINDINGS:
 *
 *   1. The outbound "images[*].src" field carries the PUBLIC URL we host
 *      (our Laravel public disk). Woo's background worker (cron or sync)
 *      downloads that URL and stores it in its own /wp-content/uploads/
 *      tree. OUR URL MUST STAY ALIVE FOR A FEW SECONDS POST-POST.
 *
 *   2. The response "images[*].id" is a fresh Woo-assigned media library
 *      ID (99 in the example). The response "images[*].src" is Woo's own
 *      uploads URL — NOT the URL we originally sent. This is the canonical
 *      signal that the URL pass-through succeeded.
 *
 *   3. No base64, no multipart, no /wp-json/wp/v2/media endpoint. The
 *      existing WooClient::post('/products', …) covers the whole surface —
 *      Plan 03's CreateWooProductJob just merges the ImagePayloadBuilder
 *      output into its parent payload.
 *
 *   4. This test uses the WooClient shadow path (WOO_WRITE_ENABLED=false —
 *      Phase 1 FOUND-08 default) to assert the SyncDiff captures the
 *      outbound request body verbatim. A second test exercises the live
 *      path via a mocked AutomatticClient to verify the response decode.
 *
 * Q5 RESOLUTION: the payload shape is verified against the Woo REST docs
 * (rudrastyh.com + woocommerce-rest-api-docs). Live sandbox validation
 * deferred to Phase 7 cutover prep — operator must set WOO_BASE_URL /
 * WOO_CONSUMER_KEY / WOO_CONSUMER_SECRET + WOO_WRITE_ENABLED=true, then
 * run `php artisan tinker` and issue a real POST before flipping the
 * immediate-publish config flag. See
 * storage/app/research/woo-image-passthrough.json for the full fixture
 * artifact (auto-mode-auto-approved in Plan 06-02 — see SUMMARY deviations).
 */
beforeEach(function (): void {
    // correlation_id column is VARCHAR(36) — plain UUID only, no prefix.
    Context::add('correlation_id', (string) Str::uuid());
});

it('URL pass-through: images[].src URL lands in the outbound request body verbatim (shadow path)', function (): void {
    config(['services.woo.write_enabled' => false]);

    $outboundImages = [
        [
            'src' => 'https://ops.meetingstore.co.uk/images/sample.jpg',
            'name' => 'Test',
            'alt' => 'Test alt',
        ],
    ];

    $payload = [
        'name' => 'Test Product',
        'sku' => 'TEST-PASSTHROUGH-01',
        'type' => 'simple',
        'status' => 'draft',
        'regular_price' => '0.00',
        'images' => $outboundImages,
    ];

    $result = app(WooClient::class)->post('/products', $payload);

    // Shadow-mode contract (Phase 1 FOUND-08) — SyncDiff captures the payload verbatim
    expect($result)->toMatchArray(['shadow_mode' => true]);
    $diff = \App\Domain\Sync\Models\SyncDiff::first();
    expect($diff->method)->toBe('POST');
    expect($diff->endpoint)->toBe('/products');

    // The single most important assertion for Q5 — outbound src URL is NOT
    // base64-encoded, NOT multipart — it is carried as a plain URL string in
    // the POST body under images[0].src.
    expect($diff->payload['images'])->toBe($outboundImages);
    expect($diff->payload['images'][0]['src'])
        ->toBe('https://ops.meetingstore.co.uk/images/sample.jpg')
        ->and($diff->payload['images'][0]['src'])->toStartWith('https://')
        ->and($diff->payload['images'][0]['src'])->not->toStartWith('data:');

    // IntegrationLogger captured the outbound
    expect(IntegrationEvent::where('operation', 'POST /products')->count())->toBe(1);
});

it('URL pass-through: documented Woo response shape (id + uploads-path src) decodes cleanly (live path)', function (): void {
    config(['services.woo.write_enabled' => true]);

    $outboundImages = [
        [
            'src' => 'https://ops.meetingstore.co.uk/images/sample.jpg',
            'name' => 'Test',
            'alt' => 'Test alt',
        ],
    ];

    // The canonical Woo REST response documented in the test PHPDoc above
    // — Woo assigns its own media-library id + swaps src to its own uploads URL.
    $documentedWooResponse = [
        'id' => 99001,
        'name' => 'Test Product',
        'slug' => 'test-product',
        'status' => 'draft',
        'images' => [
            [
                'id' => 99,
                'src' => 'https://meetingstore.co.uk/wp-content/uploads/2026/04/sample.jpg',
                'name' => 'Test',
                'alt' => 'Test alt',
                'date_created' => '2026-04-23T19:12:00',
            ],
        ],
    ];

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(201, [], ''));
    $mockInner->http = $mockHttp;

    $mockInner->shouldReceive('post')
        ->once()
        ->with('/products', Mockery::on(function (array $payload) use ($outboundImages): bool {
            // Assert the outbound payload DOES carry the original src URL
            return $payload['images'] === $outboundImages
                && $payload['images'][0]['src'] === 'https://ops.meetingstore.co.uk/images/sample.jpg';
        }))
        ->andReturn($documentedWooResponse);

    $client = new WooClient(app(IntegrationLogger::class), $mockInner);

    $response = $client->post('/products', [
        'name' => 'Test Product',
        'sku' => 'TEST-PASSTHROUGH-01',
        'type' => 'simple',
        'status' => 'draft',
        'regular_price' => '0.00',
        'images' => $outboundImages,
    ]);

    // Q5 evidence — Woo returns its own image id + its own uploads URL
    expect($response)->toHaveKey('id', 99001);
    expect($response['images'])->toHaveCount(1);
    expect($response['images'][0]['id'])->toBe(99);
    expect($response['images'][0]['src'])
        ->toBe('https://meetingstore.co.uk/wp-content/uploads/2026/04/sample.jpg')
        ->and($response['images'][0]['src'])->toContain('wp-content/uploads');

    // The response src DIFFERS from our sent src — this is the core URL-pass-through signal
    expect($response['images'][0]['src'])
        ->not->toBe($outboundImages[0]['src']);
});

it('URL pass-through: empty images[] array is accepted (placeholder-image fallback scenario)', function (): void {
    config(['services.woo.write_enabled' => false]);

    $result = app(WooClient::class)->post('/products', [
        'name' => 'No Image Product',
        'sku' => 'NO-IMG-01',
        'type' => 'simple',
        'status' => 'draft',
        'regular_price' => '0.00',
        'images' => [],
    ]);

    expect($result)->toMatchArray(['shadow_mode' => true]);
    $diff = \App\Domain\Sync\Models\SyncDiff::first();
    expect($diff->payload['images'])->toBe([]);
});
