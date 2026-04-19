<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\CRM\Events\BitrixCompanyPushed;
use App\Domain\CRM\Events\BitrixContactPushed;
use App\Domain\CRM\Events\BitrixDealPushed;
use App\Domain\CRM\Exceptions\BitrixPermanentException;
use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\CRM\Jobs\UpdateDealStageJob;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Notifications\CrmPushFailedNotification;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 2 — PushOrderToBitrixJob
|--------------------------------------------------------------------------
|
| Company → Contact → Deal sequencing + D-10 race guard + D-11 retry +
| D-12 DLQ producer + 3 domain events.
*/

function makeOrderReceiptForPush(array $orderOverrides = [], string $topic = 'order.created'): WebhookReceipt
{
    $order = array_merge([
        'id' => 42,
        'number' => '42',
        'status' => 'pending',
        'total' => '199.99',
        'currency' => 'GBP',
        'customer_id' => 7,
        'billing' => [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '+447700900111',
            'company' => 'ACME Ltd',
            'address_1' => '1 High St',
            'city' => 'London',
            'postcode' => 'SW1A 1AA',
            'country' => 'GB',
        ],
        'meta_data' => [],
    ], $orderOverrides);

    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => $topic,
        'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
        'headers' => ['x-wc-webhook-topic' => [$topic]],
        'raw_body' => json_encode($order),
        'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
        'received_at' => now(),
        'status' => 'accepted',
    ]);
}

/** Bind a BitrixClient mock that accepts any field (permissive shadow-mode-friendly). */
function mockBitrixClientForPush(): \Mockery\MockInterface
{
    $client = Mockery::mock(BitrixClient::class);
    // Permissive schema so FieldWhitelister accepts UF_CRM_* keys.
    $client->shouldReceive('dealFieldsGet')->zeroOrMoreTimes()->andReturn([
        'TITLE' => [], 'OPPORTUNITY' => [], 'CURRENCY_ID' => [], 'CATEGORY_ID' => [], 'STAGE_ID' => [],
        'CONTACT_ID' => [], 'COMPANY_ID' => [], 'COMMENTS' => [], 'BEGINDATE' => [], 'ASSIGNED_BY_ID' => [],
        'UF_CRM_WOO_ORDER_ID' => [], 'UF_CRM_WOO_ORDER_NUMBER' => [], 'UF_CRM_WOO_BILLING_FIRST_NAME' => [],
        'UF_CRM_WOO_BILLING_LAST_NAME' => [], 'UF_CRM_WOO_BILLING_COMPANY' => [], 'UF_CRM_WOO_BILLING_EMAIL' => [],
        'UF_CRM_WOO_BILLING_PHONE' => [], 'UF_CRM_WOO_LINE_ITEMS_SUMMARY' => [], 'UF_CRM_WOO_PAYMENT_METHOD' => [],
        'UF_CRM_WOO_UTM_SOURCE' => [], 'UF_CRM_WOO_UTM_MEDIUM' => [], 'UF_CRM_WOO_UTM_CAMPAIGN' => [],
        'UF_CRM_WOO_UTM_TERM' => [], 'UF_CRM_WOO_UTM_CONTENT' => [], 'UF_CRM_WOO_GA_CID' => [],
    ]);
    $client->shouldReceive('contactFieldsGet')->zeroOrMoreTimes()->andReturn([
        'NAME' => [], 'LAST_NAME' => [], 'EMAIL' => [], 'PHONE' => [],
        'ADDRESS' => [], 'ADDRESS_2' => [], 'ADDRESS_CITY' => [], 'ADDRESS_POSTAL_CODE' => [], 'ADDRESS_COUNTRY' => [],
        'UF_CRM_WOO_CUSTOMER_ID' => [], 'UF_CRM_WOO_UTM_SOURCE' => [], 'UF_CRM_WOO_UTM_MEDIUM' => [],
        'UF_CRM_WOO_UTM_CAMPAIGN' => [], 'UF_CRM_WOO_UTM_TERM' => [], 'UF_CRM_WOO_UTM_CONTENT' => [],
        'UF_CRM_WOO_GA_CID' => [],
    ]);
    $client->shouldReceive('companyFieldsGet')->zeroOrMoreTimes()->andReturn([
        'TITLE' => [], 'ADDRESS' => [], 'ADDRESS_2' => [], 'ADDRESS_CITY' => [],
        'ADDRESS_POSTAL_CODE' => [], 'ADDRESS_COUNTRY' => [], 'UF_CRM_COMPANY_VAT' => [],
    ]);

    // Field-schema cache in the app is singleton-resolved; container rebuild needed.
    Cache::flush();
    app()->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);
    app()->instance(BitrixClient::class, $client);
    app()->forgetInstance(\App\Domain\CRM\Services\BitrixSchemaCache::class);

    return $client;
}

beforeEach(function (): void {
    config(['services.bitrix.write_enabled' => true]);
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);
    $this->seed(\Database\Seeders\Phase4\CrmStatusMappingSeeder::class);
});

it('creates Company → Contact → Deal sequence on order.created', function (): void {
    Event::fake([BitrixCompanyPushed::class, BitrixContactPushed::class, BitrixDealPushed::class]);

    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->once()->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->once()->andReturn('C1');
    $client->shouldReceive('dealAdd')->once()->andReturn('D1');
    // EntityDeduper's dealList lookup during findDealByWooOrderId
    $client->shouldReceive('dealList')->once()->andReturn([]);
    // EntityDeduper phone/email dedup calls return no match → create
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);

    $receipt = makeOrderReceiptForPush();

    (new PushOrderToBitrixJob($receipt->id, 'order.created', 0))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    expect(BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->exists())->toBeTrue();
    $dealMap = BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->first();
    expect($dealMap->bitrix_id)->toBe('D1');
    expect($dealMap->last_status_snapshot)->toBe('pending');

    Event::assertDispatched(BitrixCompanyPushed::class);
    Event::assertDispatched(BitrixContactPushed::class);
    Event::assertDispatched(BitrixDealPushed::class, fn ($e) => $e->mode === 'created');
});

it('skips Company step when billing.company is empty', function (): void {
    Event::fake([BitrixCompanyPushed::class, BitrixContactPushed::class, BitrixDealPushed::class]);

    $client = mockBitrixClientForPush();
    $client->shouldNotReceive('companyAdd');
    $client->shouldReceive('contactAdd')->once()->andReturn('C1');
    $client->shouldReceive('dealAdd')->once()->andReturn('D1');
    $client->shouldReceive('dealList')->once()->andReturn([]);
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);

    $receipt = makeOrderReceiptForPush(['billing' => [
        'first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'j@e.com',
        'phone' => '+447700900111', 'company' => '', 'postcode' => 'SW1A 1AA', 'country' => 'GB',
    ]]);

    (new PushOrderToBitrixJob($receipt->id, 'order.created', 0))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    Event::assertNotDispatched(BitrixCompanyPushed::class);
    Event::assertDispatched(BitrixContactPushed::class);
    Event::assertDispatched(BitrixDealPushed::class);
});

it('on order.updated with missing map and retries < 5, release(30) re-queues itself', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();

    $receipt = makeOrderReceiptForPush([], 'order.updated');

    (new PushOrderToBitrixJob($receipt->id, 'order.updated', 2))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    Queue::assertPushed(PushOrderToBitrixJob::class, function (PushOrderToBitrixJob $job) use ($receipt) {
        return $job->webhookReceiptId === $receipt->id
            && $job->topic === 'order.updated'
            && $job->updateMissRetries === 3;
    });
    // No suggestion yet — still retrying.
    expect(Suggestion::where('kind', 'crm_push_failed')->count())->toBe(0);
});

it('on order.updated with missing map after 5 retries, writes update_before_create suggestion', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();

    $receipt = makeOrderReceiptForPush([], 'order.updated');

    (new PushOrderToBitrixJob($receipt->id, 'order.updated', 5))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    Queue::assertNotPushed(PushOrderToBitrixJob::class);
    $s = Suggestion::where('kind', 'crm_push_failed')->first();
    expect($s)->not->toBeNull();
    expect($s->payload['sub_kind'])->toBe('update_before_create');
    expect($s->payload['woo_id'])->toBe(42);
});

it('on order.updated with map and changed status, dispatches UpdateDealStageJob', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();
    $client->shouldReceive('contactAdd')->once()->andReturn('C1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('companyAdd')->once()->andReturn('CMP1');
    $client->shouldReceive('dealGet')->zeroOrMoreTimes()->andReturn(['COMMENTS' => '']);
    $client->shouldReceive('dealUpdate')->zeroOrMoreTimes();

    BitrixEntityMap::factory()->dealFor(42, 'D1')->create([
        'last_status_snapshot' => 'pending',
    ]);
    $receipt = makeOrderReceiptForPush(['status' => 'processing'], 'order.updated');

    (new PushOrderToBitrixJob($receipt->id, 'order.updated', 0))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    Queue::assertPushed(UpdateDealStageJob::class, function (UpdateDealStageJob $job) {
        return $job->wooOrderId === 42
            && $job->newWooStatus === 'processing'
            && $job->oldWooStatus === 'pending';
    });
});

it('on order.updated with same status, does NOT dispatch UpdateDealStageJob', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();
    $client->shouldReceive('contactAdd')->once()->andReturn('C1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('companyAdd')->once()->andReturn('CMP1');
    $client->shouldReceive('dealGet')->zeroOrMoreTimes()->andReturn(['COMMENTS' => '']);
    $client->shouldReceive('dealUpdate')->zeroOrMoreTimes();

    BitrixEntityMap::factory()->dealFor(42, 'D1')->create([
        'last_status_snapshot' => 'pending',
    ]);
    $receipt = makeOrderReceiptForPush(['status' => 'pending'], 'order.updated');

    (new PushOrderToBitrixJob($receipt->id, 'order.updated', 0))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    Queue::assertNotPushed(UpdateDealStageJob::class);
});

it('writes crm_push_failed suggestion (permanent_validation) on BitrixPermanentException and fails fast', function (): void {
    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->zeroOrMoreTimes()->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->zeroOrMoreTimes()->andThrow(new BitrixPermanentException('Invalid email field'));
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);

    $receipt = makeOrderReceiptForPush();

    $job = Mockery::mock(PushOrderToBitrixJob::class.'[fail]', [$receipt->id, 'order.created', 0])
        ->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('fail')->once();

    $job->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    $s = Suggestion::where('kind', 'crm_push_failed')->first();
    expect($s)->not->toBeNull();
    expect($s->payload['sub_kind'])->toBe('permanent_validation');
});

it('failed() hook writes push_exhausted suggestion and dispatches AlertDistribution with 5-min dedup', function (): void {
    Notification::fake();
    Cache::flush();
    AlertRecipient::create([
        'email' => 'crm-ops@example.com',
        'name' => 'CRM Ops',
        'is_active' => true,
        'receives_crm_alerts' => true,
    ]);

    $receipt = makeOrderReceiptForPush();
    $job = new PushOrderToBitrixJob($receipt->id, 'order.created', 0);

    // First fail() → one suggestion + one notification
    $job->failed(new \RuntimeException('transport timeout'));

    expect(Suggestion::where('kind', 'crm_push_failed')->count())->toBe(1);
    $s = Suggestion::where('kind', 'crm_push_failed')->first();
    expect($s->payload['sub_kind'])->toBe('push_exhausted');

    Notification::assertSentTimes(CrmPushFailedNotification::class, 1);

    // Second fail() within 5 min → another suggestion BUT no duplicate notification.
    $job2 = new PushOrderToBitrixJob($receipt->id, 'order.created', 0);
    $job2->failed(new \RuntimeException('still failing'));

    expect(Suggestion::where('kind', 'crm_push_failed')->count())->toBe(2);
    Notification::assertSentTimes(CrmPushFailedNotification::class, 1); // still only 1
});
