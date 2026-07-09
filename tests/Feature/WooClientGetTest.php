<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\HttpClientException;
use Automattic\WooCommerce\HttpClient\Request as WooRequest;
use Automattic\WooCommerce\HttpClient\Response as WooResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

beforeEach(function () {
    // correlation_id column is VARCHAR(36) — use a plain UUID, no prefix.
    Context::add('correlation_id', (string) Str::uuid());
});

// ─────────────────────────────────────────────────────────────
// Test G1: get() returns array payload from the Automattic client
// ─────────────────────────────────────────────────────────────
it('get() returns the Automattic client payload as an array', function () {
    $mockInner = Mockery::mock(AutomatticClient::class);
    // Stub the ->http->getResponse() chain for status-code reads
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;
    $mockInner->shouldReceive('get')
        ->once()
        ->with('products', ['per_page' => 100])
        ->andReturn([
            ['id' => 1, 'sku' => 'SKU-A', 'price' => '99.00'],
            ['id' => 2, 'sku' => 'SKU-B', 'price' => '149.50'],
        ]);

    $client = new WooClient(app(IntegrationLogger::class), app(IntegrationCredentialResolver::class), $mockInner);
    $result = $client->get('products', ['per_page' => 100]);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result[0])->toHaveKey('sku', 'SKU-A');
});

// ─────────────────────────────────────────────────────────────
// Test G2: every get() writes an integration_events row
// ─────────────────────────────────────────────────────────────
it('get() writes an integration_events row with channel=woo + method=GET + status=success', function () {
    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;
    $mockInner->shouldReceive('get')->once()->andReturn([]);

    (new WooClient(app(IntegrationLogger::class), app(IntegrationCredentialResolver::class), $mockInner))
        ->get('products', ['per_page' => 100, 'page' => 1]);

    $events = IntegrationEvent::all();
    expect($events)->toHaveCount(1);

    $event = $events->first();
    expect($event->channel)->toBe('woo')
        ->and($event->method)->toBe('GET')
        ->and($event->endpoint)->toBe('products')
        ->and($event->http_status)->toBe(200)
        ->and($event->status)->toBe('success')
        ->and($event->latency_ms)->toBeGreaterThanOrEqual(0)
        ->and($event->correlation_id)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────
// Test G3: errors propagate but still log a failed event
// ─────────────────────────────────────────────────────────────
it('get() propagates exceptions from the client but records a failed integration_events row', function () {
    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(500, [], ''));
    $mockInner->http = $mockHttp;
    $mockInner->shouldReceive('get')->once()->andThrow(
        new HttpClientException('Internal Server Error', 500, new WooRequest(), new WooResponse(500, [], ''))
    );

    expect(fn () => (new WooClient(app(IntegrationLogger::class), app(IntegrationCredentialResolver::class), $mockInner))->get('products'))
        ->toThrow(HttpClientException::class);

    $event = IntegrationEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->status)->toBe('failed')
        ->and($event->http_status)->toBe(500);
});

// ─────────────────────────────────────────────────────────────
// Test G4: constructor $inner type is Automattic\WooCommerce\Client (reflection)
// ─────────────────────────────────────────────────────────────
it('WooClient constructor types $inner as ?Automattic\\WooCommerce\\Client', function () {
    $refl = new ReflectionMethod(WooClient::class, '__construct');
    $params = $refl->getParameters();

    // Params: [IntegrationLogger $logger, IntegrationCredentialResolver $resolver, ?AutomatticClient $inner = null]
    expect($params)->toHaveCount(3);

    expect($params[1]->getType())->not->toBeNull()
        ->and($params[1]->getType()->getName())->toBe(IntegrationCredentialResolver::class);

    $innerParam = $params[2];
    $type = $innerParam->getType();

    expect($type)->not->toBeNull()
        ->and($type->getName())->toBe(AutomatticClient::class)
        ->and($type->allowsNull())->toBeTrue();
});
