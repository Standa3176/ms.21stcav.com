<?php

use App\Domain\Webhooks\Events\OrderReceived;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['services.woo.webhook_secret' => 'test-secret-alphanum-only']);
});

/** Helper: build a valid HMAC signature for a given raw body. */
function wooSign(string $rawBody, string $secret = 'test-secret-alphanum-only'): string
{
    return base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
}

it('accepts a correctly-signed order webhook and persists one receipt', function () {
    Event::fake([OrderReceived::class]);
    $body = json_encode(['id' => 555, 'status' => 'processing', 'total' => '99.99']);
    $sig = wooSign($body);

    $response = $this->call(
        'POST',
        '/webhooks/woo/order',
        [], [], [],
        [
            'HTTP_X-WC-Webhook-Signature' => $sig,
            'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_delivery_1',
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertOk();
    expect($response->json('status'))->toBe('accepted');
    expect(WebhookReceipt::count())->toBe(1);

    $receipt = WebhookReceipt::first();
    expect($receipt->source)->toBe('woo');
    expect($receipt->topic)->toBe('order');
    expect($receipt->delivery_id)->toBe('wh_delivery_1');
    expect($receipt->raw_body)->toBe($body);

    Event::assertDispatched(OrderReceived::class, 1);
});

it('rejects requests with tampered body (body does not match HMAC)', function () {
    $bodyForSig = json_encode(['id' => 555]);
    $bodyPosted = json_encode(['id' => 999]); // different bytes — HMAC will mismatch
    $sig = wooSign($bodyForSig);

    $response = $this->call(
        'POST',
        '/webhooks/woo/order',
        [], [], [],
        [
            'HTTP_X-WC-Webhook-Signature' => $sig,
            'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_delivery_2',
            'CONTENT_TYPE' => 'application/json',
        ],
        $bodyPosted
    );

    $response->assertStatus(401);
    expect(WebhookReceipt::count())->toBe(0);
});

it('rejects requests missing X-WC-Webhook-Signature header', function () {
    $response = $this->call(
        'POST',
        '/webhooks/woo/order',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['id' => 1])
    );

    $response->assertStatus(401);
});

it('rejects requests when server secret is not configured', function () {
    config(['services.woo.webhook_secret' => '']);

    $body = json_encode(['id' => 1]);
    $response = $this->call(
        'POST', '/webhooks/woo/order', [], [], [],
        ['HTTP_X-WC-Webhook-Signature' => 'anything', 'CONTENT_TYPE' => 'application/json'],
        $body
    );

    $response->assertStatus(401);
});

it('deduplicates retries by (source, delivery_id) — second POST returns duplicate', function () {
    Event::fake([OrderReceived::class]);
    $body = json_encode(['id' => 777]);
    $sig = wooSign($body);
    $headers = [
        'HTTP_X-WC-Webhook-Signature' => $sig,
        'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_delivery_retry',
        'CONTENT_TYPE' => 'application/json',
    ];

    $first = $this->call('POST', '/webhooks/woo/order', [], [], [], $headers, $body);
    $second = $this->call('POST', '/webhooks/woo/order', [], [], [], $headers, $body);

    $first->assertOk()->assertJson(['status' => 'accepted']);
    $second->assertOk()->assertJson(['status' => 'duplicate']);

    expect(WebhookReceipt::count())->toBe(1);
    Event::assertDispatched(OrderReceived::class, 1); // not 2
});

it('emits X-Correlation-Id header on the response (Plan 03 integration)', function () {
    $body = json_encode(['id' => 1]);
    $sig = wooSign($body);

    $response = $this->call(
        'POST', '/webhooks/woo/order', [], [], [],
        [
            'HTTP_X-WC-Webhook-Signature' => $sig,
            'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_cid_test',
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertOk();
    expect($response->headers->get('X-Correlation-Id'))->not->toBeNull();
});

it('completes the HMAC → insert → event dispatch cycle in under 200ms (FOUND-07 acceptance)', function () {
    $body = json_encode(['id' => 1]);
    $sig = wooSign($body);

    $start = microtime(true);
    $response = $this->call(
        'POST', '/webhooks/woo/order', [], [], [],
        [
            'HTTP_X-WC-Webhook-Signature' => $sig,
            'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_latency_test',
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );
    $elapsedMs = (microtime(true) - $start) * 1000;

    $response->assertOk();
    expect($elapsedMs)->toBeLessThan(200.0); // FOUND-07 success criterion
});

it('routes /webhooks/woo/customer to the customer handler', function () {
    $body = json_encode(['id' => 42, 'email' => 'test@example.com']);
    $sig = wooSign($body);

    $response = $this->call(
        'POST', '/webhooks/woo/customer', [], [], [],
        [
            'HTTP_X-WC-Webhook-Signature' => $sig,
            'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_cust_1',
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertOk();
    expect(WebhookReceipt::first()->topic)->toBe('customer');
});
