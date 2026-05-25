<?php

declare(strict_types=1);

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 2 — PublishProductJob  (+ core-loop #3b create-on-Woo)
|--------------------------------------------------------------------------
| Covers:
|   - Queue routing + tries via constructor.
|   - Path A (has woo_product_id): PUT status=publish (NO leading slash) →
|     flips published + fires ProductPublished.
|   - Path B (#3b, no woo_product_id): POST /products with the full draft
|     payload → back-fills woo_product_id + slug → flips published + event.
|   - Shadow mode (WOO_WRITE_ENABLED=false → shadow_mode response): the row is
|     NOT marked published and NO event fires (it stays in review).
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

it('constructor sets queue=sync-woo-push and tries=3', function (): void {
    $job = new PublishProductJob(productId: 1, publishedByUserId: 99);

    expect($job->queue)->toBe('sync-woo-push');
    expect($job->tries)->toBe(3);
    expect($job->productId)->toBe(1);
    expect($job->publishedByUserId)->toBe(99);
});

it('path A: PUTs status=publish (no leading slash) + flips published + fires event', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => 500,
        'auto_create_status' => 'approved',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->with('products/500', ['status' => 'publish'])
        ->once()
        ->andReturn(['id' => 500, 'status' => 'publish']);
    $woo->shouldNotReceive('post');

    $job = new PublishProductJob(productId: (int) $product->id, publishedByUserId: 7);
    $job->handle($woo, new PriceCalculator);

    $product->refresh();
    expect($product->auto_create_status)->toBe('published');
    expect($product->status)->toBe('publish');

    Event::assertDispatched(ProductPublished::class, function (ProductPublished $e) use ($product): bool {
        return $e->productId === (int) $product->id
            && $e->wooProductId === 500
            && $e->publishedByUserId === 7;
    });
});

it('path B (#3b): creates the auto-draft on Woo + back-fills woo_product_id + publishes', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'NEW-SKU-1',
        'name' => 'Acme Widget',
        'slug' => 'acme-widget',
        'type' => 'simple',
        'sell_price' => 120.00,
        'short_description' => 'Short blurb',
        'long_description' => '<p>Long body</p>',
        'meta_description' => 'Meta blurb',
        'category_id' => 42,
        'category_ids' => [42, 99],
        'image_url' => 'https://ms.example/storage/auto-create-images/acme-widget-main.webp',
        'gallery_image_urls' => ['https://ms.example/storage/auto-create-images/acme-widget-2.webp'],
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(function (array $payload): bool {
            return $payload['name'] === 'Acme Widget'
                && $payload['sku'] === 'NEW-SKU-1'
                && $payload['slug'] === 'acme-widget'
                && $payload['status'] === 'publish'
                && $payload['type'] === 'simple'
                && $payload['regular_price'] === '120.00'
                && $payload['short_description'] === 'Short blurb'
                && $payload['description'] === '<p>Long body</p>'
                && $payload['categories'] === [['id' => 42], ['id' => 99]]
                && $payload['images'] === [
                    ['src' => 'https://ms.example/storage/auto-create-images/acme-widget-main.webp'],
                    ['src' => 'https://ms.example/storage/auto-create-images/acme-widget-2.webp'],
                ];
        }))
        ->andReturn(['id' => 90210, 'slug' => 'acme-widget-2']);

    $job = new PublishProductJob(productId: (int) $product->id, publishedByUserId: 3);
    $job->handle($woo, new PriceCalculator);

    $product->refresh();
    expect((int) $product->woo_product_id)->toBe(90210);
    expect($product->slug)->toBe('acme-widget-2'); // Woo-reconciled slug
    expect($product->auto_create_status)->toBe('published');
    expect($product->status)->toBe('publish');

    Event::assertDispatched(ProductPublished::class, function (ProductPublished $e) use ($product): bool {
        return $e->productId === (int) $product->id
            && $e->wooProductId === 90210
            && $e->publishedByUserId === 3;
    });
});

it('shadow mode: path B does NOT mark published and fires no event (stays in review)', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'SHADOW-1',
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->andReturn(['shadow_mode' => true, 'diff_id' => 7]); // WOO_WRITE_ENABLED=false

    $job = new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1);
    $job->handle($woo, new PriceCalculator);

    $product->refresh();
    expect($product->woo_product_id)->toBeNull();
    expect($product->auto_create_status)->toBe('draft'); // still in the review inbox
    expect($product->status)->toBe('draft');

    Event::assertNotDispatched(ProductPublished::class);
});

it('shadow mode: path A does NOT mark published either', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => 500,
        'auto_create_status' => 'approved',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->once()
        ->andReturn(['shadow_mode' => true, 'diff_id' => 8]);

    $job = new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1);
    $job->handle($woo, new PriceCalculator);

    $product->refresh();
    expect($product->auto_create_status)->toBe('approved'); // unchanged
    expect($product->status)->toBe('draft');

    Event::assertNotDispatched(ProductPublished::class);
});
