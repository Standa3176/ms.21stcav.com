<?php

declare(strict_types=1);

use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Automattic\WooCommerce\Client as AutomatticClient;
use Automattic\WooCommerce\HttpClient\Response as WooResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.woo.write_enabled' => false]);
});

it('records a SyncDiff instead of calling Woo when WOO_WRITE_ENABLED=false', function () {
    $result = app(WooClient::class)->put('products/1234', ['regular_price' => '99.99']);

    expect(SyncDiff::count())->toBe(1);
    $diff = SyncDiff::first();
    expect($diff->method)->toBe('PUT')
        ->and($diff->endpoint)->toBe('products/1234')
        ->and($diff->woo_id)->toBe('1234')
        ->and($diff->channel)->toBe('woo')
        ->and($diff->status)->toBe('pending')
        ->and($diff->payload)->toBe(['regular_price' => '99.99']);

    expect($result)->toMatchArray(['shadow_mode' => true])
        ->and($result['diff_id'])->toBe($diff->id);
});

/*
 * P2-K: This test used to assert `LogicException` was thrown when write_enabled=true
 * (Phase 1's placeholder). Phase 2 Plan 02 fills the writeLive() branch with a real
 * Automattic\WooCommerce\Client invocation — the LogicException only remains as a
 * safety fallback when $inner is null.
 */
it('WOO_WRITE_ENABLED=true invokes the Automattic client (replaces Phase 1 LogicException)', function () {
    config(['services.woo.write_enabled' => true]);
    // correlation_id column is VARCHAR(36) — use a plain UUID, no prefix.
    Context::add('correlation_id', (string) Str::uuid());

    $mockInner = Mockery::mock(AutomatticClient::class);
    $mockHttp = Mockery::mock();
    $mockHttp->shouldReceive('getResponse')->andReturn(new WooResponse(200, [], ''));
    $mockInner->http = $mockHttp;

    $mockInner->shouldReceive('put')
        ->once()
        ->with('products/1234', ['regular_price' => '99.99'])
        ->andReturn(['id' => 1234, 'regular_price' => '99.99']);

    $client = new WooClient(app(IntegrationLogger::class), $mockInner);
    $result = $client->put('products/1234', ['regular_price' => '99.99']);

    expect($result)->toHaveKey('id', 1234);

    // integration_events row persisted with status=success
    expect(IntegrationEvent::where('endpoint', 'products/1234')
        ->where('status', 'success')
        ->count())->toBe(1);

    // NO sync_diffs row on a live write (live path does not shadow)
    expect(SyncDiff::count())->toBe(0);
});

it('writes integration_events row with shadow_mode response when diff recorded', function () {
    app(WooClient::class)->post('products', ['name' => 'Test Product', 'sku' => 'TST-1']);

    $events = IntegrationEvent::all();
    expect($events)->toHaveCount(1);
    expect($events->first()->channel)->toBe('woo');
    expect($events->first()->operation)->toBe('POST products');
    expect($events->first()->response_body)->toMatchArray(['shadow_mode' => true]);
});

it('extracts woo_id from endpoint patterns', function () {
    app(WooClient::class)->put('products/4567', ['x' => 1]);
    expect(SyncDiff::where('endpoint', 'products/4567')->first()->woo_id)->toBe('4567');

    app(WooClient::class)->patch('products/9999/variations/42', ['y' => 1]);
    expect(SyncDiff::where('endpoint', 'products/9999/variations/42')->first()->woo_id)->toBe('9999');

    app(WooClient::class)->post('orders', ['z' => 1]);
    expect(SyncDiff::where('endpoint', 'orders')->first()->woo_id)->toBeNull();
});
