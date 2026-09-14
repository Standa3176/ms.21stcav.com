<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\IcecatClient;

/*
|--------------------------------------------------------------------------
| Quick task 260914-j0x — Icecat as the grounding source
|--------------------------------------------------------------------------
|
| WHY generated copy was thin: supplier_products has FOURTEEN columns and not
| one holds prose — sku, title, manufacturer, mpn, stock, price, rrp, ean and
| sync metadata. detectDetailColumns() therefore returns [] for every supplier,
| permanently, and the model was describing products from a part number.
|
| Icecat has the prose, and IcecatClient was ALREADY fetching the whole record
| for image URLs and discarding the rest. Measured 2026-09-14 across 30 random
| published products: 21 (70%) return data, descriptions to 6,042 characters,
| up to 19 spec groups.
|
| It had been switched off since 2026-05-24 because testConnection() probed one
| Sony GTIN, Sony is brand-restricted on an Open Icecat account, and the reply
| ("To access Full Icecat content, an app_key is required") matched an auth
| regex on both `access` and `app_key`. 113 days of a working free integration
| disabled by one over-broad pattern.
*/

/** Reach a private method without a live HTTP call. */
function icecatInvoke(string $method, mixed ...$args): mixed
{
    $m = new ReflectionMethod(IcecatClient::class, $method);

    return $m->invoke(app(IcecatClient::class), ...$args);
}

it('prefers the longest real prose Icecat offers', function (): void {
    $data = ['GeneralInfo' => [
        'Description' => ['LongDesc' => '<p>Full long description with <b>markup</b>.</p>'],
        'SummaryDescription' => ['LongSummaryDescription' => 'A shorter summary.'],
    ]];

    expect(icecatInvoke('extractDescription', $data))
        ->toBe('Full long description with markup.');
});

it('falls back through the shapes when LongDesc is absent', function (): void {
    // Real products carry different subsets — LH85WMBWLGCXEN returned 0
    // description characters but 11 feature groups.
    $data = ['GeneralInfo' => [
        'SummaryDescription' => ['ShortSummaryDescription' => 'Only a short summary exists.'],
    ]];

    expect(icecatInvoke('extractDescription', $data))->toBe('Only a short summary exists.');
});

it('returns an empty string, not null, when there is no prose at all', function (): void {
    expect(icecatInvoke('extractDescription', ['GeneralInfo' => []]))->toBe('')
        ->and(icecatInvoke('extractDescription', []))->toBe('');
});

it('flattens feature groups into name => value specs', function (): void {
    // The specs are what let the model write "VESA 400x400, 45kg" instead of
    // "professional mounting solution".
    $data = ['FeaturesGroups' => [
        ['Features' => [
            ['Feature' => ['Name' => ['Value' => 'VESA mounting']], 'PresentationValue' => '400 x 400 mm'],
            ['Feature' => ['Name' => ['Value' => 'Maximum weight']], 'PresentationValue' => '45 kg'],
        ]],
        ['Features' => [
            ['Feature' => ['Name' => ['Value' => 'Colour']], 'PresentationValue' => 'Black'],
        ]],
    ]];

    expect(icecatInvoke('extractFeatures', $data))->toBe([
        'VESA mounting' => '400 x 400 mm',
        'Maximum weight' => '45 kg',
        'Colour' => 'Black',
    ]);
});

it('skips features missing either a name or a value', function (): void {
    $data = ['FeaturesGroups' => [['Features' => [
        ['Feature' => ['Name' => ['Value' => 'Good']], 'PresentationValue' => 'yes'],
        ['Feature' => ['Name' => ['Value' => '']], 'PresentationValue' => 'orphan value'],
        ['Feature' => ['Name' => ['Value' => 'No value']], 'PresentationValue' => ''],
    ]]]];

    expect(icecatInvoke('extractFeatures', $data))->toBe(['Good' => 'yes']);
});

it('caps the spec table so a datasheet cannot swamp the prompt', function (): void {
    $features = [];
    for ($i = 0; $i < 120; $i++) {
        $features[] = ['Feature' => ['Name' => ['Value' => "Spec {$i}"]], 'PresentationValue' => "value {$i}"];
    }

    expect(icecatInvoke('extractFeatures', ['FeaturesGroups' => [['Features' => $features]]]))
        ->toHaveCount(60);
});

it('treats a brand restriction as NOT an auth failure', function (): void {
    // The bug that cost 113 days. These messages mean "not covered for THIS
    // product" on an Open Icecat account — never "your account is bad".
    $source = file_get_contents(base_path('app/Domain/ProductAutoCreate/Services/IcecatClient.php'));

    expect($source)->toContain('$notAnAuthProblem')
        ->and($source)->toContain('brand restriction|access is limited|full icecat|app_key is required')
        // and the over-broad terms must be gone from the auth pattern itself
        ->and($source)->not->toContain("preg_match('/access|denied|unauthor");
});

it('probes more than one product before condemning the integration', function (): void {
    $source = file_get_contents(base_path('app/Domain/ProductAutoCreate/Services/IcecatClient.php'));

    expect($source)->toContain('$probes = [')
        // the Barco CX-30, verified as Open Icecat content on this account
        ->and($source)->toContain('5415334038875');
});

it('treats Icecat as grounding for the thin-content floor', function (): void {
    // A bare part number is fine to generate from IF Icecat describes it.
    $source = file_get_contents(base_path('app/Console/Commands/GenerateProductDraftsCommand.php'));

    expect($source)->toContain('$this->icecat->fetchProductFacts(')
        ->and($source)->toContain('! $allowThin && ! $grounded && $details === []')
        ->and($source)->toContain('icecat_description')
        ->and($source)->toContain('icecat_specifications');
});
