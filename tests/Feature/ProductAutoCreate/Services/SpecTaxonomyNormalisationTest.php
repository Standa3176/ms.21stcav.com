<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Models\WooAttributeTerm;
use App\Domain\ProductAutoCreate\Services\SpecTaxonomyResolver;
use App\Domain\ProductAutoCreate\Services\WooAttributePayloadBuilder;

/*
|--------------------------------------------------------------------------
| 260728-fwx T9 — label-alias + value-normalisation + multi-term coverage
|--------------------------------------------------------------------------
|
| Extends the T2 SpecTaxonomyResolver with config-driven label aliases,
| per-attribute value normalisers (regex / keyword / split logic reading
| config/spec_taxonomy.php) and MULTI-TERM support (Connectivity) — all still
| RESOLVE-DON'T-INVENT: a normalised candidate must match an EXISTING cached
| term (seeded here into woo_attribute_terms) or it stays unmatched.
|
| These are DB-backed (real woo_attribute_terms mirror + real config) so the
| whole path — config → resolver → cache — is exercised end to end. NO Woo I/O.
*/

/** @param  array<int, list<string>>  $termsByAttr */
function seedTerms(array $termsByAttr): void
{
    $termId = 7000;
    foreach ($termsByAttr as $attributeId => $names) {
        foreach ($names as $name) {
            WooAttributeTerm::query()->create([
                'attribute_id' => $attributeId,
                'attribute_slug' => 'pa_seed',
                'attribute_name' => null,
                'term_id' => $termId++,
                'term_name' => $name,
                'term_slug' => null,
            ]);
        }
    }
}

/** The canonical live term vocabulary (subset used across the T9 cases). */
function seedT9Vocabulary(): void
{
    seedTerms([
        3429 => ['4K UHD (3840x2160)', 'Full HD (1920x1080)', 'QHD (2560x1440)', 'HD (1280x720)', 'HD (1366x768)', '8K UHD (7680x4320)', 'WUXGA (1920x1200)'],
        3517 => ['Wall', 'Ceiling', 'Trolley / Cart', 'Desk', 'Floor Standing', 'Pole', 'Tabletop', 'Tripod', 'Rack', 'DIN Rail'],
        3273 => ['Bluetooth', 'Wireless', '2.4GHz Wireless', 'USB', 'Wired', 'IP / Network', 'Ethernet', 'USB-C', 'Wi-Fi', 'HDMI', 'USB 3.0'],
        3538 => ['Cat8', 'Cat5e', 'Cat6', 'Cat7', 'Cat6a'],
        3498 => ['Lifetime', '3 Years', '1 Year', '2 Years', '5 Years', '10 Years', '6 Months'],
        3543 => ['LCD', 'IPS', 'VA'],
        3533 => ['75x75', '100x100', 'VESA compatible', '200x200 / 600x400', '400x400', '600x400', '200x200', '800x400', '800x600'],
        3518 => ['Standard (up to 350)', 'Semi-bright (351-700)', 'High bright (701-2500)', 'Window facing (2500+)'],
        3554 => ['Under 3000 lumens', '3000-4999 lumens', '5000-9999 lumens', '10000+ lumens'],
    ]);
}

function t9Resolver(): SpecTaxonomyResolver
{
    return app(SpecTaxonomyResolver::class);
}

beforeEach(function (): void {
    seedT9Vocabulary();
});

// ── Resolution normalisation ────────────────────────────────────────────────

it('normalises resolution values to the 4K UHD term', function (string $raw): void {
    $spec = t9Resolver()->resolve([['name' => 'Resolution', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_resolution');
    expect($spec->global()[0]['term_name'])->toBe('4K UHD (3840x2160)');
})->with([
    '4K UHD 3840 x 2160 (2160p)',
    '4K UHD 3840 × 2160',
    '4K Ultra HD',
    '3840 x 2160 (4K UHD)',
]);

it('normalises "Full HD 1080p" to the Full HD term', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Resolution', 'value' => 'Full HD 1080p']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe('Full HD (1920x1080)');
});

it('still exact-matches a native resolution term the normaliser has no pair for', function (): void {
    // 1366x768 is NOT in the pair map — must fall through to the exact cache hit.
    $spec = t9Resolver()->resolve([['name' => 'Resolution', 'value' => 'HD (1366x768)']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe('HD (1366x768)');
});

// ── Mount normalisation ──────────────────────────────────────────────────────

it('normalises mount values by contains-keyword', function (string $raw, string $term): void {
    $spec = t9Resolver()->resolve([['name' => 'Mount Type', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_mount-type');
    expect($spec->global()[0]['term_name'])->toBe($term);
})->with([
    ['Tilt Wall Mount', 'Wall'],
    ['Wall Mount', 'Wall'],
    ['Full-Motion Wall Mount', 'Wall'],
    ['Wall Arm', 'Wall'],
    ['Desk Clamp', 'Desk'],
    ['Desk-mountable articulating arm', 'Desk'],
    ['Ceiling Mount', 'Ceiling'],
]);

// ── Cable category normalisation ─────────────────────────────────────────────

it('normalises cable category values', function (string $raw, string $term): void {
    $spec = t9Resolver()->resolve([['name' => 'Cable Category', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_cable-category');
    expect($spec->global()[0]['term_name'])->toBe($term);
})->with([
    ['Cat.6', 'Cat6'],
    ['Cat.8.1', 'Cat8'],
]);

// ── Warranty normalisation ───────────────────────────────────────────────────

it('normalises "Lifetime Warranty" to Lifetime', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Warranty', 'value' => 'Lifetime Warranty']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_warranty');
    expect($spec->global()[0]['term_name'])->toBe('Lifetime');
});

it('normalises numeric warranty phrasings', function (string $raw, string $term): void {
    $spec = t9Resolver()->resolve([['name' => 'Warranty', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe($term);
})->with([
    ['3 year warranty', '3 Years'],
    ['3 years', '3 Years'],
    ['1 year', '1 Year'],
    ['6 months', '6 Months'],
]);

// ── Panel normalisation ──────────────────────────────────────────────────────

it('normalises "LED-backlit LCD" to LCD', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Panel Type', 'value' => 'LED-backlit LCD']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_panel-type');
    expect($spec->global()[0]['term_name'])->toBe('LCD');
});

// ── Multi-term Connectivity ──────────────────────────────────────────────────

it('splits connectivity into multiple resolved terms', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'Bluetooth, 2.4 GHz Wireless']]);

    expect($spec->global())->toHaveCount(1);
    $row = $spec->global()[0];
    expect($row['attribute_slug'])->toBe('pa_connectivity');
    expect($row['term_names'])->toBe(['Bluetooth', '2.4GHz Wireless']);
    expect($row['term_ids'])->toHaveCount(2);
    // Backward-compat scalar keys point at the first resolved term.
    expect($row['term_name'])->toBe('Bluetooth');
    expect($row['term_id'])->toBe($row['term_ids'][0]);
});

it('strips a version number from a single connectivity token', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'Bluetooth 5.1']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['Bluetooth']);
});

it('splits connectivity on the word "and"', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'Wired and Wireless']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['Wired', 'Wireless']);
});

it('keeps a slash-bearing connectivity term whole (not split)', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'IP / Network']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['IP / Network']);
});

it('emits the resolved connectivity tokens and logs the unresolved one', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'Bluetooth & Telepathy']]);

    // The row still emits Bluetooth; Telepathy is unmatched (not a cached term).
    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['Bluetooth']);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_connectivity');
    expect($spec->unmatched()[0]['reason'])->toBe('value_not_a_term');
});

it('builds a Woo payload with all resolved connectivity options', function (): void {
    $payload = app(WooAttributePayloadBuilder::class)
        ->build([['name' => 'Connectivity', 'value' => 'Bluetooth, 2.4 GHz Wireless']]);

    expect($payload)->toHaveCount(1);
    expect($payload[0]['id'])->toBe(3273);
    expect($payload[0]['options'])->toBe(['Bluetooth', '2.4GHz Wireless']);
    expect($payload[0]['visible'])->toBeTrue();
    expect($payload[0]['variation'])->toBeFalse();
});

// ── Label aliases (config-driven) ────────────────────────────────────────────

it('routes "VESA Compatibility" + spaced value to the pa_vesa-standard term', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'VESA Compatibility', 'value' => '200 x 200']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_vesa-standard');
    expect($spec->global()[0]['term_name'])->toBe('200x200');
});

it('normalises a VESA "compatible" value', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'VESA', 'value' => 'VESA Compatible']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe('VESA compatible');
});

it('routes "Connection Type" to pa_connectivity', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connection Type', 'value' => 'HDMI']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_connectivity');
    expect($spec->global()[0]['term_names'])->toBe(['HDMI']);
});

// ── Brightness unit-routed label ─────────────────────────────────────────────

it('routes a "Brightness" cd/m² value to the pa_brightness-nits band', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Brightness', 'value' => '500 cd/m²']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_brightness-nits');
    expect($spec->global()[0]['term_name'])->toBe('Semi-bright (351-700)');
});

it('routes a "Brightness" lumens value to the pa_brightness-lumens band', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Brightness', 'value' => '3500 lumens']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_brightness-lumens');
    expect($spec->global()[0]['term_name'])->toBe('3000-4999 lumens');
});

// ── Resolve-don't-invent preserved ───────────────────────────────────────────

it('leaves a made-up mount unmatched (normalises but not a cached term)', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Mount Type', 'value' => 'Hovercraft Suspension']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_mount-type');
    expect($spec->unmatched()[0]['reason'])->toBe('value_not_a_term');
});
