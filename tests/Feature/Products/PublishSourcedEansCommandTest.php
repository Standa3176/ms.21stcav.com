<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260708-pw3 — products:publish-sourced-eans feature test
|--------------------------------------------------------------------------
|
| The command pushes ALREADY-BACKFILLED local EANs to Woo's GTIN
| (global_unique_id) with ZERO re-lookup cost — it reads Product.ean and calls
| the real WooGtinPublisher (260708-pw3), which does the Woo products-PUT and
| bumps woo_gtin.
|
| A Mockery WooClient stub is bound via app()->instance so the REAL
| WooGtinPublisher runs against it (no real Woo call) — mirrors
| PublishSourcedImagesCommandTest. We assert put() call counts + endpoints to
| prove the selection logic:
|
|   P1: publish, woo #101, ean=EAN-P1, woo_gtin=null → the backlog SKU
|   P2: publish, woo NULL, ean=EAN-P2               → draft, never selected
|   P3: publish, woo #103, ean=null                 → no EAN, never selected
|   P4: publish, woo #104, ean=EAN-P4, woo_gtin=set → already on Woo
|
| Cases:
|   - No --skus  → publishes ONLY P1 (put once for products/101; P4 excluded by
|     the woo_gtin filter; P2/P3 not selected). P1.woo_gtin → EAN-P1.
|   - --dry-run  → put received ZERO times; output lists P1.
|   - --skus=<P4> → force-publishes P4 (put for products/104) even though it
|     already had a GTIN.
|   - --skus=<P2,P3> → neither reaches the publisher (no put).
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
function seedEanBacklog(): array
{
    /** @var Product $p1 */
    $p1 = Product::factory()->create([
        'sku' => 'PW3-BACKLOG',
        'status' => 'publish',
        'woo_product_id' => 101,
        'ean' => 'EAN-P1',
        'woo_gtin' => null,
    ]);

    /** @var Product $p2 */
    $p2 = Product::factory()->create([
        'sku' => 'PW3-DRAFT',
        'status' => 'publish',
        'woo_product_id' => null,
        'ean' => 'EAN-P2',
        'woo_gtin' => null,
    ]);

    /** @var Product $p3 */
    $p3 = Product::factory()->create([
        'sku' => 'PW3-NOEAN',
        'status' => 'publish',
        'woo_product_id' => 103,
        'ean' => null,
        'woo_gtin' => null,
    ]);

    /** @var Product $p4 */
    $p4 = Product::factory()->create([
        'sku' => 'PW3-DONE',
        'status' => 'publish',
        'woo_product_id' => 104,
        'ean' => 'EAN-P4',
        'woo_gtin' => 'EAN-P4',
    ]);

    return ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4];
}

it('auto-selects and publishes ONLY the live+ean+missing-on-Woo backlog product', function (): void {
    seedEanBacklog();

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

    $this->artisan('products:publish-sourced-eans')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local EANs to Woo GTIN for 1 product(s).')
        ->expectsOutputToContain('Done — 1 published, 0 collisions (duplicate GTIN — local EAN cleared), 0 skipped.');

    // Only P1's Woo product was touched — P4 excluded by the woo_gtin filter;
    // P2 (no woo id) + P3 (no ean) never selected.
    expect($endpoints)->toBe(['products/101']);

    // Dashboard reflects the fix immediately.
    expect(Product::where('sku', 'PW3-BACKLOG')->first()->woo_gtin)->toBe('EAN-P1');
});

it('--dry-run writes NOTHING to Woo but lists the selection', function (): void {
    seedEanBacklog();

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:publish-sourced-eans --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY-RUN — Publishing local EANs to Woo GTIN for 1 product(s).')
        ->expectsOutputToContain('would publish PW3-BACKLOG (Woo #101, EAN EAN-P1)')
        ->expectsOutputToContain('DRY-RUN complete');

    // woo_gtin untouched — no write happened.
    expect(Product::where('sku', 'PW3-BACKLOG')->first()->woo_gtin)->toBeNull();
});

it('--skus force-publishes a specific SKU even when it already has a Woo GTIN', function (): void {
    seedEanBacklog();

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

    // P4 already has woo_gtin — auto-select would skip it, but --skus forces it.
    $this->artisan('products:publish-sourced-eans --skus=PW3-DONE')
        ->assertExitCode(0)
        ->expectsOutputToContain('Done — 1 published, 0 collisions (duplicate GTIN — local EAN cleared), 0 skipped.');

    expect($endpoints)->toBe(['products/104']);
});

it('--skus skips the null-woo_product_id and no-ean products (never pushes them)', function (): void {
    seedEanBacklog();

    $woo = Mockery::mock(WooClient::class);
    // P2 (no woo id) is excluded by whereNotNull('woo_product_id'); P3 (no ean)
    // by the whereNotNull('ean') filter — neither reaches the publisher.
    $woo->shouldNotReceive('put');
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:publish-sourced-eans --skus=PW3-DRAFT,PW3-NOEAN')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local EANs to Woo GTIN for 0 product(s).')
        ->expectsOutputToContain('Done — 0 published, 0 collisions (duplicate GTIN — local EAN cleared), 0 skipped.');
});

it('--limit caps how many products are processed', function (): void {
    // Two eligible backlog products; --limit=1 processes only one.
    Product::factory()->create([
        'sku' => 'PW3-A',
        'status' => 'publish',
        'woo_product_id' => 301,
        'ean' => 'EAN-A',
        'woo_gtin' => null,
    ]);
    Product::factory()->create([
        'sku' => 'PW3-B',
        'status' => 'publish',
        'woo_product_id' => 302,
        'ean' => 'EAN-B',
        'woo_gtin' => null,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')->once()->andReturn([]);
    app()->instance(WooClient::class, $woo);

    $this->artisan('products:publish-sourced-eans --limit=1')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local EANs to Woo GTIN for 1 product(s).')
        ->expectsOutputToContain('Done — 1 published, 0 collisions (duplicate GTIN — local EAN cleared), 0 skipped.');
});

it('registers the products:publish-sourced-eans command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('products:publish-sourced-eans');
});
