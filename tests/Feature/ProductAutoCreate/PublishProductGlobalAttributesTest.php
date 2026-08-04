<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\ProductAutoCreate\Services\Spec\ArraySpecTermVocabulary;
use App\Domain\ProductAutoCreate\Services\Spec\SpecTermVocabulary;
use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use App\Domain\Sync\Services\WooClient;
use App\Domain\Sync\Services\WpRestClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
| Local (uniquely-named) test doubles — kept distinct from PublishProductJobTest's
| helpers so both files coexist in the full suite AND run in isolation.
*/

function t3NoBrandsTaxonomy(): TaxonomyResolver
{
    return new class extends TaxonomyResolver
    {
        public function __construct() {}

        public function allBrands(): array
        {
            return [];
        }
    };
}

function t3NoopBrandResolver(): ProductBrandTermResolver
{
    return new class(new WpRestClient('https://example.test/wp-json', null, null)) extends ProductBrandTermResolver
    {
        public function getTermIdForName(?string $brandName): ?int
        {
            return null;
        }

        public function assignToProduct(int $wooProductId, array $termIds): bool
        {
            return false;
        }
    };
}

function t3NoStockResolver(): LiveSupplierStockResolver
{
    $mock = Mockery::mock(LiveSupplierStockResolver::class);
    $mock->shouldReceive('resolveForSku')->andReturn(null);

    return $mock;
}

/*
|--------------------------------------------------------------------------
| 260728-fwx T3 — PublishProductJob builds GLOBAL pa_* taxonomy attributes
|--------------------------------------------------------------------------
| The publish path now routes attributes_json through SpecTaxonomyResolver
| (via WooAttributePayloadBuilder) so filterable specs attach as term-linked
| GLOBAL taxonomy attributes (FacetWP-visible) instead of local postmeta.
|
| GLOBAL rows   → {id, options:[term_name], visible, variation, position}
| LOCAL rows    → {name, options:[value], visible, variation, position}
| UNMATCHED     → withheld entirely (resolver already logged them for T6)
|
| The term vocabulary is injected via an ArraySpecTermVocabulary so terms
| resolve deterministically with NO Woo call and NO DB rows.
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());

    // Seed the resolver's term vocabulary in-memory (no Woo, no DB). Only
    // pa_resolution (3429) carries a term, so a resolvable Resolution value
    // becomes GLOBAL while a Colour (pa_colour 3268, no cached terms) value
    // has no term to resolve → UNMATCHED → withheld.
    app()->instance(SpecTermVocabulary::class, new ArraySpecTermVocabulary([
        3429 => [
            ['term_id' => 8801, 'term_name' => '4K UHD (3840x2160)', 'term_slug' => '4k-uhd-3840x2160'],
        ],
    ]));
});

it('path B: mappable spec + resolvable term → GLOBAL taxonomy attribute (id + resolved term), NOT local', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'GLOBAL-ATTR-1',
        'name' => 'Global Attr Widget',
        'sell_price' => null,
        'attributes_json' => [
            ['name' => 'Resolution', 'value' => '4K'],     // → GLOBAL id 3429, term '4K UHD (3840x2160)'
            ['name' => 'MPN', 'value' => 'ABC-123'],        // → LOCAL (spec-only)
            ['name' => 'Colour', 'value' => 'Rainbow'],     // mappable label, value not a term → UNMATCHED
        ],
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $captured = null;
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(function (array $payload) use (&$captured): bool {
            $captured = $payload['attributes'] ?? null;

            return true;
        }))
        ->andReturn(['id' => 71001, 'slug' => 'global-attr-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator, t3NoBrandsTaxonomy(), t3NoopBrandResolver(), t3NoStockResolver());

    expect($captured)->toBeArray();

    // The Resolution row is now GLOBAL taxonomy — carries `id` + the RESOLVED
    // term name, NOT the raw '4K' value, and NO `name` key.
    $global = collect($captured)->firstWhere('id', 3429);
    expect($global)->not->toBeNull();
    expect($global)->toBe([
        'id' => 3429,
        'options' => ['4K UHD (3840x2160)'],
        'position' => 0,
        'visible' => true,
        'variation' => false,
    ]);

    // MPN stays LOCAL (name + raw value).
    $mpn = collect($captured)->firstWhere('name', 'MPN');
    expect($mpn)->not->toBeNull();
    expect($mpn['options'])->toBe(['ABC-123']);
    expect($mpn['visible'])->toBeTrue();
    expect($mpn['variation'])->toBeFalse();

    // No LOCAL Resolution row leaked through (the old local shape is gone).
    expect(collect($captured)->firstWhere('name', 'Resolution'))->toBeNull();

    // The unmatched Colour is absent entirely — never sent (resolve-don't-invent).
    expect(collect($captured)->firstWhere('name', 'Colour'))->toBeNull();
    expect(collect($captured)->pluck('options')->flatten()->all())->not->toContain('Rainbow');

    // Global-first ordering: the taxonomy row precedes the local spec rows.
    expect($captured[0]['id'] ?? null)->toBe(3429);
});

it('path B: empty attributes_json → no `attributes` key sent (regression preserved)', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'NOATTR-GLOBAL-1',
        'name' => 'Bare Global Widget',
        'sell_price' => null,
        'attributes_json' => null,
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(fn (array $p): bool => ! array_key_exists('attributes', $p)))
        ->andReturn(['id' => 71002, 'slug' => 'bare-global-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator, t3NoBrandsTaxonomy(), t3NoopBrandResolver(), t3NoStockResolver());
});

it('path B: all-unmatched attributes_json → no `attributes` key sent', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'ALLUNMATCHED-1',
        'name' => 'All Unmatched Widget',
        'sell_price' => null,
        'attributes_json' => [
            // Colour is mappable (pa_colour 3268) but has NO cached term → unmatched.
            ['name' => 'Colour', 'value' => 'Rainbow'],
        ],
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(fn (array $p): bool => ! array_key_exists('attributes', $p)))
        ->andReturn(['id' => 71003, 'slug' => 'all-unmatched-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator, t3NoBrandsTaxonomy(), t3NoopBrandResolver(), t3NoStockResolver());
});
