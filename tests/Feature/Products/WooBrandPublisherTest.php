<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260709-gj2 — WooBrandPublisher Pest test (the core proof)
|--------------------------------------------------------------------------
|
| WooBrandPublisher makes a product's local brand END-TO-END: it resolves the
| brand NAME to its product_brand taxonomy term (ProductBrandTermResolver::
| getTermIdForName), assigns it to the product's EXISTING Woo product
| (assignToProduct), and bumps the local woo_brand_count so the Woo Maintenance
| 'missing brand' count drops. Brand-only — no price/tag side-effects.
|
| When the brand isn't in the product_brand taxonomy yet, getTermIdForName
| returns null → publish() reports 'no_term' (create the brand first — NOT
| auto-created here) without touching Woo. When the product isn't live, the
| brand name is blank, or the assign fails, it reports 'skipped'.
|
| Proves:
|   1. LIVE product (woo_product_id=301) + brand 'Yealink', resolver term=42,
|      assignToProduct(301,[42])=true → 'published'; woo_brand_count == 1.
|   2. resolver returns null → 'no_term'; assignToProduct NEVER called;
|      woo_brand_count unchanged.
|   3. woo_product_id null → 'skipped'; resolver NEVER touched.
|   4. blank brand name → 'skipped'; resolver NEVER touched.
|   5. assignToProduct returns false → 'skipped'; woo_brand_count unchanged.
|
| A Mockery ProductBrandTermResolver stub is bound via app()->instance so no
| real WP REST call happens (mirrors WooGtinPublisherTest).
*/

use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\ProductAutoCreate\Services\WooBrandPublisher;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the brand, assigns the product_brand term to the live Woo product, and bumps woo_brand_count', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'GJ2-LIVE',
        'woo_product_id' => 301,
        'woo_brand_count' => 0,
    ]);

    $resolver = Mockery::mock(ProductBrandTermResolver::class);
    $resolver->shouldReceive('getTermIdForName')->once()->with('Yealink')->andReturn(42);
    $resolver->shouldReceive('assignToProduct')->once()->with(301, [42])->andReturnTrue();
    app()->instance(ProductBrandTermResolver::class, $resolver);

    $result = app(WooBrandPublisher::class)->publish($product, 'Yealink');

    expect($result)->toBe('published');

    // Dashboard reflects the fix immediately (no wait for the nightly reconcile).
    expect($product->fresh()->woo_brand_count)->toBe(1);
});

it('reports no_term when the brand is not in the product_brand taxonomy — never assigns, count untouched', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'GJ2-NOTERM',
        'woo_product_id' => 302,
        'woo_brand_count' => 0,
    ]);

    $resolver = Mockery::mock(ProductBrandTermResolver::class);
    $resolver->shouldReceive('getTermIdForName')->once()->with('Obscure')->andReturnNull();
    $resolver->shouldNotReceive('assignToProduct');
    app()->instance(ProductBrandTermResolver::class, $resolver);

    expect(app(WooBrandPublisher::class)->publish($product, 'Obscure'))->toBe('no_term');
    expect($product->fresh()->woo_brand_count)->toBe(0);
});

it('SKIPS a product that is not live on Woo (no woo_product_id) — resolver never touched', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'GJ2-DRAFT',
        'woo_product_id' => null,
        'woo_brand_count' => 0,
    ]);

    $resolver = Mockery::mock(ProductBrandTermResolver::class);
    $resolver->shouldNotReceive('getTermIdForName');
    $resolver->shouldNotReceive('assignToProduct');
    app()->instance(ProductBrandTermResolver::class, $resolver);

    expect(app(WooBrandPublisher::class)->publish($product, 'Yealink'))->toBe('skipped');
    expect($product->fresh()->woo_brand_count)->toBe(0);
});

it('SKIPS when the brand name is blank — resolver never touched', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'GJ2-NOBRAND',
        'woo_product_id' => 303,
        'woo_brand_count' => 0,
    ]);

    $resolver = Mockery::mock(ProductBrandTermResolver::class);
    $resolver->shouldNotReceive('getTermIdForName');
    $resolver->shouldNotReceive('assignToProduct');
    app()->instance(ProductBrandTermResolver::class, $resolver);

    expect(app(WooBrandPublisher::class)->publish($product, ''))->toBe('skipped');
    expect(app(WooBrandPublisher::class)->publish($product, '   '))->toBe('skipped');
    expect($product->fresh()->woo_brand_count)->toBe(0);
});

it('SKIPS when assignToProduct fails — woo_brand_count untouched', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'GJ2-ASSIGNFAIL',
        'woo_product_id' => 304,
        'woo_brand_count' => 0,
    ]);

    $resolver = Mockery::mock(ProductBrandTermResolver::class);
    $resolver->shouldReceive('getTermIdForName')->once()->with('Yealink')->andReturn(42);
    $resolver->shouldReceive('assignToProduct')->once()->with(304, [42])->andReturnFalse();
    app()->instance(ProductBrandTermResolver::class, $resolver);

    expect(app(WooBrandPublisher::class)->publish($product, 'Yealink'))->toBe('skipped');
    expect($product->fresh()->woo_brand_count)->toBe(0);
});
