<?php

declare(strict_types=1);

use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\IcecatClient;
use App\Foundation\Integration\Services\IntegrationLogger;

/*
|--------------------------------------------------------------------------
| Quick task 260607-g25 — IcecatClient::lookupGtinByMpn unit coverage
|--------------------------------------------------------------------------
|
| Tests the GTIN/EAN extraction path for brand+MPN → GTIN lookup. The HTTP
| layer is mocked at the protected `requestRawData()` boundary via an
| anonymous subclass — NO live Icecat traffic, NO Http::fake either.
|
| Three Icecat response shapes must be tolerated (live.icecat.biz has not
| deprecated any of them; the field present varies per product):
|   - data.GeneralInfo.GTIN            (string, e.g. "5025232931842")
|   - data.GeneralInfo.GTINs[].Value   (array of objects — newer schema)
|   - data.EANCodes[]                  (array of strings — older schema)
|
| Mirrors the SourceProductImagesCommand testing discipline (no live Icecat
| HTTP) + the 260607-9c6 runDumpCommand subclass-override pattern.
*/

/**
 * Build a test double that overrides `requestRawData()` to return a fixture
 * `data` array (or null). Exposes a public `callCount` so we can assert
 * "fixture helper NOT called" semantics (Test 4 — short-circuit on empty
 * inputs).
 */
function makeIcecatFake(?array $fixtureData): IcecatClient
{
    return new class(
        app(IntegrationCredentialResolver::class),
        app(IntegrationLogger::class),
        $fixtureData,
    ) extends IcecatClient
    {
        public int $callCount = 0;

        public function __construct(
            IntegrationCredentialResolver $resolver,
            IntegrationLogger $logger,
            private readonly ?array $fixtureData,
        ) {
            parent::__construct($resolver, $logger);
        }

        protected function requestRawData(array $creds, array $identifier): ?array
        {
            $this->callCount++;

            return $this->fixtureData;
        }

        // Bypass real credential resolution — empty array is a valid
        // "configured" creds payload from lookupGtinByMpn's perspective
        // (it only checks for null-or-not).
        protected function credentials(): ?array
        {
            return [
                'username' => 'fake-shop',
                'app_key' => 'fake-key',
                'api_token' => '',
                'content_token' => '',
            ];
        }
    };
}

it('returns the flat GTIN string from data.GeneralInfo.GTIN', function (): void {
    $fake = makeIcecatFake([
        'GeneralInfo' => [
            'GTIN' => '5025232931842',
        ],
    ]);

    $result = $fake->lookupGtinByMpn('Panasonic', 'PT-EZ770ZLE');

    expect($result)->toBe('5025232931842');
    expect($fake->callCount)->toBe(1);
});

it('returns the first GTINs[].Value when GeneralInfo.GTIN is absent', function (): void {
    $fake = makeIcecatFake([
        'GeneralInfo' => [
            'GTINs' => [
                ['Value' => '7090043790993'],
                ['Value' => '7090043790994'],
            ],
        ],
    ]);

    $result = $fake->lookupGtinByMpn('Huddly', 'S1');

    expect($result)->toBe('7090043790993');
});

it('returns the first EANCodes[] entry when GeneralInfo is absent', function (): void {
    $fake = makeIcecatFake([
        'EANCodes' => [
            '4948570123456',
            '4948570123457',
        ],
    ]);

    $result = $fake->lookupGtinByMpn('Sony', 'FW-50EZ20L');

    expect($result)->toBe('4948570123456');
});

it('returns null without an HTTP call when brand AND mpn are blank', function (): void {
    $fake = makeIcecatFake(['GeneralInfo' => ['GTIN' => '5025232931842']]);

    $result = $fake->lookupGtinByMpn('', '');

    expect($result)->toBeNull();
    expect($fake->callCount)->toBe(0);

    // Also covers null inputs:
    $result2 = $fake->lookupGtinByMpn(null, null);
    expect($result2)->toBeNull();
    expect($fake->callCount)->toBe(0);

    // Whitespace-only is also treated as empty:
    $result3 = $fake->lookupGtinByMpn('   ', "\t");
    expect($result3)->toBeNull();
    expect($fake->callCount)->toBe(0);
});

it('returns null when Icecat returns no data (product not found)', function (): void {
    $fake = makeIcecatFake(null);

    $result = $fake->lookupGtinByMpn('Panasonic', 'NOT-A-REAL-MPN');

    expect($result)->toBeNull();
    expect($fake->callCount)->toBe(1);
});

it('returns null when data is present but contains no GTIN fields', function (): void {
    $fake = makeIcecatFake([
        'GeneralInfo' => [
            'Title' => 'Some product without a GTIN',
        ],
        'Image' => ['HighPic' => 'https://example.com/x.jpg'],
        // No GTIN, no GTINs[], no EANCodes[]
    ]);

    $result = $fake->lookupGtinByMpn('Panasonic', 'PT-EZ770ZLE');

    expect($result)->toBeNull();
});

it('falls through GeneralInfo.GTIN to GTINs[] to EANCodes[] in priority order', function (): void {
    // All three shapes present — should return the FIRST (GeneralInfo.GTIN).
    $fake = makeIcecatFake([
        'GeneralInfo' => [
            'GTIN' => '1111111111111',
            'GTINs' => [['Value' => '2222222222222']],
        ],
        'EANCodes' => ['3333333333333'],
    ]);
    expect($fake->lookupGtinByMpn('Sony', 'X'))->toBe('1111111111111');

    // GeneralInfo.GTIN is empty string → fall through to GTINs[].
    $fake2 = makeIcecatFake([
        'GeneralInfo' => [
            'GTIN' => '',
            'GTINs' => [['Value' => '2222222222222']],
        ],
        'EANCodes' => ['3333333333333'],
    ]);
    expect($fake2->lookupGtinByMpn('Sony', 'X'))->toBe('2222222222222');

    // No GeneralInfo at all → EANCodes[].
    $fake3 = makeIcecatFake([
        'EANCodes' => ['3333333333333'],
    ]);
    expect($fake3->lookupGtinByMpn('Sony', 'X'))->toBe('3333333333333');
});

it('tolerates a single brand or single mpn (not both)', function (): void {
    // Only brand present — should still call (relies on Icecat brand-only match
    // for some catalogues).
    $fake = makeIcecatFake(['GeneralInfo' => ['GTIN' => '5025232931842']]);
    $result = $fake->lookupGtinByMpn('Panasonic', '');
    expect($result)->toBe('5025232931842');
    expect($fake->callCount)->toBe(1);

    // Only MPN present (Brand=null + ProductCode=mpn).
    $fake2 = makeIcecatFake(['GeneralInfo' => ['GTIN' => '5025232931842']]);
    $result2 = $fake2->lookupGtinByMpn(null, 'PT-EZ770ZLE');
    expect($result2)->toBe('5025232931842');
    expect($fake2->callCount)->toBe(1);
});
