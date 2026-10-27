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
        'status' => 'processing',
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
        'UF_CRM_COST' => ['type' => 'money'], 'UF_CRM_PM' => ['type' => 'double'], 'UF_CRM_1610623973323' => ['type' => 'money'],
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
    expect($dealMap->last_status_snapshot)->toBe('processing');

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

function runOrderPush(PushOrderToBitrixJob $job, $client): void
{
    $job->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );
}

function expectNoBitrixCalls($client): void
{
    foreach (['companyAdd', 'contactAdd', 'contactUpdate', 'dealAdd', 'dealList', 'duplicateFindByComm'] as $method) {
        $client->shouldNotReceive($method);
    }
}

function expectCreateCalls($client, string $dealId = 'D1'): void
{
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('dealAdd')->once()->andReturn($dealId);
    $client->shouldReceive('dealList')->andReturn([]);
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
}

it('makes zero Bitrix calls for never-paid statuses on order.created', function (string $status): void {
    Queue::fake();
    $client = mockBitrixClientForPush();
    expectNoBitrixCalls($client);

    $receipt = makeOrderReceiptForPush(['status' => $status]);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    expect(BitrixEntityMap::where('entity_type', 'deal')->count())->toBe(0);
    expect(\App\Foundation\Integration\Models\IntegrationEvent::where('operation', 'crm.deal.skipped_unpaid')->count())->toBe(1);
    Queue::assertNothingPushed();
})->with(['pending', 'failed', 'cancelled', 'checkout-draft']);

it('creates the deal for a quote-request status (ywraq-new) with default config', function (): void {
    $client = mockBitrixClientForPush();
    expectCreateCalls($client);

    $receipt = makeOrderReceiptForPush(['status' => 'ywraq-new']);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    expect(BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->value('bitrix_id'))->toBe('D1');
});

it('creates the deal on the first paid order.updated (BACS paid later) without re-dispatching', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();
    expectCreateCalls($client);

    $receipt = makeOrderReceiptForPush(['status' => 'on-hold'], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    expect(BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->value('bitrix_id'))->toBe('D1');
    Queue::assertNotPushed(PushOrderToBitrixJob::class);
});

it('makes no Bitrix calls, re-queues nothing and writes no suggestion for unpaid order.updated with no map', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();
    expectNoBitrixCalls($client);

    $receipt = makeOrderReceiptForPush(['status' => 'pending'], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    Queue::assertNothingPushed();
    expect(Suggestion::where('kind', 'crm_push_failed')->count())->toBe(0);
});

it('releases the job and creates nothing when another worker holds the per-order lock', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();
    $client->shouldNotReceive('dealAdd');

    $held = Cache::lock('crm-order-push:42', 120);
    expect($held->get())->toBeTrue();

    $receipt = makeOrderReceiptForPush(['status' => 'processing']);
    $job = new PushOrderToBitrixJob($receipt->id, 'order.created', 0);
    $job->lockWaitSeconds = 1;
    $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(15);
    $job->setJob($queueJob);

    runOrderPush($job, $client);

    $held->release();
    expect(BitrixEntityMap::where('entity_type', 'deal')->count())->toBe(0);
});

it('applies the create floor to order.updated only', function (): void {
    config(['services.bitrix.order_create_floor_id' => 100]);
    Queue::fake();

    $client = mockBitrixClientForPush();
    expectNoBitrixCalls($client);
    $receipt = makeOrderReceiptForPush(['status' => 'processing'], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    expect(BitrixEntityMap::where('entity_type', 'deal')->count())->toBe(0);
    expect(\App\Foundation\Integration\Models\IntegrationEvent::where('operation', 'crm.deal.legacy_unmapped')->count())->toBe(1);

    // Same order via order.created (backfill path) is created.
    $client2 = mockBitrixClientForPush();
    expectCreateCalls($client2);
    $receipt2 = makeOrderReceiptForPush(['status' => 'processing'], 'order.created');
    runOrderPush(new PushOrderToBitrixJob($receipt2->id, 'order.created', 0), $client2);

    expect(BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->value('bitrix_id'))->toBe('D1');
});

it('never persists a SHADOW- deal id but still fires BitrixDealPushed in shadow mode', function (): void {
    Event::fake([BitrixCompanyPushed::class, BitrixContactPushed::class, BitrixDealPushed::class]);
    $client = mockBitrixClientForPush();
    expectCreateCalls($client, 'SHADOW-abc');

    $receipt = makeOrderReceiptForPush(['status' => 'processing']);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    expect(BitrixEntityMap::where('entity_type', 'deal')->count())->toBe(0);
    Event::assertDispatched(BitrixDealPushed::class, fn ($e) => $e->mode === 'shadow');
});

it('treats an existing SHADOW- deal map row as absent and overwrites it with the real id', function (): void {
    Queue::fake();
    BitrixEntityMap::factory()->dealFor(42, 'SHADOW-old')->create(['last_status_snapshot' => 'processing']);
    $client = mockBitrixClientForPush();
    expectCreateCalls($client, 'D9');

    $receipt = makeOrderReceiptForPush(['status' => 'processing'], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    expect(BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->count())->toBe(1);
    expect(BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->value('bitrix_id'))->toBe('D9');
});

it('moves an already-mapped deal on paid to cancelled (update path ignores the paid gate)', function (): void {
    Queue::fake();
    $client = mockBitrixClientForPush();
    $client->shouldNotReceive('dealAdd');
    $client->shouldReceive('companyAdd')->zeroOrMoreTimes()->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->zeroOrMoreTimes()->andReturn('C1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('dealGet')->zeroOrMoreTimes()->andReturn(['COMMENTS' => '']);
    $client->shouldReceive('dealUpdate')->zeroOrMoreTimes();

    BitrixEntityMap::factory()->dealFor(42, 'D1')->create(['last_status_snapshot' => 'processing']);
    $receipt = makeOrderReceiptForPush(['status' => 'cancelled'], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    Queue::assertPushed(UpdateDealStageJob::class, fn (UpdateDealStageJob $j) => $j->newWooStatus === 'cancelled' && $j->oldWooStatus === 'processing');
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
        'last_status_snapshot' => 'processing',
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

/*
|--------------------------------------------------------------------------
| Phase 12.1 Plan 03 — product rows, Cost / Predicted Margin (D-03..D-09, D-16)
|--------------------------------------------------------------------------
*/

/** @return array<int, array<string, mixed>> */
function costedOrderLines(): array
{
    return [
        ['id' => 1, 'name' => 'Poly X50', 'product_id' => 10, 'variation_id' => 0, 'sku' => 'SKU-A', 'quantity' => 2, 'subtotal' => '100.00', 'total' => '100.00', 'total_tax' => '20.00'],
        ['id' => 2, 'name' => 'HDMI cable', 'product_id' => 11, 'variation_id' => 0, 'sku' => 'SKU-B', 'quantity' => 1, 'subtotal' => '50.00', 'total' => '50.00', 'total_tax' => '10.00'],
    ];
}

function seedCostCodes(?string $cost = 'UF_CRM_COST', ?string $margin = 'UF_CRM_PM'): void
{
    CrmPipelineSetting::query()->first()->update(['deal_cost_field' => $cost, 'deal_predicted_margin_field' => $margin]);
}

function seedBuyPrices(array $bySku): void
{
    foreach ($bySku as $sku => $price) {
        \App\Domain\Products\Models\Product::factory()->create(['sku' => $sku, 'buy_price' => $price]);
    }
}

it('12.1-03: create writes product rows, Cost and Predicted Margin to the configured codes', function (): void {
    seedCostCodes();
    seedBuyPrices(['SKU-A' => '30.0000', 'SKU-B' => '20.5000']);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('dealList')->andReturn([]);
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $captured = null;
    $client->shouldReceive('dealAdd')->once()->withArgs(function (array $payload) use (&$captured) {
        $captured = $payload;

        return true;
    })->andReturn('1234');
    $client->shouldReceive('dealProductRowsSet')->once()->withArgs(fn ($id, $rows) => $id === 1234 && count($rows) === 2 && $rows[0]['PRODUCT_NAME'] === 'Poly X50');

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()]);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    expect($captured['UF_CRM_COST'])->toBe('80.50|GBP')
        ->and($captured['UF_CRM_PM'])->toBe('69.50')
        ->and($captured)->not->toHaveKey('UF_CRM_1610623973323');
    $map = BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->first();
    expect($map->line_items_hash)->toBe(app(\App\Domain\CRM\Services\OrderProductRowBuilder::class)->lineItemsHash(json_decode($receipt->raw_body, true)));
});

it('12.1-03: an uncosted SKU omits both fields, flags cost_incomplete, still creates the deal', function (): void {
    seedCostCodes();
    seedBuyPrices(['SKU-A' => '30.0000']);
    $client = mockBitrixClientForPush();
    expectCreateCalls($client, '77');
    $client->shouldReceive('dealProductRowsSet')->once();

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()]);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    $event = \App\Foundation\Integration\Models\IntegrationEvent::where('operation', 'crm.deal.cost_incomplete')->first();
    expect($event)->not->toBeNull()
        ->and($event->request_body['missing_skus'])->toBe(['SKU-B'])
        ->and($event->request_body['woo_order_id'])->toBe(42);
    expect(BitrixEntityMap::where('woo_id', 42)->where('entity_type', 'deal')->exists())->toBeTrue();
});

it('12.1-03: never sends the Actual Margin field even if configured', function (): void {
    seedCostCodes('UF_CRM_1610623973323', 'UF_CRM_PM');
    seedBuyPrices(['SKU-A' => '30.0000', 'SKU-B' => '20.5000']);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('dealList')->andReturn([]);
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $captured = null;
    $client->shouldReceive('dealAdd')->once()->withArgs(function (array $payload) use (&$captured) {
        $captured = $payload;

        return true;
    })->andReturn('5');
    $client->shouldReceive('dealProductRowsSet')->once();

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()]);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    expect($captured)->not->toHaveKey('UF_CRM_1610623973323')->and($captured)->not->toHaveKey('UF_CRM_PM');
    expect(\App\Foundation\Integration\Models\IntegrationEvent::where('operation', 'crm.deal.forbidden_field_skipped')->exists())->toBeTrue();
});

it('12.1-03: an adopted deal gets a baseline hash only (no rows, no cost)', function (): void {
    seedCostCodes();
    seedBuyPrices(['SKU-A' => '30.0000', 'SKU-B' => '20.5000']);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('dealList')->andReturn([['ID' => '999']]);
    $client->shouldNotReceive('dealAdd');
    $client->shouldNotReceive('dealProductRowsSet');
    $client->shouldNotReceive('dealUpdate');

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()]);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    $map = BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->first();
    expect($map)->not->toBeNull()->and($map->bitrix_id)->toBe('999')->and($map->line_items_hash)->not->toBeNull();
});

it('12.1-03: update with an unchanged hash does not touch product rows', function (): void {
    Queue::fake();
    $order = ['line_items' => costedOrderLines()];
    $hash = app(\App\Domain\CRM\Services\OrderProductRowBuilder::class)->lineItemsHash($order);
    BitrixEntityMap::factory()->dealFor(42, 'D1')->create(['line_items_hash' => $hash]);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('dealGet')->zeroOrMoreTimes()->andReturn(['COMMENTS' => '']);
    $client->shouldNotReceive('dealProductRowsSet');
    $client->shouldNotReceive('dealUpdate');

    $receipt = makeOrderReceiptForPush($order, 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    expect(true)->toBeTrue();
});

it('12.1-03: update with a changed hash re-sets rows and recomputes Cost/PM', function (): void {
    Queue::fake();
    seedCostCodes();
    seedBuyPrices(['SKU-A' => '30.0000', 'SKU-B' => '20.5000']);
    BitrixEntityMap::factory()->dealFor(42, 'D1')->create(['line_items_hash' => str_repeat('a', 64)]);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('dealGet')->zeroOrMoreTimes()->andReturn(['COMMENTS' => '']);
    $client->shouldReceive('dealProductRowsSet')->once()->withArgs(fn ($id, $rows) => is_int($id) && count($rows) === 2);
    $client->shouldReceive('dealUpdate')->once()->withArgs(fn ($id, $fields) => $id === 'D1' && $fields === ['UF_CRM_COST' => '80.50|GBP', 'UF_CRM_PM' => '69.50']);

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    expect(BitrixEntityMap::where('woo_id', 42)->value('line_items_hash'))->not->toBe(str_repeat('a', 64));
});

it('12.1-03: update with an incomplete cost blanks both fields', function (): void {
    Queue::fake();
    seedCostCodes();
    BitrixEntityMap::factory()->dealFor(42, 'D1')->create(['line_items_hash' => str_repeat('a', 64)]);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('dealGet')->zeroOrMoreTimes()->andReturn(['COMMENTS' => '']);
    $client->shouldReceive('dealProductRowsSet')->once();
    $client->shouldReceive('dealUpdate')->once()->withArgs(fn ($id, $fields) => $fields === ['UF_CRM_COST' => '', 'UF_CRM_PM' => '']);

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);
});

it('12.1-03: a legacy adopted deal with no hash gets a baseline and its rows are left alone', function (): void {
    Queue::fake();
    BitrixEntityMap::factory()->dealFor(42, 'D1')->create(['line_items_hash' => null, 'created_via' => BitrixEntityMap::VIA_ADOPTED_LEGACY]);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('dealGet')->zeroOrMoreTimes()->andReturn(['COMMENTS' => '']);
    $client->shouldNotReceive('dealProductRowsSet');
    $client->shouldNotReceive('dealUpdate');

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()], 'order.updated');
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.updated', 0), $client);

    expect(BitrixEntityMap::where('woo_id', 42)->value('line_items_hash'))->not->toBeNull();
});

it('12.1-03 D-09: a required-field rejection with blanked Cost/PM fails fast as required_field_blank', function (): void {
    seedCostCodes();
    seedBuyPrices(['SKU-A' => '30.0000']);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('dealList')->andReturn([]);
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $sent = [];
    $client->shouldReceive('dealAdd')->once()->withArgs(function (array $payload) use (&$sent) {
        $sent[] = $payload;

        return true;
    })->andThrow(new BitrixPermanentException('Required fields: Cost'));
    $client->shouldNotReceive('dealProductRowsSet');

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()]);
    $job = Mockery::mock(PushOrderToBitrixJob::class.'[fail]', [$receipt->id, 'order.created', 0])->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('fail')->once();
    runOrderPush($job, $client);

    $s = Suggestion::where('kind', 'crm_push_failed')->first();
    expect($s->payload['sub_kind'])->toBe('required_field_blank')
        ->and($s->payload['error_message'])->toContain('SKU-B')
        ->and($sent)->toHaveCount(1)
        ->and(json_encode($sent))->not->toContain('0.00|GBP');
});

it('12.1-03 D-09: the same rejection with complete financials stays permanent_validation', function (): void {
    seedCostCodes();
    seedBuyPrices(['SKU-A' => '30.0000', 'SKU-B' => '20.5000']);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('dealList')->andReturn([]);
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $client->shouldReceive('dealAdd')->once()->andThrow(new BitrixPermanentException('Required fields: Cost'));

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()]);
    $job = Mockery::mock(PushOrderToBitrixJob::class.'[fail]', [$receipt->id, 'order.created', 0])->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('fail')->once();
    runOrderPush($job, $client);

    expect(Suggestion::where('kind', 'crm_push_failed')->first()->payload['sub_kind'])->toBe('permanent_validation');
});

it('12.1-03: shadow mode sends rows with deal id 0, includes cost keys in the add payload, and persists no map', function (): void {
    seedCostCodes();
    seedBuyPrices(['SKU-A' => '30.0000', 'SKU-B' => '20.5000']);
    $client = mockBitrixClientForPush();
    $client->shouldReceive('companyAdd')->andReturn('CMP1');
    $client->shouldReceive('contactAdd')->andReturn('C1');
    $client->shouldReceive('dealList')->andReturn([]);
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);
    $captured = null;
    $client->shouldReceive('dealAdd')->once()->withArgs(function (array $payload) use (&$captured) {
        $captured = $payload;

        return true;
    })->andReturn('SHADOW-abc');
    $client->shouldReceive('dealProductRowsSet')->once()->withArgs(fn ($id, $rows) => $id === 0 && count($rows) === 2);

    $receipt = makeOrderReceiptForPush(['line_items' => costedOrderLines()]);
    runOrderPush(new PushOrderToBitrixJob($receipt->id, 'order.created', 0), $client);

    expect($captured)->toHaveKeys(['UF_CRM_COST', 'UF_CRM_PM'])
        ->and(BitrixEntityMap::where('entity_type', 'deal')->count())->toBe(0);
});
