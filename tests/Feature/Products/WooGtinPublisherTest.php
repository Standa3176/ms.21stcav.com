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

/*
|--------------------------------------------------------------------------
| 260905-ae5 — the publisher is the choke point for Woo GTIN writes
|--------------------------------------------------------------------------
|
| It used to PUT whatever string it was handed. products:publish-sourced-eans
| has no gate of its own, so on 2026-08-28 it came within one SKU of pushing
| 61U3010000AC's fabricated `613010000` live, purely because that SKU was still
| in a --skus list. Refusing here means no caller can do it, present or future.
*/

it('refuses a barcode whose check digit fails', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');

    $product = Product::factory()->create(['woo_product_id' => 555, 'ean' => '5099206131250']);

    expect((new WooGtinPublisher($woo))->publish($product, '5099206131250'))->toBe('invalid');
});

it('refuses a GS1 prefix padded out with zeros even though it checksums', function (): void {
    // 4934292000003 — prefix + five zeros + check digit 3. Vision's feed emits
    // these wholesale and they pass mod-10 perfectly well.
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');

    $product = Product::factory()->create(['woo_product_id' => 556, 'ean' => '4934292000003']);

    expect((new WooGtinPublisher($woo))->publish($product, '4934292000003'))->toBe('invalid');
});

it('leaves the local ean ALONE when it refuses', function (): void {
    // This is a publisher, not a cleaner. A refused value is a data-quality
    // question for products:identity-health-check — destroying it here would
    // lose the evidence of what the feed actually said.
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');

    $product = Product::factory()->create(['woo_product_id' => 557, 'ean' => '6936420000000']);

    (new WooGtinPublisher($woo))->publish($product, '6936420000000');

    expect($product->fresh()->ean)->toBe('6936420000000');
});

it('still publishes a genuine GTIN', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')->once()
        ->with('products/558', ['global_unique_id' => '5099206131255'])
        ->andReturn(['id' => 558]);

    $product = Product::factory()->create(['woo_product_id' => 558, 'ean' => '5099206131255']);

    expect((new WooGtinPublisher($woo))->publish($product, '5099206131255'))->toBe('published');
    expect($product->fresh()->woo_gtin)->toBe('5099206131255');
});

it('still publishes a genuine 12-digit UPC-A', function (): void {
    // DU7099Z-BK is stored unpadded and Google accepts UPC-A; refusing it would
    // be a false alarm on correct data.
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')->once()
        ->with('products/559', ['global_unique_id' => '813097025876'])
        ->andReturn(['id' => 559]);

    $product = Product::factory()->create(['woo_product_id' => 559, 'ean' => '813097025876']);

    expect((new WooGtinPublisher($woo))->publish($product, '813097025876'))->toBe('published');
});
