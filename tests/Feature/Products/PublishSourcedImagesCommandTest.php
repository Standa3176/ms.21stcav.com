<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260708-mv0 — products:publish-sourced-images feature test
|--------------------------------------------------------------------------
|
| The command pushes ALREADY-SOURCED local galleries to Woo with ZERO
| re-sourcing cost — it reads Product.gallery_image_urls and calls the real
| WooGalleryPublisher (260708-kg4), which does the Woo images-PUT (replaces the
| gallery → removes the placeholder) and bumps woo_image_count.
|
| A Mockery WooClient stub is bound via app()->instance so the REAL
| WooGalleryPublisher runs against it (no real Woo call) — mirrors
| WooGalleryPublisherTest. We assert put() call counts + endpoints to prove the
| selection logic:
|
|   P1: publish, woo #101, gallery=[x.webp], woo_image_count=0  → the backlog SKU
|   P2: publish, woo NULL, gallery=[y.webp]                      → draft, never selected
|   P3: publish, woo #103, gallery=[]                            → empty, never selected
|   P4: publish, woo #104, gallery=[z.webp], woo_image_count=5   → already on Woo
|
| Cases:
|   - No --skus  → publishes ONLY P1 (put once for products/101; P4 excluded by
|     the woo_image_count filter; P2/P3 not selected). P1.woo_image_count → 1.
|   - --dry-run  → put received ZERO times; output lists P1.
|   - --skus=<P4> → force-publishes P4 (put for products/104) even though it
|     already had Woo images.
|   - --limit=1  → caps to a single product.
*/

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Seed the four-product fixture. Returns the created products keyed p1..p4.
 *
 * @return array{p1: Product, p2: Product, p3: Product, p4: Product}
 */
function seedPublishBacklog(): array
{
    /** @var Product $p1 */
    $p1 = Product::factory()->create([
        'sku' => 'MV0-BACKLOG',
        'status' => 'publish',
        'woo_product_id' => 101,
        'gallery_image_urls' => ['https://ops.meetingstore.co.uk/img/x.webp'],
        'woo_image_count' => 0,
    ]);

    /** @var Product $p2 */
    $p2 = Product::factory()->create([
        'sku' => 'MV0-DRAFT',
        'status' => 'publish',
        'woo_product_id' => null,
        'gallery_image_urls' => ['https://ops.meetingstore.co.uk/img/y.webp'],
        'woo_image_count' => null,
    ]);

    /** @var Product $p3 */
    $p3 = Product::factory()->create([
        'sku' => 'MV0-EMPTY',
        'status' => 'publish',
        'woo_product_id' => 103,
        'gallery_image_urls' => [],
        'woo_image_count' => 0,
    ]);

    /** @var Product $p4 */
    $p4 = Product::factory()->create([
        'sku' => 'MV0-DONE',
        'status' => 'publish',
        'woo_product_id' => 104,
        'gallery_image_urls' => ['https://ops.meetingstore.co.uk/img/z.webp'],
        'woo_image_count' => 5,
    ]);

    return ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4];
}

it('auto-selects and publishes ONLY the live+gallery+missing-on-Woo backlog product', function (): void {
    seedPublishBacklog();

    $endpoints = [];
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->once()
        ->with(Mockery::on(function ($endpoint) use (&$endpoints): bool {
            $endpoints[] = $endpoint;

            return true;
        }), Mockery::any())
        ->andReturn([]);
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:publish-sourced-images')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local galleries to Woo for 1 product(s).')
        ->expectsOutputToContain('Done — 1 published, 0 skipped.');

    // Only P1's Woo product was touched — P4 excluded by the woo_image_count
    // filter; P2 (no woo id) + P3 (empty gallery) never selected.
    expect($endpoints)->toBe(['products/101']);

    // Dashboard reflects the fix immediately.
    expect(Product::where('sku', 'MV0-BACKLOG')->first()->woo_image_count)->toBe(1);
});

it('--dry-run writes NOTHING to Woo but lists the selection', function (): void {
    seedPublishBacklog();

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:publish-sourced-images --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY-RUN — Publishing local galleries to Woo for 1 product(s).')
        ->expectsOutputToContain('would publish MV0-BACKLOG (Woo #101, 1 image(s))')
        ->expectsOutputToContain('DRY-RUN complete');

    // woo_image_count untouched — no write happened.
    expect(Product::where('sku', 'MV0-BACKLOG')->first()->woo_image_count)->toBe(0);
});

it('--skus force-publishes a specific SKU even when it already has Woo images', function (): void {
    seedPublishBacklog();

    $endpoints = [];
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->once()
        ->with(Mockery::on(function ($endpoint) use (&$endpoints): bool {
            $endpoints[] = $endpoint;

            return true;
        }), Mockery::any())
        ->andReturn([]);
    app()->instance(WooClient::class, $woo);

    // P4 already has woo_image_count=5 — auto-select would skip it, but --skus forces it.
    $this->artisan('products:publish-sourced-images --skus=MV0-DONE')
        ->assertExitCode(0)
        ->expectsOutputToContain('Done — 1 published, 0 skipped.');

    expect($endpoints)->toBe(['products/104']);
});

it('--skus skips the null-woo_product_id and empty-gallery products (never pushes them)', function (): void {
    seedPublishBacklog();

    $woo = Mockery::mock(WooClient::class);
    // P2 (no woo id) is excluded by whereNotNull; P3 (empty gallery) by the
    // != '[]' filter — neither reaches the publisher, so put is never called.
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:publish-sourced-images --skus=MV0-DRAFT,MV0-EMPTY')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local galleries to Woo for 0 product(s).')
        ->expectsOutputToContain('Done — 0 published, 0 skipped.');
});

it('--limit caps how many products are processed', function (): void {
    // Two eligible backlog products; --limit=1 processes only one.
    Product::factory()->create([
        'sku' => 'MV0-A',
        'status' => 'publish',
        'woo_product_id' => 201,
        'gallery_image_urls' => ['https://ops.meetingstore.co.uk/img/a.webp'],
        'woo_image_count' => 0,
    ]);
    Product::factory()->create([
        'sku' => 'MV0-B',
        'status' => 'publish',
        'woo_product_id' => 202,
        'gallery_image_urls' => ['https://ops.meetingstore.co.uk/img/b.webp'],
        'woo_image_count' => 0,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')->once()->andReturn([]);
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:publish-sourced-images --limit=1')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local galleries to Woo for 1 product(s).')
        ->expectsOutputToContain('Done — 1 published, 0 skipped.');
});

it('registers the products:publish-sourced-images command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('products:publish-sourced-images');
});
