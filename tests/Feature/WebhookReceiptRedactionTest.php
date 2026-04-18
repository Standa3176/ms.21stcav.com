<?php

use App\Domain\Webhooks\Models\WebhookReceipt;

it('redacts sensitive inbound headers via WebhookReceipt::redactHeaders()', function () {
    $raw = [
        'Content-Type' => 'application/json',
        'User-Agent' => 'WooCommerce/9.0',
        'Authorization' => 'Bearer leaked-token-should-never-land',
        'X-WC-Webhook-Signature' => 'abc123==',
        'cookie' => 'session=xyz',
        'X-Api-Key' => 'sk_live_should_be_redacted',
        'set-cookie' => 'tracking=1',
        'X-Auth-Token' => 'oauth-token',
        'X-Session-Token' => 'sess-token',
    ];

    $redacted = WebhookReceipt::redactHeaders($raw);

    // Non-sensitive headers preserved verbatim
    expect($redacted['Content-Type'])->toBe('application/json');
    expect($redacted['User-Agent'])->toBe('WooCommerce/9.0');

    // Sensitive headers replaced with ['***REDACTED***']
    expect($redacted['Authorization'])->toBe(['***REDACTED***']);
    expect($redacted['X-WC-Webhook-Signature'])->toBe(['***REDACTED***']);
    expect($redacted['cookie'])->toBe(['***REDACTED***']);
    expect($redacted['X-Api-Key'])->toBe(['***REDACTED***']);
    expect($redacted['set-cookie'])->toBe(['***REDACTED***']);
    expect($redacted['X-Auth-Token'])->toBe(['***REDACTED***']);
    expect($redacted['X-Session-Token'])->toBe(['***REDACTED***']);
});

it('redacts sensitive headers on webhook_receipts insert via WooWebhookController', function () {
    config(['services.woo.webhook_secret' => 'test-secret-alphanum-only']);

    $body = json_encode(['id' => 999]);
    $sig = base64_encode(hash_hmac('sha256', $body, 'test-secret-alphanum-only', true));

    // Send a request with a leaked Authorization header that Woo "shouldn't" send
    $response = $this->call(
        'POST', '/webhooks/woo/order', [], [], [],
        [
            'HTTP_X-WC-Webhook-Signature' => $sig,
            'HTTP_X-WC-Webhook-Delivery-ID' => 'wh_redact_test',
            'HTTP_AUTHORIZATION' => 'Bearer should-be-redacted',
            'HTTP_COOKIE' => 'session=leaked',
            'CONTENT_TYPE' => 'application/json',
        ],
        $body
    );

    $response->assertOk();

    $receipt = WebhookReceipt::where('delivery_id', 'wh_redact_test')->firstOrFail();
    $headers = $receipt->headers;

    // Authorization + Cookie + Signature redacted in the persisted row even though HMAC verify passed.
    // Symfony lower-cases header keys; check both variants for resilience.
    $authVal = $headers['authorization'] ?? $headers['Authorization'] ?? null;
    $cookieVal = $headers['cookie'] ?? $headers['Cookie'] ?? null;
    $sigVal = $headers['x-wc-webhook-signature'] ?? $headers['X-WC-Webhook-Signature'] ?? null;

    expect($authVal)->toBe(['***REDACTED***']);
    expect($cookieVal)->toBe(['***REDACTED***']);
    expect($sigVal)->toBe(['***REDACTED***']);

    // Non-sensitive header survives
    $contentType = $headers['content-type'] ?? $headers['Content-Type'] ?? null;
    expect($contentType)->not->toBeNull();
});
