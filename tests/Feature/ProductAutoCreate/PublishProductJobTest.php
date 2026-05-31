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

it('path B: includes attributes[] in the WC POST when attributes_json is populated (Flatsome layout parity)', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'ATTR-SKU-1',
        'name' => 'Spec-Rich Widget',
        'sell_price' => 50.00,
        'attributes_json' => [
            ['name' => 'Brand', 'value' => 'Acme'],
            ['name' => 'Resolution', 'value' => '4K UHD'],
            ['name' => 'Connection', 'value' => 'USB-C'],
            ['name' => '   ', 'value' => 'should be dropped'],   // blank name → skipped
            ['name' => 'Mount', 'value' => '   '],                 // blank value → skipped
            ['name' => 'brand', 'value' => 'Acme Duplicate'],       // case-dup of "Brand" → last wins
        ],
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(function (array $payload): bool {
            if (! isset($payload['attributes']) || ! is_array($payload['attributes'])) {
                return false;
            }
            // 3 rows: Brand (case-dup overwrites first), Resolution, Connection.
            // Blank-name + blank-value rows dropped.
            $names = array_column($payload['attributes'], 'name');

            return $payload['attributes'][0] === [
                'name' => 'brand',
                'options' => ['Acme Duplicate'],
                'position' => 0,
                'visible' => true,
                'variation' => false,
            ]
                && in_array('Resolution', $names, true)
                && in_array('Connection', $names, true)
                && count($payload['attributes']) === 3;
        }))
        ->andReturn(['id' => 12345, 'slug' => 'spec-rich-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator);
});

it('path B: includes global_unique_id when product has an EAN, omits the key when null', function (): void {
    Event::fake([ProductPublished::class]);

    // With EAN — should include global_unique_id.
    $withEan = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'EAN-SKU-1',
        'name' => 'GTIN Widget',
        'sell_price' => 25.00,
        'ean' => '7090043790993',
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo1 = Mockery::mock(WooClient::class);
    $woo1->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(fn (array $p): bool => ($p['global_unique_id'] ?? null) === '7090043790993'))
        ->andReturn(['id' => 1001, 'slug' => 'gtin-widget']);

    (new PublishProductJob(productId: (int) $withEan->id, publishedByUserId: 1))
        ->handle($woo1, new PriceCalculator);

    // Without EAN — must not include the key (Woo would otherwise store an empty GTIN).
    $withoutEan = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'NO-EAN-1',
        'name' => 'No GTIN Widget',
        'sell_price' => 10.00,
        'ean' => null,
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo2 = Mockery::mock(WooClient::class);
    $woo2->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(fn (array $p): bool => ! array_key_exists('global_unique_id', $p)))
        ->andReturn(['id' => 1002, 'slug' => 'no-gtin-widget']);

    (new PublishProductJob(productId: (int) $withoutEan->id, publishedByUserId: 1))
        ->handle($woo2, new PriceCalculator);
});

it('path B: NEVER includes brands payload key, even when brand_id is set', function (): void {
    // 2026-05-31 investigation showed pushing `brands: [{id}]` to the WC
    // products endpoint is silently dropped on meetingstore.co.uk — the live
    // storefront brand display ("Brand: <link>") comes from a curated
    // `product_brand` taxonomy (WP REST `/wp/v2/product_brand`, URL
    // `/brand/<slug>/`) that the WC products endpoint doesn't expose.
    // Until product_brand integration ships, brand surfaces in three other
    // places: product title, tags (brand-as-first-tag), and the attributes
    // spec-table "Brand" row. Pushing the dead `brands[]` key is wasted
    // bytes that confuses future readers — assert it's never present.
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'BRAND-1',
        'name' => 'Branded Widget',
        'sell_price' => 50.00,
        'brand_id' => 2907,
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(fn (array $p): bool => ! array_key_exists('brands', $p)))
        ->andReturn(['id' => 4321, 'slug' => 'branded-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator);
});

it('path B: pushes tags as [{name: ...}] from products.tags, deduping + dropping blanks', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'TAG-1',
        'name' => 'Tagged Widget',
        'sell_price' => 25.00,
        'tags' => ['Conferencing', '', '  Conferencing  ', 'Bestseller', '   '],
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(function (array $payload): bool {
            if (! isset($payload['tags']) || ! is_array($payload['tags'])) {
                return false;
            }
            $names = array_column($payload['tags'], 'name');

            return $names === ['Conferencing', 'Bestseller'];
        }))
        ->andReturn(['id' => 5555, 'slug' => 'tagged-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator);
});

it('path B: omits tags payload key when products.tags is null or empty', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'NOTAG-1',
        'name' => 'Untagged Widget',
        'sell_price' => 10.00,
        'tags' => [],
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(fn (array $p): bool => ! array_key_exists('tags', $p)))
        ->andReturn(['id' => 6666, 'slug' => 'untagged-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator);
});

it('path B: omits attributes payload key when attributes_json is null or empty (no empty Woo attributes)', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'NOATTR-1',
        'name' => 'Bare Widget',
        'sell_price' => 10.00,
        'attributes_json' => null,
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('post')
        ->once()
        ->with('products', Mockery::on(function (array $payload): bool {
            // attributes key must NOT be present at all — Woo would otherwise
            // create empty global attribute rows on the store.
            return ! array_key_exists('attributes', $payload);
        }))
        ->andReturn(['id' => 555, 'slug' => 'bare-widget']);

    (new PublishProductJob(productId: (int) $product->id, publishedByUserId: 1))
        ->handle($woo, new PriceCalculator);
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
