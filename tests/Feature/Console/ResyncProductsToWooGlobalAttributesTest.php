<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\ProductAutoCreate\Services\Spec\ArraySpecTermVocabulary;
use App\Domain\ProductAutoCreate\Services\Spec\SpecTermVocabulary;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;

/*
|--------------------------------------------------------------------------
| 260728-fwx T3 — products:resync-to-woo re-globalises attributes
|--------------------------------------------------------------------------
| A manual resync must build the SAME global-taxonomy attribute shape as the
| publish path (via WooAttributePayloadBuilder / SpecTaxonomyResolver) so a
| resync RE-GLOBALISES rather than re-localising the cleaned attributes.
|
| Attributes ride the second PUT ("everything except regular_price"), so we
| capture that call's payload and assert the global shape.
*/

beforeEach(function (): void {
    // In-memory term vocabulary — pa_resolution (3429) resolves, nothing else.
    app()->instance(SpecTermVocabulary::class, new ArraySpecTermVocabulary([
        3429 => [
            ['term_id' => 8801, 'term_name' => '4K UHD (3840x2160)', 'term_slug' => '4k-uhd-3840x2160'],
        ],
    ]));

    // No brands — keeps tags empty (brand_id null below) and skips the
    // product_brand WP-REST assignment path entirely.
    $taxonomy = new class extends TaxonomyResolver
    {
        public function __construct() {}

        public function allBrands(): array
        {
            return [];
        }
    };
    app()->instance(TaxonomyResolver::class, $taxonomy);

    $brandResolver = Mockery::mock(ProductBrandTermResolver::class);
    $brandResolver->shouldReceive('getTermIdForName')->andReturn(null);
    $brandResolver->shouldReceive('assignToProduct')->andReturn(false);
    app()->instance(ProductBrandTermResolver::class, $brandResolver);
});

it('resync: re-globalises attributes into the pa_* taxonomy shape (same as publish)', function (): void {
    $product = Product::factory()->create([
        'sku' => 'RESYNC-GLOBAL-1',
        'woo_product_id' => 640,
        'brand_id' => null,
        'tags' => [],
        'sell_price' => 100.00,
        'attributes_json' => [
            ['name' => 'Resolution', 'value' => '4K'],   // → GLOBAL id 3429
            ['name' => 'MPN', 'value' => 'XYZ-999'],       // → LOCAL
        ],
    ]);

    $capturedAttrs = null;
    $woo = Mockery::mock(WooClient::class);
    // 1st PUT — regular_price alone (split-write price isolation).
    $woo->shouldReceive('put')
        ->once()
        ->with('products/640', ['regular_price' => '100.00'])
        ->andReturn(['id' => 640]);
    // 2nd PUT — everything else (here: attributes only, no tags).
    $woo->shouldReceive('put')
        ->once()
        ->with('products/640', Mockery::on(function (array $payload) use (&$capturedAttrs): bool {
            $capturedAttrs = $payload['attributes'] ?? null;

            return isset($payload['attributes']);
        }))
        ->andReturn(['id' => 640]);
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:resync-to-woo', ['--skus' => 'RESYNC-GLOBAL-1'])
        ->assertExitCode(0);

    expect($capturedAttrs)->toBeArray();

    // Resolution → GLOBAL taxonomy (id + resolved term, no `name`, global-first).
    expect($capturedAttrs[0])->toBe([
        'id' => 3429,
        'options' => ['4K UHD (3840x2160)'],
        'position' => 0,
        'visible' => true,
        'variation' => false,
    ]);

    // MPN → LOCAL spec row.
    $mpn = collect($capturedAttrs)->firstWhere('name', 'MPN');
    expect($mpn)->not->toBeNull();
    expect($mpn['options'])->toBe(['XYZ-999']);

    // No local Resolution leaked through.
    expect(collect($capturedAttrs)->firstWhere('name', 'Resolution'))->toBeNull();
});
