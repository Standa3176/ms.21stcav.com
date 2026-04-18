<?php

use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Integration\Models\IntegrationEvent;

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

it('throws LogicException on write_enabled=true in Phase 1 (Phase 2 implements real write)', function () {
    config(['services.woo.write_enabled' => true]);

    expect(fn () => app(WooClient::class)->put('products/1234', []))
        ->toThrow(\LogicException::class);
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
