<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Models\WooAttributeTerm;
use App\Domain\ProductAutoCreate\Services\Spec\SpecTermVocabulary;
use App\Domain\ProductAutoCreate\Services\Spec\WooAttributeTermVocabulary;
use App\Domain\ProductAutoCreate\Services\SpecTaxonomyResolver;

/*
|--------------------------------------------------------------------------
| 260728-fwx T2 — SpecTaxonomyResolver integration (real DB-backed vocabulary)
|--------------------------------------------------------------------------
|
| Proves the container wiring: app(SpecTaxonomyResolver::class) resolves the
| Eloquent-backed WooAttributeTermVocabulary reading the local
| woo_attribute_terms mirror (T1). Still NO Woo I/O — the resolver reads the
| already-synced local cache, never the live store.
*/

function seedTerm(int $attributeId, string $slug, string $termName, int $termId): void
{
    WooAttributeTerm::query()->create([
        'attribute_id' => $attributeId,
        'attribute_slug' => $slug,
        'attribute_name' => null,
        'term_id' => $termId,
        'term_name' => $termName,
        'term_slug' => null,
    ]);
}

it('binds SpecTermVocabulary to the DB-backed WooAttributeTermVocabulary', function (): void {
    expect(app(SpecTermVocabulary::class))->toBeInstanceOf(WooAttributeTermVocabulary::class);
});

it('resolves against the seeded woo_attribute_terms mirror', function (): void {
    seedTerm(3268, 'pa_colour', 'Black', 5001);
    seedTerm(3268, 'pa_colour', 'White', 5002);
    seedTerm(3516, 'pa_screen-size-band', '44-55', 5003);

    $resolver = app(SpecTaxonomyResolver::class);

    $spec = $resolver->resolve([
        ['name' => 'Colour', 'value' => 'black'],           // ci → Black
        ['name' => 'Display Size', 'value' => '55 inch'],   // band → 44-55
        ['name' => 'MPN', 'value' => 'ABC-123'],            // local-forced
        ['name' => 'Widget Factor', 'value' => '42'],       // unknown → local
    ]);

    expect($spec->global())->toHaveCount(2);

    $bySlug = collect($spec->global())->keyBy('attribute_slug');
    expect($bySlug['pa_colour']['term_id'])->toBe(5001);
    expect($bySlug['pa_colour']['term_name'])->toBe('Black');
    expect($bySlug['pa_screen-size-band']['term_id'])->toBe(5003);
    expect($bySlug['pa_screen-size-band']['term_name'])->toBe('44-55');

    // MPN + unknown label + the band's exact companion figure all land local.
    expect($spec->local())->toContain(['name' => 'MPN', 'value' => 'ABC-123']);
    expect($spec->local())->toContain(['name' => 'Widget Factor', 'value' => '42']);
    expect($spec->local())->toContain(['name' => 'Display Size', 'value' => '55 inch']);
});

it('withholds an uncached value from Woo (unmatched, not global)', function (): void {
    seedTerm(3273, 'pa_connectivity', 'HDMI', 6001);

    $resolver = app(SpecTaxonomyResolver::class);

    $spec = $resolver->resolve([['name' => 'Connectivity', 'value' => 'Thunderbolt 5']]);

    expect($spec->global())->toBe([]);
    expect($spec->unmatched())->toHaveCount(1);
    expect($spec->unmatched()[0]['reason'])->toBe('value_not_a_term');
});
