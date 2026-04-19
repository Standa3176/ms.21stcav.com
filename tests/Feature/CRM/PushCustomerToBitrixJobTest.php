<?php

declare(strict_types=1);

use App\Domain\CRM\Events\BitrixContactPushed;
use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Jobs\PushCustomerToBitrixJob;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 2 — PushCustomerToBitrixJob
|--------------------------------------------------------------------------
|
| Contact-only upsert for customer.* webhooks. D-04 Contact-level UTM capture.
*/

function makeCustomerReceiptForPush(array $overrides = [], string $topic = 'customer.created'): WebhookReceipt
{
    $payload = array_merge([
        'id' => 7,
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'billing' => [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => '+447700900111',
            'address_1' => '1 High St',
            'city' => 'London',
            'postcode' => 'SW1A 1AA',
            'country' => 'GB',
        ],
        'meta_data' => [
            ['key' => '_ms_utm_source', 'value' => 'linkedin'],
            ['key' => '_ms_utm_medium', 'value' => 'social'],
        ],
    ], $overrides);

    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => $topic,
        'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
        'headers' => ['x-wc-webhook-topic' => [$topic]],
        'raw_body' => json_encode($payload),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'accepted',
    ]);
}

function mockContactClient(): \Mockery\MockInterface
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('contactFieldsGet')->zeroOrMoreTimes()->andReturn([
        'NAME' => [], 'LAST_NAME' => [], 'EMAIL' => [], 'PHONE' => [],
        'ADDRESS' => [], 'ADDRESS_2' => [], 'ADDRESS_CITY' => [], 'ADDRESS_POSTAL_CODE' => [], 'ADDRESS_COUNTRY' => [],
        'UF_CRM_WOO_CUSTOMER_ID' => [], 'UF_CRM_WOO_UTM_SOURCE' => [], 'UF_CRM_WOO_UTM_MEDIUM' => [],
        'UF_CRM_WOO_UTM_CAMPAIGN' => [], 'UF_CRM_WOO_UTM_TERM' => [], 'UF_CRM_WOO_UTM_CONTENT' => [],
        'UF_CRM_WOO_GA_CID' => [],
    ]);
    Cache::flush();
    app()->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);
    app()->instance(BitrixClient::class, $client);
    app()->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);

    return $client;
}

beforeEach(function (): void {
    config(['services.bitrix.write_enabled' => true]);
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);
});

it('upserts Contact and fires BitrixContactPushed with UTM fields from D-04', function (): void {
    Event::fake([BitrixContactPushed::class]);
    $client = mockContactClient();

    $capturedPayload = null;
    $client->shouldReceive('contactAdd')
        ->once()
        ->with(Mockery::capture($capturedPayload), Mockery::any())
        ->andReturn('C99');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);

    $receipt = makeCustomerReceiptForPush();

    (new PushCustomerToBitrixJob($receipt->id, 'customer.created'))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
    );

    expect($capturedPayload)->toBeArray();
    expect($capturedPayload['NAME'])->toBe('Jane');
    expect($capturedPayload['UF_CRM_WOO_UTM_SOURCE'])->toBe('linkedin');
    expect($capturedPayload['UF_CRM_WOO_UTM_MEDIUM'])->toBe('social');

    Event::assertDispatched(BitrixContactPushed::class, fn ($e) => $e->wooCustomerId === 7 && $e->bitrixContactId === 'C99');
});

it('skips dispatch when customer_id is missing (0)', function (): void {
    $client = mockContactClient();
    $client->shouldNotReceive('contactAdd');

    $receipt = makeCustomerReceiptForPush(['id' => 0]);

    (new PushCustomerToBitrixJob($receipt->id, 'customer.created'))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
    );

    // No exception, no contactAdd called.
    expect(true)->toBeTrue();
});

it('writes crm_push_failed suggestion on BitrixPermanentException and fails fast', function (): void {
    $client = mockContactClient();
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('contactAdd')->andThrow(new BitrixPermanentException('invalid email'));

    $receipt = makeCustomerReceiptForPush();

    $job = Mockery::mock(PushCustomerToBitrixJob::class.'[fail]', [$receipt->id, 'customer.created'])
        ->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('fail')->once();

    $job->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
    );

    $s = Suggestion::where('kind', 'crm_push_failed')->first();
    expect($s)->not->toBeNull();
    expect($s->payload['sub_kind'])->toBe('permanent_validation');
    expect($s->payload['entity_type'])->toBe('contact');
});
