<?php

declare(strict_types=1);

use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('persists a row with all expected columns', function () {
    Context::add('correlation_id', 'test-cid-1');
    $logger = app(IntegrationLogger::class);

    $event = $logger->log([
        'channel' => 'woo',
        'operation' => 'product.update',
        'endpoint' => 'products/1234',
        'method' => 'PUT',
        'request_body' => ['regular_price' => '99.99'],
        'response_body' => ['id' => 1234, 'price' => '99.99'],
        'http_status' => 200,
        'latency_ms' => 145,
        'status' => 'success',
    ]);

    expect($event)->toBeInstanceOf(IntegrationEvent::class);
    expect(IntegrationEvent::count())->toBe(1);
    expect($event->correlation_id)->toBe('test-cid-1');
    expect($event->channel)->toBe('woo');
    expect($event->direction)->toBe('outbound'); // default
    expect($event->attempt)->toBe(1);             // default
    expect($event->http_status)->toBe(200);
    expect($event->request_body)->toBe(['regular_price' => '99.99']); // json cast
});

it('redacts sensitive request_headers (case-insensitive)', function () {
    Context::add('correlation_id', 'redact-cid-1');
    $logger = app(IntegrationLogger::class);

    $event = $logger->log([
        'channel' => 'woo',
        'operation' => 'test',
        'endpoint' => '/test',
        'method' => 'POST',
        'status' => 'success',
        'request_headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer super-secret-token',
            'X-WC-Webhook-Signature' => 'abc123==',
            'cookie' => 'session=xyz',
            'X-API-KEY' => 'sk_live_xxx',
            'X-Auth-Token' => 'bitrix-token',
            'User-Agent' => 'test',
        ],
    ]);

    $headers = $event->request_headers;

    expect($headers['Content-Type'])->toBe('application/json');
    expect($headers['User-Agent'])->toBe('test');
    expect($headers['Authorization'])->toBe(['***REDACTED***']);
    expect($headers['X-WC-Webhook-Signature'])->toBe(['***REDACTED***']);
    expect($headers['cookie'])->toBe(['***REDACTED***']);
    expect($headers['X-API-KEY'])->toBe(['***REDACTED***']);
    expect($headers['X-Auth-Token'])->toBe(['***REDACTED***']);
});

it('auto-attaches correlation_id from Context when not explicitly passed', function () {
    Context::add('correlation_id', 'auto-cid-xyz');

    $event = app(IntegrationLogger::class)->log([
        'channel' => 'bitrix',
        'operation' => 'deal.add',
        'endpoint' => 'crm.deal.add',
        'method' => 'POST',
        'status' => 'failed',
    ]);

    expect($event->correlation_id)->toBe('auto-cid-xyz');
});

it('has indexes on correlation_id, (channel, created_at), (status, created_at)', function () {
    $columns = Schema::getColumnListing('integration_events');
    expect($columns)->toContain('correlation_id', 'channel', 'status', 'created_at');

    $indexes = collect(Schema::getIndexes('integration_events'))->pluck('name')->unique()->values();

    expect($indexes)->toContain('integration_events_correlation_id_index');
    expect($indexes)->toContain('integration_events_channel_created_at_index');
    expect($indexes)->toContain('integration_events_status_created_at_index');
});

it('supports ULID-keyed subjects via nullableUlidMorphs (CHAR(26) subject_id)', function () {
    Context::add('correlation_id', 'ulid-cid-1');
    // Prove the schema accepts 26-char ULIDs — Plan 04's Suggestion model will rely on this.
    $ulid = '01JF4R5GNZ7KQ8W3DPXVEM2YAB';

    $event = app(IntegrationLogger::class)->log([
        'channel' => 'suggestions',
        'operation' => 'apply:test',
        'endpoint' => 'internal://suggestions/apply',
        'method' => 'APPLY',
        'status' => 'success',
        'subject_type' => 'App\\Domain\\Suggestions\\Models\\Suggestion',
        'subject_id' => $ulid,
    ]);

    expect($event->subject_id)->toBe($ulid);
    expect(strlen($event->subject_id))->toBe(26);
});
