<?php

declare(strict_types=1);

use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\EntityDeduper;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 02 Task 3 — EntityDeduper 4-step cascade
|--------------------------------------------------------------------------
|
| Contact: map → phone dupe → email dupe → create.
| Company: last_payload_hash(sha256(title+postcode)) → create.
| Deal: map → dealList UF filter (adopt on hit, audit on multi-match).
|
| Every decision writes an integration_events row with endpoint=crm.deduper.{entity}.
*/

function makeDeduper($client): EntityDeduper
{
    return new EntityDeduper($client, app(IntegrationLogger::class));
}

it('returns map_hit bitrix_id when entity_map row exists for contact', function (): void {
    BitrixEntityMap::factory()->contactFor(500, 'B100', 'x@y.com')->create();

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('contactUpdate')->once()->with('B100', Mockery::any(), Mockery::any());
    $client->shouldNotReceive('contactAdd');
    $client->shouldNotReceive('duplicateFindByComm');

    $deduper = makeDeduper($client);
    $result = $deduper->findOrCreateContact(500, ['NAME' => 'X', 'EMAIL' => [['VALUE' => 'x@y.com']]]);

    expect($result)->toBe('B100');
    expect(IntegrationEvent::where('endpoint', 'crm.deduper.contact')
        ->whereJsonContains('response_body->step', 'map_hit')
        ->exists())->toBeTrue();
});

it('cascades to phone dedup when map misses and phone present', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('duplicateFindByComm')
        ->once()
        ->with('PHONE', 'CONTACT', ['+447700900111'], Mockery::any())
        ->andReturn(['CONTACT' => ['B200']]);
    $client->shouldReceive('contactUpdate')->once()->with('B200', Mockery::any(), Mockery::any());
    $client->shouldNotReceive('contactAdd');

    $deduper = makeDeduper($client);
    $result = $deduper->findOrCreateContact(501, [
        'NAME' => 'Jane',
        'PHONE' => [['VALUE' => '+44 7700 900111']],
        'EMAIL' => [['VALUE' => 'jane@example.com']],
    ]);

    expect($result)->toBe('B200');
    expect(IntegrationEvent::whereJsonContains('response_body->step', 'phone_dupe_hit')->exists())->toBeTrue();
    expect(BitrixEntityMap::where('entity_type', 'contact')->where('woo_id', 501)->where('bitrix_id', 'B200')->exists())->toBeTrue();
});

it('cascades to email dedup when phone step misses', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('duplicateFindByComm')
        ->once()
        ->with('PHONE', 'CONTACT', Mockery::any(), Mockery::any())
        ->andReturn(['CONTACT' => []]);
    $client->shouldReceive('duplicateFindByComm')
        ->once()
        ->with('EMAIL', 'CONTACT', ['jane@example.com'], Mockery::any())
        ->andReturn(['CONTACT' => ['B300']]);
    $client->shouldReceive('contactUpdate')->once()->with('B300', Mockery::any(), Mockery::any());
    $client->shouldNotReceive('contactAdd');

    $deduper = makeDeduper($client);
    $result = $deduper->findOrCreateContact(502, [
        'NAME' => 'Jane',
        'PHONE' => [['VALUE' => '+447700900111']],
        'EMAIL' => [['VALUE' => 'jane@example.com']],
    ]);

    expect($result)->toBe('B300');
    expect(IntegrationEvent::whereJsonContains('response_body->step', 'email_dupe_hit')->exists())->toBeTrue();
});

it('creates when all dedup steps miss', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('duplicateFindByComm')->twice()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('contactAdd')->once()->andReturn('B999');
    $client->shouldNotReceive('contactUpdate');

    $deduper = makeDeduper($client);
    $result = $deduper->findOrCreateContact(503, [
        'NAME' => 'Jane',
        'PHONE' => [['VALUE' => '+447700900111']],
        'EMAIL' => [['VALUE' => 'jane@example.com']],
    ]);

    expect($result)->toBe('B999');
    expect(IntegrationEvent::whereJsonContains('response_body->step', 'created')->exists())->toBeTrue();

    $map = BitrixEntityMap::where('entity_type', 'contact')->where('woo_id', 503)->first();
    expect($map)->not->toBeNull();
    expect($map->bitrix_id)->toBe('B999');
    expect($map->email_hash)->toBe(hash('sha256', 'jane@example.com'));
});

it('skips phone step when phone is empty or malformed', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    // Only email dedup should run — no phone dedup call
    $client->shouldReceive('duplicateFindByComm')
        ->once()
        ->with('EMAIL', 'CONTACT', Mockery::any(), Mockery::any())
        ->andReturn(['CONTACT' => []]);
    $client->shouldReceive('contactAdd')->once()->andReturn('B777');

    $deduper = makeDeduper($client);
    $result = $deduper->findOrCreateContact(504, [
        'NAME' => 'NoPhone',
        'EMAIL' => [['VALUE' => 'nophone@example.com']],
        // No PHONE key
    ]);

    expect($result)->toBe('B777');
    expect(IntegrationEvent::whereJsonContains('response_body->step', 'phone_skipped')
        ->whereJsonContains('response_body->reason', 'empty or non-E164')
        ->exists())->toBeTrue();
});

it('findOrCreateCompany uses sha256(title+postcode) as dedup key with case-insensitive match', function (): void {
    $dedupKey = hash('sha256', (string) json_encode([
        'title' => 'acme ltd',
        'postcode' => 'ec1a 1aa',
    ]));

    BitrixEntityMap::create([
        'entity_type' => BitrixEntityMap::ENTITY_COMPANY,
        'woo_id' => 0,
        'bitrix_id' => 'BC500',
        'last_payload_hash' => $dedupKey,
        'created_via' => BitrixEntityMap::VIA_PUSH,
    ]);

    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('companyUpdate')->once()->with('BC500', Mockery::any(), Mockery::any());
    $client->shouldNotReceive('companyAdd');

    $deduper = makeDeduper($client);
    $result = $deduper->findOrCreateCompany('ACME Ltd', 'EC1A 1AA', ['TITLE' => 'ACME Ltd']);

    expect($result)->toBe('BC500');
    expect(IntegrationEvent::where('endpoint', 'crm.deduper.company')
        ->whereJsonContains('response_body->step', 'map_hit')->exists())->toBeTrue();
});

it('findDealByWooOrderId returns null when not in map and not in Bitrix', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealList')
        ->once()
        ->with(['UF_CRM_WOO_ORDER_ID' => 42], ['ID'], 0, Mockery::any())
        ->andReturn([]);

    $deduper = makeDeduper($client);
    expect($deduper->findDealByWooOrderId(42))->toBeNull();

    expect(IntegrationEvent::whereJsonContains('response_body->step', 'not_found')->exists())->toBeTrue();
});

it('findDealByWooOrderId adopts Bitrix Deal into map on UF filter hit', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealList')
        ->once()
        ->andReturn([['ID' => '5001']]);

    $deduper = makeDeduper($client);
    $result = $deduper->findDealByWooOrderId(42);

    expect($result)->toBe('5001');
    expect(BitrixEntityMap::where('entity_type', 'deal')
        ->where('woo_id', 42)
        ->where('bitrix_id', '5001')
        ->where('created_via', 'push')
        ->exists())->toBeTrue();
});

it('findDealByWooOrderId adopts lowest ID and writes duplicate_detected audit on multi-match', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealList')
        ->once()
        ->andReturn([['ID' => '5002'], ['ID' => '5001']]);

    $deduper = makeDeduper($client);
    $result = $deduper->findDealByWooOrderId(99);

    expect($result)->toBe('5001'); // lowest wins

    $activity = Activity::where('description', 'bitrix.deal.duplicate_detected')->latest('id')->first();
    expect($activity)->not->toBeNull();
    $ids = $activity->properties['bitrix_deal_ids'];
    expect($ids)->toContain('5001');
    expect($ids)->toContain('5002');
});

it('normalises email case + whitespace for email_hash', function (): void {
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('duplicateFindByComm')->twice()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('contactAdd')->once()->andReturn('B888');

    $deduper = makeDeduper($client);
    $deduper->findOrCreateContact(600, [
        'NAME' => 'CaseNorm',
        'PHONE' => [['VALUE' => '+447700900222']],
        'EMAIL' => [['VALUE' => '  JANE@ACME.COM  ']],
    ]);

    $map = BitrixEntityMap::where('entity_type', 'contact')->where('woo_id', 600)->firstOrFail();
    expect($map->email_hash)->toBe(hash('sha256', 'jane@acme.com'));
});
