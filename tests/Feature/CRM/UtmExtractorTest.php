<?php

declare(strict_types=1);

use App\Domain\CRM\Services\UtmExtractor;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 03 Task 1 — UtmExtractor
|--------------------------------------------------------------------------
|
| D-03 + D-04: parses 6 `_ms_utm_*` meta_data keys into 6 UF_CRM_WOO_* Bitrix
| fields for Deal + Contact payloads. Missing keys default to '' not null
| (Bitrix rejects null for string user_type).
*/

it('extracts all 6 UTM fields from order meta_data', function (): void {
    $payload = [
        'id' => 1,
        'meta_data' => [
            ['key' => '_ms_utm_source',   'value' => 'google'],
            ['key' => '_ms_utm_medium',   'value' => 'cpc'],
            ['key' => '_ms_utm_campaign', 'value' => 'summer_sale'],
            ['key' => '_ms_utm_term',     'value' => 'meeting+room'],
            ['key' => '_ms_utm_content',  'value' => 'ad_variant_a'],
            ['key' => '_ms_utm_ga_cid',   'value' => '1234567890.0987654321'],
        ],
    ];

    $out = (new UtmExtractor())->fromOrderPayload($payload);

    expect($out)->toBe([
        'UF_CRM_WOO_UTM_SOURCE' => 'google',
        'UF_CRM_WOO_UTM_MEDIUM' => 'cpc',
        'UF_CRM_WOO_UTM_CAMPAIGN' => 'summer_sale',
        'UF_CRM_WOO_UTM_TERM' => 'meeting+room',
        'UF_CRM_WOO_UTM_CONTENT' => 'ad_variant_a',
        'UF_CRM_WOO_GA_CID' => '1234567890.0987654321',
    ]);
});

it('defaults missing keys to empty string (never null)', function (): void {
    $payload = [
        'meta_data' => [
            ['key' => '_ms_utm_source', 'value' => 'google'],
        ],
    ];

    $out = (new UtmExtractor())->fromOrderPayload($payload);

    expect($out['UF_CRM_WOO_UTM_SOURCE'])->toBe('google');
    foreach (['UF_CRM_WOO_UTM_MEDIUM', 'UF_CRM_WOO_UTM_CAMPAIGN', 'UF_CRM_WOO_UTM_TERM', 'UF_CRM_WOO_UTM_CONTENT', 'UF_CRM_WOO_GA_CID'] as $key) {
        expect($out[$key])->toBe('');
        expect($out[$key])->not->toBeNull();
    }
});

it('emits 6 empty strings when meta_data is missing entirely', function (): void {
    $out = (new UtmExtractor())->fromOrderPayload(['id' => 1]);

    expect($out)->toHaveCount(6);
    foreach ($out as $value) {
        expect($value)->toBe('');
    }
});

it('extracts from customer payload meta_data too (D-04)', function (): void {
    $payload = [
        'id' => 99,
        'email' => 'a@b.com',
        'meta_data' => [
            ['key' => '_ms_utm_source', 'value' => 'linkedin'],
            ['key' => '_ms_utm_medium', 'value' => 'social'],
        ],
    ];

    $out = (new UtmExtractor())->fromCustomerPayload($payload);

    expect($out['UF_CRM_WOO_UTM_SOURCE'])->toBe('linkedin');
    expect($out['UF_CRM_WOO_UTM_MEDIUM'])->toBe('social');
    expect($out['UF_CRM_WOO_UTM_CAMPAIGN'])->toBe('');
});

it('ignores non-_ms_utm_ meta keys (T-04-03-01 mitigation)', function (): void {
    $payload = [
        'meta_data' => [
            ['key' => 'UF_CRM_EVIL_INJECTION', 'value' => 'pwned'],
            ['key' => '_ms_utm_source',        'value' => 'google'],
            ['key' => 'custom_arbitrary',      'value' => 'should_not_leak'],
        ],
    ];

    $out = (new UtmExtractor())->fromOrderPayload($payload);

    // Only the 6 hardcoded UF_CRM_WOO_* keys emitted. The malicious UF_CRM_EVIL_*
    // key is dropped because it doesn't match one of the 6 _ms_utm_* source keys.
    expect(array_keys($out))->toBe([
        'UF_CRM_WOO_UTM_SOURCE',
        'UF_CRM_WOO_UTM_MEDIUM',
        'UF_CRM_WOO_UTM_CAMPAIGN',
        'UF_CRM_WOO_UTM_TERM',
        'UF_CRM_WOO_UTM_CONTENT',
        'UF_CRM_WOO_GA_CID',
    ]);
    expect($out)->not->toHaveKey('UF_CRM_EVIL_INJECTION');
});
