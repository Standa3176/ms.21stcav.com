<?php

declare(strict_types=1);

use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Services\BitrixSchemaCache;
use App\Domain\CRM\Services\DealPayloadBuilder;
use App\Domain\CRM\Services\UtmExtractor;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Foundation\Integration\Services\IntegrationLogger;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 1 — DealPayloadBuilder
|--------------------------------------------------------------------------
|
| Builds a crm.deal.add payload from a Woo order. Covers:
|   - TITLE shortcode resolution from CrmPipelineSetting.deal_title_template
|   - CATEGORY_ID + STAGE_ID (status-map wins over landing_stage_id)
|   - UF_CRM_WOO_ORDER_ID + OPPORTUNITY
|   - 6 UTM fields from UtmExtractor (D-03)
|   - CrmFieldMapping overrides with transformers (phone_e164, uppercase, join_line_items)
|   - Stale UF_CRM_* mapping skipped + integration_events audit row
*/

/** Schema cache stub that accepts every UF_CRM_* field by default. */
function permissiveSchemaCache(): BitrixSchemaCache
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->zeroOrMoreTimes()->andReturn([
        'TITLE' => [], 'OPPORTUNITY' => [], 'CURRENCY_ID' => [], 'CATEGORY_ID' => [],
        'STAGE_ID' => [], 'CONTACT_ID' => [], 'COMPANY_ID' => [], 'COMMENTS' => [],
        'BEGINDATE' => [], 'ASSIGNED_BY_ID' => [],
        'UF_CRM_WOO_ORDER_ID' => [], 'UF_CRM_WOO_ORDER_NUMBER' => [],
        'UF_CRM_WOO_UTM_SOURCE' => [], 'UF_CRM_WOO_UTM_MEDIUM' => [], 'UF_CRM_WOO_UTM_CAMPAIGN' => [],
        'UF_CRM_WOO_UTM_TERM' => [], 'UF_CRM_WOO_UTM_CONTENT' => [], 'UF_CRM_WOO_GA_CID' => [],
        'UF_CRM_WOO_BILLING_FIRST_NAME' => [], 'UF_CRM_WOO_BILLING_LAST_NAME' => [],
        'UF_CRM_WOO_BILLING_COMPANY' => [], 'UF_CRM_WOO_BILLING_EMAIL' => [],
        'UF_CRM_WOO_BILLING_PHONE' => [], 'UF_CRM_WOO_LINE_ITEMS_SUMMARY' => [],
        'UF_CRM_WOO_PAYMENT_METHOD' => [],
    ]);
    \Illuminate\Support\Facades\Cache::flush();

    return new BitrixSchemaCache($client, app(IntegrationLogger::class));
}

function makeDealBuilder(?BitrixSchemaCache $schema = null): DealPayloadBuilder
{
    return new DealPayloadBuilder(
        new UtmExtractor(),
        $schema ?? permissiveSchemaCache(),
        app(IntegrationLogger::class),
    );
}

it('resolves TITLE template with shortcodes', function (): void {
    CrmPipelineSetting::query()->update(['deal_title_template' => 'Order #{order_number} — {billing_last_name}']);

    $order = [
        'id' => 42,
        'number' => '12345',
        'total' => '100.00',
        'currency' => 'gbp',
        'billing' => ['first_name' => 'Jane', 'last_name' => 'Smith'],
    ];

    $payload = makeDealBuilder()->build($order, 'C1', '', null);

    expect($payload['TITLE'])->toBe('Order #12345 — Smith');
    expect($payload['UF_CRM_WOO_ORDER_ID'])->toBe(42);
    expect($payload['OPPORTUNITY'])->toBe(100.0);
    expect($payload['CURRENCY_ID'])->toBe('GBP');
    expect($payload['CONTACT_ID'])->toBe('C1');
});

it('merges 6 UTM fields from UtmExtractor into payload', function (): void {
    $order = [
        'id' => 1, 'total' => '0',
        'billing' => ['first_name' => 'X', 'last_name' => 'Y'],
        'meta_data' => [
            ['key' => '_ms_utm_source', 'value' => 'google'],
            ['key' => '_ms_utm_medium', 'value' => 'cpc'],
            ['key' => '_ms_utm_campaign', 'value' => 'spring_2026'],
        ],
    ];

    $payload = makeDealBuilder()->build($order, 'C1', '', null);

    expect($payload['UF_CRM_WOO_UTM_SOURCE'])->toBe('google');
    expect($payload['UF_CRM_WOO_UTM_MEDIUM'])->toBe('cpc');
    expect($payload['UF_CRM_WOO_UTM_CAMPAIGN'])->toBe('spring_2026');
    expect($payload['UF_CRM_WOO_UTM_TERM'])->toBe('');
});

it('CrmStatusMapping.bitrix_stage_id wins over pipeline landing_stage_id', function (): void {
    CrmPipelineSetting::query()->update([
        'bitrix_pipeline_id' => '5',
        'landing_stage_id' => 'C5:NEW',
    ]);
    CrmStatusMapping::create([
        'woo_status' => 'processing',
        'bitrix_stage_id' => 'C5:PREP',
        'bitrix_stage_label' => 'Processing',
    ]);

    $order = [
        'id' => 1, 'total' => '0', 'status' => 'processing',
        'billing' => ['first_name' => 'X', 'last_name' => 'Y'],
    ];

    $payload = makeDealBuilder()->build($order, 'C1', '', null);

    expect($payload['CATEGORY_ID'])->toBe('5');
    expect($payload['STAGE_ID'])->toBe('C5:PREP');  // status-map wins
});

it('falls back to landing_stage_id when CrmStatusMapping has no stage_id', function (): void {
    CrmPipelineSetting::query()->update([
        'bitrix_pipeline_id' => '5',
        'landing_stage_id' => 'C5:NEW',
    ]);
    // Leave status_mappings unchanged — rows exist but bitrix_stage_id null.

    $order = [
        'id' => 1, 'total' => '0', 'status' => 'pending',
        'billing' => ['first_name' => 'X', 'last_name' => 'Y'],
    ];

    $payload = makeDealBuilder()->build($order, 'C1', '', null);

    expect($payload['STAGE_ID'])->toBe('C5:NEW');
});

it('applies CrmFieldMapping phone_e164 transformer to billing.phone (UF_CRM_WOO_BILLING_PHONE)', function (): void {
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);

    $order = [
        'id' => 1, 'total' => '0',
        'billing' => ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '0044 7700 900111'],
    ];

    $payload = makeDealBuilder()->build($order, 'C1', '', null);

    expect($payload)->toHaveKey('UF_CRM_WOO_BILLING_PHONE');
    expect($payload['UF_CRM_WOO_BILLING_PHONE'])->toBe('+447700900111');
});

it('applies join_line_items transformer to UF_CRM_WOO_LINE_ITEMS_SUMMARY', function (): void {
    $this->seed(\Database\Seeders\Phase4\CrmFieldMappingSeeder::class);

    $order = [
        'id' => 1, 'total' => '0',
        'billing' => ['first_name' => 'X', 'last_name' => 'Y'],
        'line_items' => [
            ['name' => 'Logitech Rally Bar', 'quantity' => 2],
            ['name' => 'HDMI Cable 3m', 'quantity' => 1],
        ],
    ];

    $payload = makeDealBuilder()->build($order, 'C1', '', null);

    expect($payload['UF_CRM_WOO_LINE_ITEMS_SUMMARY'])->toBe('Logitech Rally Bar × 2; HDMI Cable 3m');
});

it('skips mapped UF_CRM_* key not in schema and logs integration_events stale_mapping_skipped', function (): void {
    // Narrow schema: only permit 10 fields; UF_CRM_WOO_BILLING_COMPANY NOT in schema.
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->once()->andReturn([
        'TITLE' => [], 'OPPORTUNITY' => [], 'CURRENCY_ID' => [], 'CONTACT_ID' => [],
        'UF_CRM_WOO_ORDER_ID' => [], 'UF_CRM_WOO_UTM_SOURCE' => [], 'UF_CRM_WOO_UTM_MEDIUM' => [],
        'UF_CRM_WOO_UTM_CAMPAIGN' => [], 'UF_CRM_WOO_UTM_TERM' => [],
        'UF_CRM_WOO_UTM_CONTENT' => [], 'UF_CRM_WOO_GA_CID' => [],
    ]);
    \Illuminate\Support\Facades\Cache::flush();
    $schema = new BitrixSchemaCache($client, app(IntegrationLogger::class));

    // Mapping points at UF_CRM_WOO_BILLING_COMPANY which isn't in the schema above.
    CrmFieldMapping::create([
        'entity_type' => 'deal',
        'woo_field' => 'billing.company',
        'bitrix_field' => 'UF_CRM_WOO_BILLING_COMPANY',
        'is_custom' => true,
        'transformer' => 'none',
    ]);

    $order = [
        'id' => 1, 'total' => '0',
        'billing' => ['first_name' => 'X', 'last_name' => 'Y', 'company' => 'ACME Ltd'],
    ];

    $payload = makeDealBuilder($schema)->build($order, 'C1', '', 'cid-123');

    expect($payload)->not->toHaveKey('UF_CRM_WOO_BILLING_COMPANY');
    expect(IntegrationEvent::where('endpoint', 'crm.deal.builder')
        ->whereJsonContains('response_body->step', 'stale_mapping_skipped')
        ->exists())->toBeTrue();
});

it('emits CONTACT_ID + COMPANY_ID when both provided; omits COMPANY_ID when empty string', function (): void {
    $order = ['id' => 1, 'total' => '0', 'billing' => ['first_name' => 'X', 'last_name' => 'Y']];

    $withCompany = makeDealBuilder()->build($order, 'C1', 'CMP1', null);
    $withoutCompany = makeDealBuilder()->build($order, 'C1', '', null);

    expect($withCompany['COMPANY_ID'])->toBe('CMP1');
    expect($withoutCompany)->not->toHaveKey('COMPANY_ID');
    expect($withoutCompany['CONTACT_ID'])->toBe('C1');
});

/*
|--------------------------------------------------------------------------
| Phase 12.1 Plan 02 Task 2 — static fields, meta: mappings, forbidden targets
|--------------------------------------------------------------------------
*/

/** Schema cache that also knows the plugin-parity UF_CRM_* codes. */
function parityBuilder(): DealPayloadBuilder
{
    $client = Mockery::mock(BitrixClient::class);
    $client->shouldReceive('dealFieldsGet')->zeroOrMoreTimes()->andReturn(array_fill_keys([
        'TITLE', 'OPPORTUNITY', 'CURRENCY_ID', 'CATEGORY_ID', 'STAGE_ID', 'CONTACT_ID', 'COMPANY_ID',
        'COMMENTS', 'ASSIGNED_BY_ID', 'UF_CRM_WOO_ORDER_ID', 'UF_CRM_1609669335792', 'UF_CRM_1624008544289',
        'UF_CRM_1610623973323', 'UF_CRM_COST', 'UF_CRM_PM', 'UF_CRM_1621415435354',
    ], []));
    \Illuminate\Support\Facades\Cache::flush();

    return makeDealBuilder(new BitrixSchemaCache($client, app(IntegrationLogger::class)));
}

it('merges deal_static_fields with placeholder support', function (): void {
    CrmPipelineSetting::current()->update(['deal_static_fields' => [
        'TYPE_ID' => 'GOODS', 'OPENED' => 'Y', 'SOURCE_ID' => 'RC_GENERATOR', 'SOURCE_DESCRIPTION' => 'Woo #{order_number}',
    ]]);

    $payload = makeDealBuilder()->build(['id' => 1, 'number' => '42', 'total' => '0'], 'C1', '', 'corr-1');

    expect($payload)->toMatchArray([
        'TYPE_ID' => 'GOODS', 'OPENED' => 'Y', 'SOURCE_ID' => 'RC_GENERATOR', 'SOURCE_DESCRIPTION' => 'Woo #42',
    ]);
});

it('static fields never override deterministic base keys', function (): void {
    CrmPipelineSetting::current()->update([
        'bitrix_pipeline_id' => '0', 'assigned_user_id' => '6428', 'landing_stage_id' => 'NEW',
        'deal_static_fields' => [
            'TITLE' => 'HIJACK', 'OPPORTUNITY' => 1, 'CURRENCY_ID' => 'USD', 'UF_CRM_WOO_ORDER_ID' => 9,
            'CONTACT_ID' => 'X', 'COMPANY_ID' => 'X', 'CATEGORY_ID' => '9', 'STAGE_ID' => 'X', 'ASSIGNED_BY_ID' => '1',
        ],
    ]);

    $payload = makeDealBuilder()->build(['id' => 7, 'number' => '7', 'total' => '5.00', 'currency' => 'gbp'], 'C1', 'CO1', 'corr-1');

    expect($payload['TITLE'])->toBe('Woo Order #7')
        ->and($payload['OPPORTUNITY'])->toBe(5.0)
        ->and($payload['CURRENCY_ID'])->toBe('GBP')
        ->and($payload['UF_CRM_WOO_ORDER_ID'])->toBe(7)
        ->and($payload['CONTACT_ID'])->toBe('C1')
        ->and($payload['COMPANY_ID'])->toBe('CO1')
        ->and($payload['CATEGORY_ID'])->toBe('0')
        ->and($payload['STAGE_ID'])->toBe('NEW')
        ->and($payload['ASSIGNED_BY_ID'])->toBe('6428');
});

it('renders the plugin title template and sends CATEGORY_ID 0 for the default pipeline', function (): void {
    CrmPipelineSetting::current()->update([
        'bitrix_pipeline_id' => '0',
        'deal_title_template' => 'BS - #{order_number} - ({billing_company})',
    ]);

    $payload = makeDealBuilder()->build([
        'id' => 185152, 'number' => '185152', 'total' => '1', 'billing' => ['company' => 'ACME'],
    ], 'C1', '', 'corr-1');

    expect($payload['TITLE'])->toBe('BS - #185152 - (ACME)')
        ->and($payload)->toHaveKey('CATEGORY_ID')
        ->and($payload['CATEGORY_ID'])->toBe('0');
});

it('resolves meta: mappings from order meta_data and omits absent meta', function (): void {
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'meta:utm_source', 'bitrix_field' => 'UTM_SOURCE', 'is_custom' => false, 'transformer' => 'none', 'sort_order' => 0]);
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'meta:wciwtb_streaming', 'bitrix_field' => 'UF_CRM_1609669335792', 'is_custom' => true, 'transformer' => 'none', 'sort_order' => 1]);
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'meta:utm_term', 'bitrix_field' => 'UTM_TERM', 'is_custom' => false, 'transformer' => 'none', 'sort_order' => 2]);

    $payload = parityBuilder()->build([
        'id' => 1, 'total' => '0',
        'meta_data' => [
            ['key' => 'utm_source', 'value' => 'google'],
            ['key' => 'wciwtb_streaming', 'value' => 'yes'],
        ],
    ], 'C1', '', 'corr-1');

    expect($payload['UTM_SOURCE'])->toBe('google')
        ->and($payload['UF_CRM_1609669335792'])->toBe('yes')
        ->and($payload)->not->toHaveKey('UTM_TERM');
});

it('still resolves dotted-path mappings such as shipping.postcode', function (): void {
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'shipping.postcode', 'bitrix_field' => 'UF_CRM_1624008544289', 'is_custom' => true, 'transformer' => 'none', 'sort_order' => 0]);

    $payload = parityBuilder()->build(['id' => 1, 'total' => '0', 'shipping' => ['postcode' => 'SW1A 1AA']], 'C1', '', 'corr-1');

    expect($payload['UF_CRM_1624008544289'])->toBe('SW1A 1AA');
});

it('drops Actual Margin from static fields and mappings and logs forbidden_field_skipped', function (): void {
    CrmPipelineSetting::current()->update(['deal_static_fields' => ['UF_CRM_1610623973323' => '99', 'OPENED' => 'Y']]);
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'meta:am', 'bitrix_field' => 'UF_CRM_1610623973323', 'is_custom' => true, 'transformer' => 'none', 'sort_order' => 0]);

    $payload = parityBuilder()->build(['id' => 1, 'total' => '0', 'meta_data' => [['key' => 'am', 'value' => '5']]], 'C1', '', 'corr-1');

    expect($payload)->not->toHaveKey('UF_CRM_1610623973323')
        ->and($payload['OPENED'])->toBe('Y')
        ->and(IntegrationEvent::query()->where('operation', 'crm.deal.forbidden_field_skipped')->count())->toBe(2);
});

it('drops configured Cost / Predicted Margin codes from static fields and mappings', function (): void {
    CrmPipelineSetting::current()->update([
        'deal_cost_field' => 'UF_CRM_COST',
        'deal_predicted_margin_field' => 'UF_CRM_PM',
        'deal_static_fields' => ['UF_CRM_COST' => '1', 'OPENED' => 'Y'],
    ]);
    CrmFieldMapping::create(['entity_type' => 'deal', 'woo_field' => 'meta:pm', 'bitrix_field' => 'UF_CRM_PM', 'is_custom' => true, 'transformer' => 'none', 'sort_order' => 0]);

    $payload = parityBuilder()->build(['id' => 1, 'total' => '0', 'meta_data' => [['key' => 'pm', 'value' => '5']]], 'C1', '', 'corr-1');

    expect($payload)->not->toHaveKey('UF_CRM_COST')
        ->and($payload)->not->toHaveKey('UF_CRM_PM')
        ->and($payload['OPENED'])->toBe('Y');
});
