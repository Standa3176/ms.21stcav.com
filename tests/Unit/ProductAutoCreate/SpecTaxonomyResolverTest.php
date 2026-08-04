<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ResolvedSpec;
use App\Domain\ProductAutoCreate\Services\Spec\ArraySpecTermVocabulary;
use App\Domain\ProductAutoCreate\Services\SpecTaxonomyResolver;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| 260728-fwx T2 — SpecTaxonomyResolver (pure classifier — NO Woo / NO DB)
|--------------------------------------------------------------------------
|
| The resolver turns a product's raw {name,value}[] spec set into a classified
| plan: global pa_* taxonomy rows (resolved to EXISTING terms only), local
| spec-only rows, and unmatched rows (logged, NEVER sent — Woo would auto-
| create an unknown term and re-pollute the cleaned facets).
|
| These are pure unit tests: the term vocabulary is injected as an in-memory
| ArraySpecTermVocabulary, so no Woo and no database are touched.
*/

/**
 * Attribute ids from the 44-map (Task 2), used to seed the test vocabulary.
 */
const ATTR_RESOLUTION = 3429;
const ATTR_SCREEN_SIZE_BAND = 3516;
const ATTR_BRIGHTNESS_NITS = 3518;
const ATTR_BRIGHTNESS_LUMENS = 3554;
const ATTR_ROOM_SIZE_BAND = 3553;
const ATTR_COLOUR = 3268;
const ATTR_CONNECTIVITY = 3273;

/**
 * Build a resolver over a hand-seeded vocabulary. $termsByAttr = attribute_id
 * => list of term names (ids auto-assigned deterministically).
 *
 * @param  array<int, list<string>>  $termsByAttr
 */
function makeResolver(array $termsByAttr): SpecTaxonomyResolver
{
    $vocab = [];
    $termId = 9000;
    foreach ($termsByAttr as $attributeId => $names) {
        foreach ($names as $name) {
            $vocab[$attributeId][] = [
                'term_id' => $termId++,
                'term_name' => $name,
                'term_slug' => null,
            ];
        }
    }

    return new SpecTaxonomyResolver(new ArraySpecTermVocabulary($vocab));
}

beforeEach(function (): void {
    Log::spy();
});

// ── Label → attribute mapping ──────────────────────────────────────────────

it('maps a canonical Woo label to the right attribute_id + resolved term', function (): void {
    $resolver = makeResolver([ATTR_COLOUR => ['Black']]);

    $spec = $resolver->resolve([['name' => 'Colour', 'value' => 'Black']]);

    expect($spec)->toBeInstanceOf(ResolvedSpec::class);
    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_id'])->toBe(ATTR_COLOUR);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_colour');
    expect($spec->global()[0]['term_name'])->toBe('Black');
    expect($spec->local())->toBe([]);
    expect($spec->unmatched())->toBe([]);
});

it('resolves a Claude label alias to the correct attribute_id', function (): void {
    // "Screen Resolution" is an alias for the canonical "Resolution" (pa_resolution/3429).
    $resolver = makeResolver([ATTR_RESOLUTION => ['4K UHD (3840x2160)']]);

    $spec = $resolver->resolve([['name' => 'Screen Resolution', 'value' => '4K UHD (3840x2160)']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_id'])->toBe(ATTR_RESOLUTION);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_resolution');
});

it('passes an unknown label through as a local spec row', function (): void {
    $resolver = makeResolver([]);

    $spec = $resolver->resolve([['name' => 'Some Bespoke Spec', 'value' => 'Whatever']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toBe([]);
    expect($spec->local())->toBe([['name' => 'Some Bespoke Spec', 'value' => 'Whatever']]);
});

// ── Spec-only (local-forced) labels ────────────────────────────────────────

it('forces MPN / Model / Part Number to local even when numeric', function (): void {
    $resolver = makeResolver([]);

    $spec = $resolver->resolve([
        ['name' => 'MPN', 'value' => '123456'],
        ['name' => 'Model', 'value' => '55UH5F'],
        ['name' => 'Part Number', 'value' => '000999'],
    ]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toBe([]);
    expect($spec->local())->toBe([
        ['name' => 'MPN', 'value' => '123456'],
        ['name' => 'Model', 'value' => '55UH5F'],
        ['name' => 'Part Number', 'value' => '000999'],
    ]);
});

it('keeps the EXACT brightness figures local (D1), never as a taxonomy', function (): void {
    $resolver = makeResolver([ATTR_BRIGHTNESS_NITS => ['Semi-bright (351-700)']]);

    $spec = $resolver->resolve([['name' => 'Brightness (cd/m²)', 'value' => '500 cd/m²']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toBe([]);
    expect($spec->local())->toBe([['name' => 'Brightness (cd/m²)', 'value' => '500 cd/m²']]);
});

// ── Value → term resolution ────────────────────────────────────────────────

it('resolves a value case-insensitively to the correct term_id', function (): void {
    $resolver = makeResolver([ATTR_COLOUR => ['Black']]);

    $spec = $resolver->resolve([['name' => 'Colour', 'value' => 'black']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe('Black');
});

it('resolves a value via the per-attribute value-alias map', function (): void {
    $resolver = makeResolver([ATTR_RESOLUTION => ['4K UHD (3840x2160)', 'Full HD (1920x1080)']]);

    foreach (['4K', '4k uhd', '3840x2160', '4K@60Hz'] as $raw) {
        $spec = $resolver->resolve([['name' => 'Resolution', 'value' => $raw]]);
        expect($spec->global())->toHaveCount(1);
        expect($spec->global()[0]['term_name'])->toBe('4K UHD (3840x2160)');
    }

    foreach (['1080p', 'FHD', '1920x1080'] as $raw) {
        $spec = $resolver->resolve([['name' => 'Resolution', 'value' => $raw]]);
        expect($spec->global()[0]['term_name'])->toBe('Full HD (1920x1080)');
    }
});

it('marks an unresolvable value as unmatched and NEVER in global', function (): void {
    $resolver = makeResolver([ATTR_CONNECTIVITY => ['HDMI', 'USB-C']]);

    $spec = $resolver->resolve([['name' => 'Connectivity', 'value' => 'Thunderbolt 5']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_connectivity');
    expect($spec->unmatched()[0]['reason'])->toBe('value_not_a_term');

    // Belt-and-braces: the raw value must not have leaked into global.
    $globalValues = array_column($spec->global(), 'raw_value');
    expect($globalValues)->not->toContain('Thunderbolt 5');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg, array $ctx): bool => $msg === 'spec_taxonomy.unmatched' && $ctx['reason'] === 'value_not_a_term');
});

// ── Band derivation boundaries ─────────────────────────────────────────────

it('derives the screen-size band at inclusive boundaries', function (string $raw, string $band): void {
    // Seeded with the EXACT live pa_screen-size-band (3516) term names.
    $resolver = makeResolver([
        ATTR_SCREEN_SIZE_BAND => ['Up to 22 inch', '23-27 inch', '28-34 inch', '35-43 inch', '44-55 inch', '56-65 inch', '66-75 inch', '76-85 inch', '86 inch and above'],
    ]);

    $spec = $resolver->resolve([['name' => 'Display Size', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_screen-size-band');
    expect($spec->global()[0]['term_name'])->toBe($band);
})->with([
    ['22 inch', 'Up to 22 inch'],
    ['23"', '23-27 inch'],
    ['43 inch', '35-43 inch'],
    ['44 inch', '44-55 inch'],
    ['55 inch', '44-55 inch'],
    ['56"', '56-65 inch'],
    ['86 inch', '86 inch and above'],
    ['90 inch', '86 inch and above'],
    ['98"', '86 inch and above'],
]);

it('derives the cd/m² brightness band at inclusive boundaries', function (float|string $raw, string $band): void {
    $resolver = makeResolver([
        ATTR_BRIGHTNESS_NITS => ['Standard (up to 350)', 'Semi-bright (351-700)', 'High bright (701-2500)', 'Window facing (2500+)'],
    ]);

    $spec = $resolver->resolve([['name' => 'Brightness Band (cd/m²)', 'value' => (string) $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe($band);
})->with([
    ['350', 'Standard (up to 350)'],
    ['351', 'Semi-bright (351-700)'],
    ['700', 'Semi-bright (351-700)'],
    ['2500', 'High bright (701-2500)'],
    ['2501', 'Window facing (2500+)'],
]);

it('derives the lumens brightness band at inclusive boundaries', function (string $raw, string $band): void {
    // Seeded with the EXACT live pa_brightness-lumens (3554) term names.
    $resolver = makeResolver([
        ATTR_BRIGHTNESS_LUMENS => ['Under 3000 lumens', '3000-4999 lumens', '5000-9999 lumens', '10000+ lumens'],
    ]);

    $spec = $resolver->resolve([['name' => 'Brightness Band (lumens)', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe($band);
})->with([
    ['2999 lumens', 'Under 3000 lumens'],
    ['3000 lumens', '3000-4999 lumens'],
    ['4000 lumens', '3000-4999 lumens'],
    ['4999 lumens', '3000-4999 lumens'],
    ['5000 lumens', '5000-9999 lumens'],
    ['10000 lumens', '10000+ lumens'],
]);

it('emits the exact figure as a local companion row alongside the band', function (): void {
    $resolver = makeResolver([ATTR_BRIGHTNESS_NITS => ['Semi-bright (351-700)']]);

    $spec = $resolver->resolve([['name' => 'Brightness Band (cd/m²)', 'value' => '500 cd/m²']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe('Semi-bright (351-700)');
    // The exact raw figure survives as a LOCAL spec row.
    expect($spec->local())->toBe([['name' => 'Brightness (cd/m²)', 'value' => '500 cd/m²']]);
});

it('marks a band as unmatched when the derived term is not cached', function (): void {
    // Live vocab, but missing the "44-55 inch" band term → resolve-don't-invent
    // holds even with the tolerant match (nothing normalises onto 44-55).
    $resolver = makeResolver([ATTR_SCREEN_SIZE_BAND => ['Up to 22 inch', '23-27 inch']]);

    $spec = $resolver->resolve([['name' => 'Display Size', 'value' => '55 inch']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['reason'])->toBe('band_term_not_cached');
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_screen-size-band');
    // The exact figure is NOT kept when the band itself is unmatched.
    expect($spec->local())->toBe([]);
});

it('marks a non-numeric band value as unmatched', function (): void {
    $resolver = makeResolver([ATTR_SCREEN_SIZE_BAND => ['Up to 22 inch', '44-55 inch']]);

    $spec = $resolver->resolve([['name' => 'Display Size', 'value' => 'Large']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['reason'])->toBe('band_value_not_numeric');
});

it('derives the room-size band with lower-band-wins tie-break', function (string $raw, string $band): void {
    // Seeded with the EXACT live pa_room-size-band (3553) term names.
    $resolver = makeResolver([
        ATTR_ROOM_SIZE_BAND => ['Huddle (2-4 people)', 'Small (4-6 people)', 'Medium (6-10 people)', 'Large (10+ people)'],
    ]);

    $spec = $resolver->resolve([['name' => 'Room Size', 'value' => $raw]]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['term_name'])->toBe($band);
})->with([
    ['3 people', 'Huddle (2-4 people)'],
    ['4 people', 'Huddle (2-4 people)'],       // shared boundary → lower band
    ['5 people', 'Small (4-6 people)'],
    ['6 people', 'Small (4-6 people)'],        // shared boundary → lower band
    ['10 people', 'Medium (6-10 people)'],
    ['12 people', 'Large (10+ people)'],
]);

it('tolerantly matches a band term despite a minor unit/case/whitespace drift', function (string $cachedTerm): void {
    // The derived label is "44-55 inch"; each seeded cache term differs only by
    // unit suffix / casing / whitespace and must still resolve — to the REAL
    // cached term (the cache is the source of truth for what's actually sent).
    $resolver = makeResolver([ATTR_SCREEN_SIZE_BAND => [$cachedTerm]]);

    $spec = $resolver->resolve([['name' => 'Display Size', 'value' => '55 inch']]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_screen-size-band');
    expect($spec->global()[0]['term_name'])->toBe($cachedTerm);
    expect($spec->unmatched())->toBe([]);
})->with([
    '44-55 inch',    // exact
    '44-55 inches',  // plural unit
    '44-55  inch',   // double internal space
    '44-55 INCH',    // upper-case unit
    '44-55',         // no unit token at all
]);

// ── Never-mix-units (D1) ───────────────────────────────────────────────────

it('drops and logs the second brightness unit when both are present', function (): void {
    $resolver = makeResolver([
        ATTR_BRIGHTNESS_NITS => ['Semi-bright (351-700)'],
        ATTR_BRIGHTNESS_LUMENS => ['3000-4999 lumens'],
    ]);

    // cd/m² appears first → wins; the lumens signal is dropped as mixed_units.
    $spec = $resolver->resolve([
        ['name' => 'Brightness Band (cd/m²)', 'value' => '500 cd/m²'],
        ['name' => 'Brightness Band (lumens)', 'value' => '4000 lumens'],
    ]);

    expect($spec->global())->toHaveCount(1);
    expect($spec->global()[0]['attribute_slug'])->toBe('pa_brightness-nits');

    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['reason'])->toBe('mixed_units');
    expect($spec->unmatched()[0]['attribute_slug'])->toBe('pa_brightness-lumens');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg, array $ctx): bool => $msg === 'spec_taxonomy.unmatched' && $ctx['reason'] === 'mixed_units');
});

it('drops the exact lumens spec row too when cd/m² wins the unit conflict', function (): void {
    $resolver = makeResolver([ATTR_BRIGHTNESS_NITS => ['Semi-bright (351-700)']]);

    $spec = $resolver->resolve([
        ['name' => 'Brightness Band (cd/m²)', 'value' => '500'],
        ['name' => 'Brightness (lumens)', 'value' => '4000 lumens'],  // would be local, but conflicts
    ]);

    // Band cd/m² → global; its companion exact → local; lumens dropped.
    expect($spec->global())->toHaveCount(1);
    expect($spec->local())->toBe([['name' => 'Brightness (cd/m²)', 'value' => '500']]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['reason'])->toBe('mixed_units');
});

// ── Purity guard ───────────────────────────────────────────────────────────

it('performs NO Woo / HTTP call anywhere in the resolver source', function (): void {
    $source = file_get_contents(
        base_path('app/Domain/ProductAutoCreate/Services/SpecTaxonomyResolver.php')
    );

    expect($source)->not->toContain('WooClient');
    expect($source)->not->toContain('Http::');
    expect($source)->not->toContain('Guzzle');
    expect($source)->not->toContain('curl');
    // The resolver's only injected dependency is the vocabulary seam.
    expect($source)->toContain('SpecTermVocabulary');
});
