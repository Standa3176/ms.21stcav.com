<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260708-kg4 — WooGalleryPublisher Pest test (the core proof)
|--------------------------------------------------------------------------
|
| WooGalleryPublisher makes the Woo Maintenance 'Source images' fix END-TO-END:
| after a real image is sourced locally it PUTs the new gallery to the product's
| EXISTING Woo product (a Woo images-PUT REPLACES the gallery → the placeholder
| is removed) and bumps the local woo_image_count so the dashboard 'missing
| images' count drops immediately.
|
| Proves:
|   1. LIVE product (woo_product_id=555) + 2 urls → publish() returns true;
|      WooClient::put received ('products/555', payload) where
|      payload['images'] === [['src'=>url1],['src'=>url2]]; woo_image_count == 2.
|   2. NOT live (woo_product_id null) → false; WooClient::put NOT called; count unchanged.
|   3. Empty / blank urls → false; WooClient::put NOT called.
|
| A Mockery WooClient stub is bound via app()->instance so no real Woo call
| happens (mirrors the ProcessAutoCreateImageJobTest convention). The full
| SourceProductImagesCommand is NOT driven end-to-end — its mysqli supplier_db +
| Icecat/web/vision pipeline has no test harness, and the command's push is a
| one-line call to this tested publisher.
*/

use App\Domain\ProductAutoCreate\Services\WooGalleryPublisher;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('publishes the gallery to the live Woo product (replace payload) and bumps woo_image_count', function (): void {
    $url1 = 'https://ops.meetingstore.co.uk/img/widget-main.webp';
    $url2 = 'https://ops.meetingstore.co.uk/img/widget-2.webp';

    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'KG4-LIVE',
        'woo_product_id' => 555,
        'woo_image_count' => 0,
    ]);

    $capturedEndpoint = null;
    $capturedPayload = null;

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->once()
        ->with(Mockery::capture($capturedEndpoint), Mockery::capture($capturedPayload))
        ->andReturn([]);
    app()->instance(WooClient::class, $woo);

    $published = app(WooGalleryPublisher::class)->publish($product, [$url1, $url2]);

    expect($published)->toBeTrue();
    expect($capturedEndpoint)->toBe('products/555');
    expect($capturedPayload['images'])->toBe([['src' => $url1], ['src' => $url2]]);

    // Dashboard reflects the fix immediately (no wait for the nightly reconcile).
    expect($product->fresh()->woo_image_count)->toBe(2);
});

it('SKIPS a product that is not live on Woo (no woo_product_id) — no Woo call, count unchanged', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'KG4-DRAFT',
        'woo_product_id' => null,
        'woo_image_count' => null,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $published = app(WooGalleryPublisher::class)->publish($product, [
        'https://ops.meetingstore.co.uk/img/x.webp',
    ]);

    expect($published)->toBeFalse();
    expect($product->fresh()->woo_image_count)->toBeNull();
});

it('SKIPS when there are no real images — never pushes a placeholder', function (): void {
    /** @var Product $product */
    $product = Product::factory()->create([
        'sku' => 'KG4-NOIMG',
        'woo_product_id' => 777,
        'woo_image_count' => 0,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    // Both an empty array and an all-empty-string array filter down to nothing.
    expect(app(WooGalleryPublisher::class)->publish($product, []))->toBeFalse();
    expect(app(WooGalleryPublisher::class)->publish($product, ['', '']))->toBeFalse();

    expect($product->fresh()->woo_image_count)->toBe(0);
});
