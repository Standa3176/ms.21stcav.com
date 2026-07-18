<?php

declare(strict_types=1);

use App\Domain\Sync\Services\SourceabilityClassifier;

/*
|--------------------------------------------------------------------------
| SourceabilityClassifier — PURE classification + normalisation logic
|--------------------------------------------------------------------------
|
| Quick task 260719-mgp. The classifier is the read-only diagnostic core of
| `supplier:probe-sourceability-gap`: given a local product's SKU + resolved
| manufacturer + the feed rows already scoped to that manufacturer, it decides
| WHY the product is not in supplier_sku_cache:
|
|   (a) matching_gap             — a NORMALISED SKU match exists (supplier carries
|                                  it under a different format → fixable matcher gap)
|   (b) brand_in_feed_item_absent — manufacturer has feed rows but no SKU match
|                                  (likely discontinued / lead-time)
|   (c) not_in_feed              — manufacturer absent from the feed entirely
|   (d) no_manufacturer          — product has no brand/manufacturer to key on
|
| No DB, no network — the reader seam supplies the (fake, in-memory) feed rows.
| These tests pin the normalisation rule + all four buckets, including the
| different-format case (MR.JQU11.002 vs feed MRJQU11002 / MR-JQU11-002).
*/

it('normalises to lowercase alphanumerics, stripping punctuation + whitespace', function (): void {
    $c = new SourceabilityClassifier;

    expect($c->normalize('MR.JQU11.002'))->toBe('mrjqu11002')
        ->and($c->normalize('MR-JQU11-002'))->toBe('mrjqu11002')
        ->and($c->normalize('  mr jqu11 002 '))->toBe('mrjqu11002')
        ->and($c->normalize('ABC/123_x'))->toBe('abc123x')
        ->and($c->normalize(''))->toBe('');
});

it('(d) no_manufacturer — null or blank manufacturer never touches the feed', function (): void {
    $c = new SourceabilityClassifier;

    expect($c->classify(null, 'ANY-SKU', [['mpn' => 'ANY-SKU', 'suppliersku' => '']])['bucket'])
        ->toBe('no_manufacturer');
    expect($c->classify('   ', 'ANY-SKU', [])['bucket'])->toBe('no_manufacturer');
});

it('(c) not_in_feed — manufacturer resolved but the feed returned zero rows for it', function (): void {
    $c = new SourceabilityClassifier;

    $result = $c->classify('Obscurabrand', 'WIDGET-1', []);

    expect($result['bucket'])->toBe('not_in_feed')
        ->and($result['matched_feed_key'])->toBeNull();
});

it('(b) brand_in_feed_item_absent — manufacturer has rows but no normalised SKU match', function (): void {
    $c = new SourceabilityClassifier;

    $feed = [
        ['mpn' => 'YEA-OTHER-1', 'suppliersku' => 'S-1001'],
        ['mpn' => 'YEA-OTHER-2', 'suppliersku' => 'S-1002'],
    ];

    $result = $c->classify('Yealink', 'YEA-DISCONTINUED-9', $feed);

    expect($result['bucket'])->toBe('brand_in_feed_item_absent')
        ->and($result['matched_feed_key'])->toBeNull();
});

it('(a) matching_gap — normalised SKU matches a feed mpn under a DIFFERENT format', function (): void {
    $c = new SourceabilityClassifier;

    // Product SKU dotted; feed carries the same part squashed + hyphenated.
    $feed = [
        ['mpn' => 'MRJQU11002', 'suppliersku' => 'WC-99'],
        ['mpn' => 'MR-JQU11-002', 'suppliersku' => 'IG-77'],
    ];

    $result = $c->classify('Acer', 'MR.JQU11.002', $feed);

    expect($result['bucket'])->toBe('matching_gap')
        // First matching feed key wins (mpn checked before suppliersku, row order preserved).
        ->and($result['matched_feed_key'])->toBe('MRJQU11002');
});

it('(a) matching_gap — normalised match on suppliersku when mpn does not match', function (): void {
    $c = new SourceabilityClassifier;

    $feed = [
        ['mpn' => 'UNRELATED', 'suppliersku' => 'cp 158 51'],
    ];

    $result = $c->classify('Cisco', 'CP15851', $feed);

    expect($result['bucket'])->toBe('matching_gap')
        ->and($result['matched_feed_key'])->toBe('cp 158 51');
});

it('an empty/whitespace SKU with a resolved manufacturer + feed rows cannot match → item absent', function (): void {
    $c = new SourceabilityClassifier;

    $result = $c->classify('Yealink', '   ', [['mpn' => 'X', 'suppliersku' => 'Y']]);

    expect($result['bucket'])->toBe('brand_in_feed_item_absent');
});
