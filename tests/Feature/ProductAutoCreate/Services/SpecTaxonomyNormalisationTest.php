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
        3273 => ['Bluetooth', 'Wireless', '2.4GHz Wireless', 'USB', 'Wired', 'IP / Network', 'Ethernet', 'USB-C', 'Wi-Fi', 'HDMI', 'USB 3.0', '3.5mm Audio'],
        3538 => ['Cat8', 'Cat5e', 'Cat6', 'Cat7', 'Cat6a'],
        3498 => ['Lifetime', '3 Years', '1 Year', '2 Years', '5 Years', '10 Years', '6 Months'],
        3543 => ['LCD', 'IPS', 'VA'],
        3533 => ['75x75', '100x100', 'VESA compatible', '200x200 / 600x400', '400x400', '600x400', '200x200', '800x400', '800x600', '200x200 / 300x300 / 400x200 / 400x400 / 600x400', '75x75 / 100x100'],
        3518 => ['Standard (up to 350)', 'Semi-bright (351-700)', 'High bright (701-2500)', 'Window facing (2500+)'],
        3554 => ['Under 3000 lumens', '3000-4999 lumens', '5000-9999 lumens', '10000+ lumens'],
        // T10 additions.
        3522 => ['Tilt & Swivel', 'Fixed', 'Tilt', 'Swivel', 'Full Motion'],                              // Movement
        3537 => ['U/UTP', 'S/FTP'],                                                                       // Shielding
        3268 => ['Black', 'White', 'Grey', 'Silver', 'Graphite', 'Blue', 'Red', 'Green'],                // Colour
        3520 => ['LCD', 'IPS', 'Direct View LED', 'Interactive Display', 'Commercial TV', 'Digital', 'Large Format Commercial Display', 'OLED'], // Display Technology
        3364 => ['Steel', 'Aluminium', 'Plastic', 'Polycarbonate', 'Glass', 'Aluminum'],                 // Material
        3534 => ['0.6m', '2m', '1m', '3m', '2 metres', '0.5m'],                                          // Length
        3542 => ['Laser', 'Lamp', 'LED'],                                                                // Light Source
        3553 => ['Huddle (2-4 people)', 'Small (4-6 people)', 'Medium (6-10 people)', 'Large (10+ people)'], // Room Size
        3550 => ['Yes', 'No'],                                                                           // Touchscreen
        3547 => ['50kg', '25kg', '100kg'],                                                               // Max Load
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
    // T10 bearer→mode: a directly-resolved Bluetooth implies the Wireless mode.
    expect($row['term_names'])->toBe(['Bluetooth', '2.4GHz Wireless', 'Wireless']);
    expect($row['term_ids'])->toHaveCount(3);
    // Backward-compat scalar keys point at the first resolved term.
    expect($row['term_name'])->toBe('Bluetooth');
    expect($row['term_id'])->toBe($row['term_ids'][0]);
});

it('strips a version number from a single connectivity token', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'Bluetooth 5.1']]);

    expect($spec->global())->toHaveCount(1);
    // T10 bearer→mode: Bluetooth implies Wireless.
    expect($spec->global()[0]['term_names'])->toBe(['Bluetooth', 'Wireless']);
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

    // The row still emits Bluetooth (+ its Wireless mode); Telepathy is unmatched.
    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['Bluetooth', 'Wireless']);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_connectivity');
    expect($spec->unmatched()[0]['reason'])->toBe('value_not_a_term');
});

it('builds a Woo payload with all resolved connectivity options', function (): void {
    $payload = app(WooAttributePayloadBuilder::class)
        ->build([['name' => 'Connectivity', 'value' => 'Bluetooth, 2.4 GHz Wireless']]);

    expect($payload)->toHaveCount(1);
    expect($payload[0]['id'])->toBe(3273);
    // T10 bearer→mode adds Wireless alongside Bluetooth.
    expect($payload[0]['options'])->toBe(['Bluetooth', '2.4GHz Wireless', 'Wireless']);
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
    // T10 bearer→mode: a directly-resolved HDMI implies the Wired mode.
    expect($spec->global()[0]['term_names'])->toBe(['HDMI', 'Wired']);
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

/*
|--------------------------------------------------------------------------
| 260728-fwx T10 — value-normalisation coverage lift
|--------------------------------------------------------------------------
*/

// ── VESA range enumeration (§1) ──────────────────────────────────────────────

it('enumerates a VESA range into every standard pattern within it', function (string $raw, string $expected): void {
    $spec = t9Resolver()->resolve([['name' => 'VESA', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_vesa-standard');
    expect($spec->global()[0]['term_name'])->toBe($expected);
})->with([
    ['200×200 mm to 600×400 mm', '200x200 / 300x300 / 400x200 / 400x400 / 600x400'],
    ['75×75 mm and 100×100 mm', '75x75 / 100x100'],
    ['VESA 75, VESA 100', '75x75 / 100x100'],
]);

it('leaves a VESA range whose enumerated string is not cached unmatched', function (): void {
    // "50x50 to 1000x600" enumerates the full standard list — not a seeded term.
    $spec = t9Resolver()->resolve([['name' => 'VESA', 'value' => '50x50 to 1000x600']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_vesa-standard');
    expect($spec->unmatched()[0]['reason'])->toBe('value_not_a_term');
});

// ── Room Size text-map + multi-value (§2) ────────────────────────────────────

it('text-maps a single Room Size descriptor to its band', function (string $raw, string $band): void {
    $spec = t9Resolver()->resolve([['name' => 'Room Size', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_room-size-band');
    expect($spec->global()[0]['term_names'])->toBe([$band]);
})->with([
    ['Large Room', 'Large (10+ people)'],
    ['Extra Large Room', 'Large (10+ people)'],
    ['Medium/Large', 'Medium (6-10 people)'],   // dominant descriptor, one band
    ['Focus Room', 'Huddle (2-4 people)'],
    ['Small (1-4 people)', 'Small (4-6 people)'],
]);

it('emits multiple Room Size bands sorted small→large', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Room Size', 'value' => 'Large Room, Small Room, Medium Room']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe([
        'Small (4-6 people)', 'Medium (6-10 people)', 'Large (10+ people)',
    ]);
    // The raw text is also kept as a LOCAL companion.
    expect($spec->local())->toContain(['name' => 'Room Size', 'value' => 'Large Room, Small Room, Medium Room']);
});

it('still derives a numeric Room Size band', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Room Size', 'value' => '8 people']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['Medium (6-10 people)']);
});

// ── Mount → Movement value re-routing (§3) ───────────────────────────────────

it('re-routes a bare movement value under a Mount label to pa_movement', function (string $raw, string $term): void {
    $spec = t9Resolver()->resolve([['name' => 'Mount Type', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_movement');
    expect($spec->global()[0]['term_name'])->toBe($term);
})->with([
    ['Fixed', 'Fixed'],
    ['Tilt', 'Tilt'],
    ['Swivel', 'Swivel'],
    ['Tilt & Swivel', 'Tilt & Swivel'],
    ['Full Motion – Tilt, Swivel, 3 Pivots', 'Full Motion'],
]);

it('keeps a real Wall mount (that mentions motion) as pa_mount-type', function (string $raw): void {
    $spec = t9Resolver()->resolve([['name' => 'Mount Type', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_mount-type');
    expect($spec->global()[0]['term_name'])->toBe('Wall');
})->with([
    'Full-Motion Wall Mount',
    'Tilt Wall Mount',
    'Swivel Mount',
]);

it('routes a Motion Type label to pa_movement', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Motion Type', 'value' => 'Tilt']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_movement');
    expect($spec->global()[0]['term_name'])->toBe('Tilt');
});

// ── Straight value maps (§3) ─────────────────────────────────────────────────

it('maps a Shielding value to U/UTP', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Shielding', 'value' => 'U/UTP (Unshielded)']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_shielding-2');
    expect($spec->global()[0]['term_name'])->toBe('U/UTP');
});

it('maps a two-tone Colour to its dominant colour', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Colour', 'value' => 'Graphite Grey']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_colour');
    expect($spec->global()[0]['term_name'])->toBe('Graphite');
});

it('maps a backlit Display Technology to LCD', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Display Technology', 'value' => 'LED-backlit LCD']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_display-tech');
    expect($spec->global()[0]['term_name'])->toBe('LCD');
});

it('drops a junk Display Technology value even though it is cached', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Display Technology', 'value' => 'Interactive Display']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_display-tech');
});

it('maps a Material value to Steel', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Material', 'value' => 'Cold-Rolled Steel']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_material');
    expect($spec->global()[0]['term_name'])->toBe('Steel');
});

it('overrides a cached US Material spelling to UK', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Material', 'value' => 'Aluminum']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe('Aluminium');
});

it('space-strips a Length value', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Length', 'value' => '0.6 m']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_cable-length');
    expect($spec->global()[0]['term_name'])->toBe('0.6m');
});

it('maps a Light Source value with the simple maps only', function (string $raw, string $term): void {
    $spec = t9Resolver()->resolve([['name' => 'Light Source', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_light-source');
    expect($spec->global()[0]['term_name'])->toBe($term);
})->with([
    ['RGB True Laser', 'Laser'],
    ['Phosphor Laser', 'Laser'],
    ['RGB LED', 'LED'],
    ['UHP', 'Lamp'],
]);

// ── Connectivity expansions + bearer→mode (§3) ───────────────────────────────

it('expands a Network connectivity value to Ethernet + IP / Network (no bearer)', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'Network (LAN)']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_connectivity');
    // Expansion-derived Ethernet does NOT trigger the Wired mode.
    expect($spec->global()[0]['term_names'])->toBe(['Ethernet', 'IP / Network']);
});

it('adds the Wireless mode alongside a directly-resolved Wi-Fi bearer', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => 'Wi-Fi']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['Wi-Fi', 'Wireless']);
});

it('maps a 3.5mm Stereo Jack to the 3.5mm Audio term', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connectivity', 'value' => '3.5mm Stereo Jack']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_names'])->toBe(['3.5mm Audio']);
});

// ── Label aliases to ADD (§4) ────────────────────────────────────────────────

it('aliases Max Load Capacity to pa_max-load-kg', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Max Load Capacity', 'value' => '50kg']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_max-load-kg');
    expect($spec->global()[0]['term_name'])->toBe('50kg');
});

it('aliases Display Type to pa_display-tech', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Display Type', 'value' => 'LED-backlit LCD']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_display-tech');
    expect($spec->global()[0]['term_name'])->toBe('LCD');
});

it('aliases Touch to pa_touchscreen-yn', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Touch', 'value' => 'Yes']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_touchscreen-yn');
    expect($spec->global()[0]['term_name'])->toBe('Yes');
});

it('routes Cable Type to pa_cable-category only for a CatN value', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Cable Type', 'value' => 'Cat6']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_cable-category');
    expect($spec->global()[0]['term_name'])->toBe('Cat6');
});

it('keeps Cable Type local for a non-CatN value', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Cable Type', 'value' => 'USB']]);

    expect($spec->global())->toBe([]);
    expect($spec->local())->toContain(['name' => 'Cable Type', 'value' => 'USB']);
});

// ── Do NOT alias / drop (§4b) ────────────────────────────────────────────────

it('keeps Connector A local (never merged)', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Connector A', 'value' => 'HDMI Male']]);

    expect($spec->global())->toBe([]);
    expect($spec->local())->toContain(['name' => 'Connector A', 'value' => 'HDMI Male']);
});

it('keeps Screen Size Range local (does not pollute the display size facet)', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'Screen Size Range', 'value' => '32" to 55"']]);

    expect($spec->global())->toBe([]);
    expect($spec->local())->toContain(['name' => 'Screen Size Range', 'value' => '32" to 55"']);
});

it('drops an EAN label entirely (not global, local, or unmatched)', function (): void {
    $spec = t9Resolver()->resolve([['name' => 'EAN', 'value' => '5012345678900']]);

    expect($spec->global())->toBe([]);
    expect($spec->local())->toBe([]);
    expect($spec->unmatched())->toBe([]);
});
