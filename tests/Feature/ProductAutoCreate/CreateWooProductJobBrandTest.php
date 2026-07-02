<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\ProductAutoCreate\Jobs\CreateWooProductJob;
use App\Domain\ProductAutoCreate\Services\CompletenessScorer;
use App\Domain\ProductAutoCreate\Services\ProductContentBuilder;
use App\Domain\ProductAutoCreate\Services\ProductMatcher;
use App\Domain\ProductAutoCreate\Services\ProductSlugGenerator;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\ProductAutoCreate\Services\WooBrandCreator;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Quick task 260702-qd8 — CreateWooProductJob auto-creates the missing brand
|--------------------------------------------------------------------------
| The per-row "Approve — create product" path (CreateWooProductJob step 7):
| when TaxonomyResolver::resolveBrand returns null AND
| config('product_auto_create.auto_create_missing_brands') is true, the job now
| asks WooBrandCreator to create/resolve the brand and uses that id — so a real
| (non-junk) brand no longer forces the needs_brand_or_category_assignment park;
| the product proceeds to the Woo POST. A junk/failed brand (creator → null) or
| the config switch OFF still parks exactly as before.
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

/**
 * Run the job's handle() with the WooBrandCreator injected as the final arg.
 * (Distinct from CreateWooProductJobTest's runCreateJob to avoid redeclaring it.)
 */
function runCreateJobWithBrand(
    string $sku,
    WooClient $woo,
    SupplierClient $supplier,
    TaxonomyResolver $taxonomy,
    WooBrandCreator $brandCreator,
    ?RuleResolver $ruleResolver = null,
    ?PriceCalculator $calculator = null,
): void {
    $job = new CreateWooProductJob($sku);
    $job->handle(
        $woo,
        $supplier,
        app(ProductContentBuilder::class),
        app(ProductSlugGenerator::class),
        app(ProductMatcher::class),
        $taxonomy,
        $ruleResolver ?? app(RuleResolver::class),
        $calculator ?? app(PriceCalculator::class),
        app(CompletenessScorer::class),
        $brandCreator,
    );
}

it('auto-creates a real (non-Woo) brand and pushes the product to Woo', function (): void {
    config(['product_auto_create.auto_create_missing_brands' => true]);
    Event::fake();
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'S4.04-B-EB-GD5',
        'name' => 'Trantec Beltpack Transmitter',
        'brand' => 'Trantec',
        'category' => 'Microphones',
        // price 0 → computeSellPennies short-circuits (no RuleResolver/PriceCalculator
        // call), keeping the POST path while avoiding the final-class mock limitation.
        'price' => 0,
    ]);

    // Brand not on Woo, category resolvable (so category never short-circuits).
    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(null);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(42);

    // Creator mints a fresh Woo brand term id.
    $brandCreator = Mockery::mock(WooBrandCreator::class);
    $brandCreator->shouldReceive('ensureBrandTermId')->once()->with('Trantec')->andReturn(555);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);              // no slug collision
    $woo->shouldReceive('post')->once()->andReturn([        // product POST occurs
        'id' => 9001,
        'slug' => 'trantec-beltpack-transmitter',
    ]);

    runCreateJobWithBrand('S4.04-B-EB-GD5', $woo, $supplier, $taxonomy, $brandCreator);

    $product = Product::where('sku', 'S4.04-B-EB-GD5')->first();
    expect($product)->not->toBeNull();
    expect($product->brand_id)->toBe(555);
    expect($product->auto_create_status)->toBe('draft');
    expect($product->woo_product_id)->toBe(9001);
});

it('parks a junk brand — creator returns null, no Woo product POST', function (): void {
    config(['product_auto_create.auto_create_missing_brands' => true]);
    Event::fake();
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'SPECIAL-01',
        'name' => 'Special Bundle',
        'brand' => 'Specials',
        'category' => 'Microphones',
        'price' => 200,
    ]);

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(null);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(42);

    $brandCreator = Mockery::mock(WooBrandCreator::class);
    $brandCreator->shouldReceive('ensureBrandTermId')->once()->with('Specials')->andReturn(null);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldNotReceive('post');   // parked → no product POST

    runCreateJobWithBrand('SPECIAL-01', $woo, $supplier, $taxonomy, $brandCreator);

    $product = Product::where('sku', 'SPECIAL-01')->first();
    expect($product)->not->toBeNull();
    expect($product->auto_create_status)->toBe('needs_brand_or_category_assignment');
    expect($product->woo_product_id)->toBeNull();
    expect($product->brand_id)->toBeNull();
});

it('parks (does not consult the creator) when the config switch is OFF', function (): void {
    config(['product_auto_create.auto_create_missing_brands' => false]);
    Event::fake();
    Queue::fake();

    $supplier = Mockery::mock(SupplierClient::class);
    $supplier->shouldReceive('fetchSingleProduct')->andReturn([
        'sku' => 'OFF-01',
        'name' => 'Trantec Beltpack',
        'brand' => 'Trantec',
        'category' => 'Microphones',
        'price' => 200,
    ]);

    $taxonomy = Mockery::mock(TaxonomyResolver::class);
    $taxonomy->shouldReceive('resolveBrand')->andReturn(null);
    $taxonomy->shouldReceive('resolveCategory')->andReturn(42);

    $brandCreator = Mockery::mock(WooBrandCreator::class);
    $brandCreator->shouldNotReceive('ensureBrandTermId');   // switch off → never consulted

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')->andReturn([]);
    $woo->shouldNotReceive('post');

    runCreateJobWithBrand('OFF-01', $woo, $supplier, $taxonomy, $brandCreator);

    $product = Product::where('sku', 'OFF-01')->first();
    expect($product)->not->toBeNull();
    expect($product->auto_create_status)->toBe('needs_brand_or_category_assignment');
    expect($product->woo_product_id)->toBeNull();
});
