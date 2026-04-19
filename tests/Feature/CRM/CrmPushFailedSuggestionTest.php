<?php

declare(strict_types=1);

use App\Domain\CRM\Jobs\PushOrderToBitrixJob;
use App\Domain\CRM\Models\BitrixEntityMap;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\Suggestions\Jobs\ApplySuggestionJob;
use App\Domain\Suggestions\Models\Suggestion;
use App\Domain\Webhooks\Models\WebhookReceipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 3 — end-to-end crm_push_failed replay flow
|--------------------------------------------------------------------------
|
| Failed push → crm_push_failed suggestion → ApplySuggestionJob resolves
| CrmPushRetryApplier → re-dispatches PushOrderToBitrixJob → second attempt
| succeeds → BitrixEntityMap row populated → Suggestion status=applied.
*/

function seedOrderReceipt(): WebhookReceipt
{
    return WebhookReceipt::create([
        'source' => 'woo',
        'topic' => 'order.created',
        'delivery_id' => (string) \Illuminate\Support\Str::uuid(),
        'headers' => ['x-wc-webhook-topic' => ['order.created']],
        'raw_body' => json_encode([
            'id' => 42,
            'number' => '42',
            'status' => 'pending',
            'total' => '50.00',
            'currency' => 'GBP',
            'customer_id' => 7,
            'billing' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane@example.com',
                'phone' => '+447700900111',
                'company' => '',
                'postcode' => 'SW1A 1AA',
                'country' => 'GB',
            ],
            'meta_data' => [],
        ]),
        'correlation_id' => 'cid-end-to-end',
        'received_at' => now(),
        'status' => 'accepted',
    ]);
}

function bindPermissiveClient(): \Mockery\MockInterface
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->zeroOrMoreTimes()->andReturn([
        'TITLE' => [], 'OPPORTUNITY' => [], 'CURRENCY_ID' => [], 'CONTACT_ID' => [], 'STAGE_ID' => [],
        'UF_CRM_WOO_ORDER_ID' => [], 'UF_CRM_WOO_UTM_SOURCE' => [], 'UF_CRM_WOO_UTM_MEDIUM' => [],
        'UF_CRM_WOO_UTM_CAMPAIGN' => [], 'UF_CRM_WOO_UTM_TERM' => [],
        'UF_CRM_WOO_UTM_CONTENT' => [], 'UF_CRM_WOO_GA_CID' => [], 'UF_CRM_WOO_ORDER_NUMBER' => [],
        'UF_CRM_WOO_BILLING_FIRST_NAME' => [], 'UF_CRM_WOO_BILLING_LAST_NAME' => [],
        'UF_CRM_WOO_BILLING_COMPANY' => [], 'UF_CRM_WOO_BILLING_EMAIL' => [], 'UF_CRM_WOO_BILLING_PHONE' => [],
        'UF_CRM_WOO_LINE_ITEMS_SUMMARY' => [], 'UF_CRM_WOO_PAYMENT_METHOD' => [], 'COMMENTS' => [], 'BEGINDATE' => [],
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

it('fails push then replays successfully via ApplySuggestionJob', function (): void {
    $receipt = seedOrderReceipt();
    $client = bindPermissiveClient();
    $client->shouldReceive('contactAdd')->once()->andReturn('C1');
    $client->shouldReceive('dealList')->zeroOrMoreTimes()->andReturn([]);
    $client->shouldReceive('dealAdd')->once()->andReturn('D1');
    $client->shouldReceive('duplicateFindByComm')->zeroOrMoreTimes()->andReturn(['CONTACT' => []]);

    // Step 1: directly simulate the failed() hook — it writes the suggestion.
    $job = new PushOrderToBitrixJob($receipt->id, 'order.created', 0);
    $job->failed(new \App\Domain\CRM\Exceptions\BitrixTransientException('transport timeout'));

    $suggestion = Suggestion::where('kind', 'crm_push_failed')->firstOrFail();
    expect($suggestion->status)->toBe(Suggestion::STATUS_PENDING);
    expect($suggestion->evidence['webhook_receipt_id'])->toBe($receipt->id);

    // Step 2: operator approves + replay kicks off ApplySuggestionJob.
    // Queue::fake catches the re-dispatched PushOrderToBitrixJob so we can
    // verify the applier rewired it; then we process it inline via handle().
    Queue::fake();

    (new ApplySuggestionJob($suggestion->id))->handle(
        app(\App\Domain\Suggestions\Services\SuggestionApplierResolver::class),
        app(\App\Foundation\Integration\Services\IntegrationLogger::class),
    );

    Queue::assertPushed(PushOrderToBitrixJob::class, fn (PushOrderToBitrixJob $j) => $j->webhookReceiptId === $receipt->id);

    expect($suggestion->fresh()->status)->toBe(Suggestion::STATUS_APPLIED);

    // Step 3: run the fresh job inline (Queue::fake intercepted the enqueue).
    (new PushOrderToBitrixJob($receipt->id, 'order.created', 0))->handle(
        app(\App\Domain\CRM\Services\EntityDeduper::class),
        app(\App\Domain\CRM\Services\DealPayloadBuilder::class),
        app(\App\Domain\CRM\Services\ContactPayloadBuilder::class),
        app(\App\Domain\CRM\Services\CompanyPayloadBuilder::class),
        $client,
        app(\App\Domain\CRM\Services\OrderNoteSynchroniser::class),
    );

    expect(BitrixEntityMap::where('entity_type', 'deal')->where('woo_id', 42)->exists())->toBeTrue();
});

it('end-to-end chain carries the original correlation_id from suggestion through applier result', function (): void {
    $receipt = seedOrderReceipt();

    $job = new PushOrderToBitrixJob($receipt->id, 'order.created', 0);
    $job->failed(new \App\Domain\CRM\Exceptions\BitrixTransientException('timeout'));

    $suggestion = Suggestion::where('kind', 'crm_push_failed')->firstOrFail();
    Queue::fake();

    $applier = new \App\Domain\CRM\Appliers\CrmPushRetryApplier();
    $result = $applier->apply($suggestion);

    expect($result['correlation_id'])->toBe('cid-end-to-end');
});
