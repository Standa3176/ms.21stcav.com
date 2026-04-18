<?php

declare(strict_types=1);

use App\Domain\Sync\Exceptions\JwtRefreshFailedException;
use App\Domain\Sync\Services\SupplierClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    // correlation_id column is VARCHAR(36) — use a plain UUID.
    Context::add('correlation_id', (string) Str::uuid());
    config([
        'services.supplier.url' => 'https://fake-supplier.test',
        'services.supplier.username' => 'testuser',
        'services.supplier.password' => 'testpass',
    ]);
});

// ─────────────────────────────────────────────────────────────
// Test S1: fetchAllProducts returns flat SKU-keyed hashmap across pages
// ─────────────────────────────────────────────────────────────
it('fetchAllProducts returns flat SKU-keyed hashmap from paged JSON response', function () {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['token' => 'fake-jwt-abc', 'expires_in' => 3600],
            200
        ),
        'https://fake-supplier.test/api/index.php*' => Http::sequence()
            ->push(['data' => [['sku' => 'SKU-1', 'price' => '99.00', 'stock' => 5]], 'next_page' => 2], 200)
            ->push(['data' => [['sku' => 'SKU-2', 'price' => '125.50', 'stock' => 0]], 'next_page' => null], 200),
    ]);

    $result = app(SupplierClient::class)->fetchAllProducts();

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result)->toHaveKey('SKU-1')
        ->and($result)->toHaveKey('SKU-2')
        ->and($result['SKU-1'])->toEqual(['price' => '99.00', 'stock' => 5])
        ->and($result['SKU-2'])->toEqual(['price' => '125.50', 'stock' => 0]);
});

// ─────────────────────────────────────────────────────────────
// Test S2: Token is fetched once per fetchAllProducts call; subsequent page calls reuse it
// ─────────────────────────────────────────────────────────────
it('fetches the JWT exactly once per fetchAllProducts call when Cache is empty', function () {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['token' => 'fake-jwt-once', 'expires_in' => 3600],
            200
        ),
        'https://fake-supplier.test/api/index.php*' => Http::sequence()
            ->push(['data' => [['sku' => 'A', 'price' => '1.00', 'stock' => 1]], 'next_page' => 2], 200)
            ->push(['data' => [['sku' => 'B', 'price' => '2.00', 'stock' => 2]], 'next_page' => 3], 200)
            ->push(['data' => [['sku' => 'C', 'price' => '3.00', 'stock' => 3]], 'next_page' => null], 200),
    ]);

    app(SupplierClient::class)->fetchAllProducts();

    Http::assertSentCount(4); // 1 token.generate + 3 paged fetches

    $tokenCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generate_token.php'))
        ->count();

    expect($tokenCalls)->toBe(1);
});

// ─────────────────────────────────────────────────────────────
// Test S3: Every supplier call writes an integration_events row with Authorization redacted
// ─────────────────────────────────────────────────────────────
it('logs every supplier API call to integration_events with Authorization redacted', function () {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['token' => 'fake-jwt-redact', 'expires_in' => 3600],
            200
        ),
        'https://fake-supplier.test/api/index.php*' => Http::response(
            ['data' => [['sku' => 'X', 'price' => '10.00', 'stock' => 9]], 'next_page' => null],
            200
        ),
    ]);

    app(SupplierClient::class)->fetchAllProducts();

    $events = IntegrationEvent::where('channel', 'supplier')->get();
    expect($events)->toHaveCount(2); // token.generate + fetch.page.1

    $tokenEvent = $events->firstWhere('operation', 'token.generate');
    expect($tokenEvent)->not->toBeNull()
        ->and($tokenEvent->http_status)->toBe(200)
        ->and($tokenEvent->status)->toBe('success');

    // Body redaction: password field masked in token.generate request_body.
    expect($tokenEvent->request_body['password'] ?? null)->toBe('***REDACTED***');
    // Response body should NOT contain the real token string.
    $tokenBodyJson = json_encode($tokenEvent->response_body);
    expect($tokenBodyJson)->not->toContain('fake-jwt-redact');

    $fetchEvent = $events->firstWhere('operation', 'fetch.page.1');
    expect($fetchEvent)->not->toBeNull();
    // IntegrationLogger auto-redacts the authorization header.
    $headers = $fetchEvent->request_headers ?? [];
    expect($headers)->toHaveKey('authorization')
        ->and($headers['authorization'])->toBe(['***REDACTED***']);
});

// ─────────────────────────────────────────────────────────────
// Test S4: Token generation failure (401/500) throws JwtRefreshFailedException
// ─────────────────────────────────────────────────────────────
it('throws JwtRefreshFailedException when /generate_token.php returns 401', function () {
    Http::fake([
        'https://fake-supplier.test/generate_token.php' => Http::response(
            ['error' => 'invalid credentials'],
            401
        ),
    ]);

    expect(fn () => app(SupplierClient::class)->fetchAllProducts())
        ->toThrow(JwtRefreshFailedException::class);
});
