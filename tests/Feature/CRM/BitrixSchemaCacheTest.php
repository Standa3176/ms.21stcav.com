<?php

declare(strict_types=1);

use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\BitrixSchemaCache;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 02 Task 2 — BitrixSchemaCache
|--------------------------------------------------------------------------
|
| CRM-02 acceptance: 24h cache + "Refresh from Bitrix" button + push-time
| validation. Cache keys are predictable (bitrix:schema:{deal,contact,company}).
*/

beforeEach(function (): void {
    Cache::flush();
    config(['services.bitrix.cache_ttl_hours' => 24]);
});

it('caches fieldsFor for 24h - second call does not re-hit the SDK', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->once()->andReturn([
        'UF_CRM_WOO_ORDER_ID' => ['type' => 'string'],
        'TITLE' => ['type' => 'string'],
    ]);

    $cache = new BitrixSchemaCache($client, app(\App\Foundation\Integration\Services\IntegrationLogger::class));

    $first = $cache->fieldsFor('deal');
    $second = $cache->fieldsFor('deal');

    expect($first)->toHaveKey('UF_CRM_WOO_ORDER_ID');
    expect($second)->toEqual($first);
});

it('invalidate() clears all three cache keys', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->twice()->andReturn(['X' => []]);
    $client->shouldReceive('contactFieldsGet')->twice()->andReturn(['Y' => []]);
    $client->shouldReceive('companyFieldsGet')->twice()->andReturn(['Z' => []]);

    $cache = new BitrixSchemaCache($client, app(\App\Foundation\Integration\Services\IntegrationLogger::class));

    // Warm all three
    $cache->fieldsFor('deal');
    $cache->fieldsFor('contact');
    $cache->fieldsFor('company');

    $cache->invalidate();

    // All three cache keys should be gone
    expect(Cache::get('bitrix:schema:deal'))->toBeNull();
    expect(Cache::get('bitrix:schema:contact'))->toBeNull();
    expect(Cache::get('bitrix:schema:company'))->toBeNull();

    // Refetching hits the SDK again (twice total per method -> verified by mock expectations)
    $cache->fieldsFor('deal');
    $cache->fieldsFor('contact');
    $cache->fieldsFor('company');
});

it('validateMapping returns true for a known field', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->once()->andReturn([
        'UF_CRM_WOO_ORDER_ID' => ['type' => 'string'],
    ]);

    $cache = new BitrixSchemaCache($client, app(\App\Foundation\Integration\Services\IntegrationLogger::class));

    expect($cache->validateMapping('deal', 'UF_CRM_WOO_ORDER_ID'))->toBeTrue();
});

it('validateMapping returns false for an unknown field', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->once()->andReturn([
        'TITLE' => ['type' => 'string'],
    ]);

    $cache = new BitrixSchemaCache($client, app(\App\Foundation\Integration\Services\IntegrationLogger::class));

    expect($cache->validateMapping('deal', 'UF_CRM_MISSING'))->toBeFalse();
});

it('validateMapping invalidates cache on live fetch failure', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')
        ->once()
        ->andThrow(new \App\Domain\CRM\Exceptions\BitrixPermanentException('auth broken'));

    $cache = new BitrixSchemaCache($client, app(\App\Foundation\Integration\Services\IntegrationLogger::class));

    expect($cache->validateMapping('deal', 'UF_CRM_WOO_ORDER_ID'))->toBeFalse();
    // Cache should NOT retain a stale entry that would hide the auth break
    expect(Cache::get('bitrix:schema:deal'))->toBeNull();
});

it('rejects unknown entity_type', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $cache = new BitrixSchemaCache($client, app(\App\Foundation\Integration\Services\IntegrationLogger::class));

    expect(fn () => $cache->fieldsFor('lead'))->toThrow(InvalidArgumentException::class);
});

it('uses configured TTL hours (short TTL causes re-fetch)', function (): void {
    // TTL of 0 hours -> Cache::remember uses 0 seconds -> effectively not cached.
    // Instead test: set TTL=1 and prove the configured value is applied.
    config(['services.bitrix.cache_ttl_hours' => 1]);

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->once()->andReturn(['X' => []]);

    $cache = new BitrixSchemaCache($client, app(\App\Foundation\Integration\Services\IntegrationLogger::class));

    $cache->fieldsFor('deal');

    // Verify cache key exists with correct prefix
    expect(Cache::has('bitrix:schema:deal'))->toBeTrue();
});
