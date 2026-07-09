<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260708-pw3 — WooGtinPublisher Pest test (the core proof)
|--------------------------------------------------------------------------
|
| WooGtinPublisher makes the EAN backfill END-TO-END: after an EAN is backfilled
| locally it PUTs {global_unique_id} to the product's EXISTING Woo product and
| bumps the local woo_gtin so the Woo Maintenance 'missing EAN' count drops.
|
| WC 9.x rejects DUPLICATE GTINs (suppliers share one EAN across variants). On
| that specific rejection the publisher does NOT rethrow — it clears the local
| ean (so it stops colliding) and returns 'collision'; woo_gtin stays null. This
| mirrors PublishProductJob Path B (the only other global_unique_id write).
|
| Proves:
|   1. LIVE product (woo_product_id=201) + ean → publish() returns 'published';
|      WooClient::put received ('products/201', ['global_unique_id'=>ean]);
|      woo_gtin == ean.
|   2. COLLISION: put throws a RuntimeException containing
|      'product_invalid_global_unique_id' → returns 'collision'; ean now null;
|      woo_gtin still null; NO exception escapes.
|   3. NON-collision error: put throws a generic RuntimeException('boom') →
|      publish() rethrows.
|   4. woo_product_id null → 'skipped', put NOT called. Empty ean → 'skipped'.
|
| A Mockery WooClient stub is bound via app()->instance so no real Woo call
| happens (mirrors WooGalleryPublisherTest).
*/

use App\Domain\ProductAutoCreate\Services\WooGtinPublisher;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('publishes the EAN to the live Woo product GTIN (global_unique_id) and bumps woo_gtin', function (): void {
    $ean = '5012345678900';

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'PW3-LIVE',
        'woo_product_id' => 201,
        'ean' => $ean,
        'woo_gtin' => null,
    ]);

    $capturedEndpoint = null;
    $capturedPayload = null;

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->once()
        ->with(Mockery::capture($capturedEndpoint), Mockery::capture($capturedPayload))
        ->andReturn([]);
    app()->instance(WooClient::class, $woo);

    $result = app(WooGtinPublisher::class)->publish($product, $ean);

    expect($result)->toBe('published');
    expect($capturedEndpoint)->toBe('products/201');
    expect($capturedPayload)->toBe(['global_unique_id' => $ean]);

    // Dashboard reflects the fix immediately (no wait for the nightly reconcile).
    expect($product->fresh()->woo_gtin)->toBe($ean);
});

it('handles a WC 9.x duplicate-GTIN COLLISION — clears the local EAN, returns collision, no exception escapes', function (): void {
    $ean = '5012345678900';

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'PW3-COLLIDE',
        'woo_product_id' => 202,
        'ean' => $ean,
        'woo_gtin' => null,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->once()
        ->andThrow(new RuntimeException('woocommerce_rest_product_invalid_global_unique_id: duplicate GTIN'));
    app()->instance(WooClient::class, $woo);

    $result = app(WooGtinPublisher::class)->publish($product, $ean);

    expect($result)->toBe('collision');

    // Local EAN cleared so it stops colliding; woo_gtin never gets set.
    $fresh = $product->fresh();
    expect($fresh->ean)->toBeNull();
    expect($fresh->woo_gtin)->toBeNull();
});

it('RETHROWS a non-collision Woo error (real failure the caller/queue must see)', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'PW3-BOOM',
        'woo_product_id' => 203,
        'ean' => '5012345678900',
        'woo_gtin' => null,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->once()
        ->andThrow(new RuntimeException('boom'));
    app()->instance(WooClient::class, $woo);

    expect(fn () => app(WooGtinPublisher::class)->publish($product, '5012345678900'))
        ->toThrow(RuntimeException::class, 'boom');

    // Not a collision — the local EAN is NOT cleared.
    expect($product->fresh()->ean)->toBe('5012345678900');
});

it('SKIPS a product that is not live on Woo (no woo_product_id) — no Woo call', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'PW3-DRAFT',
        'woo_product_id' => null,
        'ean' => '5012345678900',
        'woo_gtin' => null,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    expect(app(WooGtinPublisher::class)->publish($product, '5012345678900'))->toBe('skipped');
    expect($product->fresh()->woo_gtin)->toBeNull();
});

it('SKIPS when the EAN is blank — no Woo call', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'PW3-NOEAN',
        'woo_product_id' => 204,
        'ean' => null,
        'woo_gtin' => null,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    expect(app(WooGtinPublisher::class)->publish($product, ''))->toBe('skipped');
    expect(app(WooGtinPublisher::class)->publish($product, '   '))->toBe('skipped');
    expect($product->fresh()->woo_gtin)->toBeNull();
});
