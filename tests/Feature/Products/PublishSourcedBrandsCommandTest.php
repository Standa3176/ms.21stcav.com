<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260709-gj2 — products:publish-sourced-brands feature test
|--------------------------------------------------------------------------
|
| The command pushes ALREADY-SET local brands to Woo's product_brand taxonomy
| with ZERO re-derivation cost — it maps Product.brand_id → brand NAME via the
| live Woo brand list (products/brands) and calls the real WooBrandPublisher
| (260709-gj2), which resolves the product_brand term + assigns it + bumps
| woo_brand_count.
|
| A Mockery WooClient stub (returning a products/brands list [{id:7,name:'Yealink'}])
| is bound via app()->instance so the brand-name map is deterministic, and a
| Mockery ProductBrandTermResolver stub is bound so the REAL WooBrandPublisher runs
| against it (no real WP REST call) — mirrors PublishSourcedEansCommandTest. We
| assert assignToProduct call counts + Woo ids to prove the selection logic:
|
|   P1: publish, woo #201, brand_id=7, woo_brand_count=0 → the backlog SKU
|   P2: publish, woo NULL, brand_id=7                    → draft, never selected
|   P3: publish, woo #203, brand_id=null                 → no brand, never selected
|   P4: publish, woo #204, brand_id=7, woo_brand_count=1 → already on Woo
|
| Cases:
|   - No --skus  → publishes ONLY P1 (assignToProduct once for Woo #201; P4 excluded
|     by the woo_brand_count filter; P2/P3 not selected). P1.woo_brand_count → 1.
|   - --dry-run  → assignToProduct received ZERO times; output lists P1.
|   - --skus=<P4> → force-publishes P4 (assign for Woo #204) even though it already
|     had a brand.
|   - Command registered.
*/

use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Bind a WooClient stub whose products/brands list maps brand_id 7 → 'Yealink'.
 * Page 1 returns the single-brand list; any later page returns [] so the
 * command's paginator stops cleanly.
 */
function bindWooBrandList(): void
{
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('get')
        ->with('products/brands', Mockery::any())
        ->andReturnUsing(function (string $endpoint, array $query): array {
            return ((int) ($query['page'] ?? 1)) === 1
                ? [['id' => 7, 'name' => 'Yealink']]
                : [];
        });
    app()->instance(WooClient::class, $woo);
}

/**
 * Bind a ProductBrandTermResolver stub: 'Yealink' resolves to product_brand term
 * 77, and assignToProduct captures every Woo id it is asked to write.
 *
 * @param  array<int, int>  $assignedWooIds  captured by reference
 */
function bindBrandResolver(array &$assignedWooIds): void
{
    $resolver = Mockery::mock(ProductBrandTermResolver::class);
    $resolver->shouldReceive('getTermIdForName')->with('Yealink')->andReturn(77);
    $resolver->shouldReceive('assignToProduct')
        ->with(Mockery::on(function ($wooId) use (&$assignedWooIds): bool {
            $assignedWooIds[] = (int) $wooId;

            return true;
        }), [77])
        ->andReturnTrue();
    app()->instance(ProductBrandTermResolver::class, $resolver);
}

/**
 * Seed the four-product fixture. Returns the created products keyed p1..p4.
 *
 * @return array{p1: Product, p2: Product, p3: Product, p4: Product}
 */
function seedBrandBacklog(): array
{
    /** @var Product $p1 */
    $p1 = Product::factory()->create([
        'sku' => 'GJ2-BACKLOG',
        'status' => 'publish',
        'woo_product_id' => 201,
        'brand_id' => 7,
        'woo_brand_count' => 0,
    ]);

    /** @var Product $p2 */
    $p2 = Product::factory()->create([
        'sku' => 'GJ2-DRAFT',
        'status' => 'publish',
        'woo_product_id' => null,
        'brand_id' => 7,
        'woo_brand_count' => 0,
    ]);

    /** @var Product $p3 */
    $p3 = Product::factory()->create([
        'sku' => 'GJ2-NOBRAND',
        'status' => 'publish',
        'woo_product_id' => 203,
        'brand_id' => null,
        'woo_brand_count' => 0,
    ]);

    /** @var Product $p4 */
    $p4 = Product::factory()->create([
        'sku' => 'GJ2-DONE',
        'status' => 'publish',
        'woo_product_id' => 204,
        'brand_id' => 7,
        'woo_brand_count' => 1,
    ]);

    return ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'p4' => $p4];
}

it('auto-selects and publishes ONLY the live+brand_id+missing-on-Woo backlog product', function (): void {
    seedBrandBacklog();
    bindWooBrandList();
    $assigned = [];
    bindBrandResolver($assigned);

    $this->artisan('products:publish-sourced-brands')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local brands to Woo product_brand for 1 product(s).')
        ->expectsOutputToContain('Done — 1 published, 0 no brand term (create the brand first), 0 skipped.');

    // Only P1's Woo product was touched — P4 excluded by the woo_brand_count filter;
    // P2 (no woo id) + P3 (no brand_id) never selected.
    expect($assigned)->toBe([201]);

    // Dashboard reflects the fix immediately.
    expect(Product::where('sku', 'GJ2-BACKLOG')->first()->woo_brand_count)->toBe(1);
});

it('--dry-run writes NOTHING to Woo but lists the selection', function (): void {
    seedBrandBacklog();
    bindWooBrandList();
    $assigned = [];
    bindBrandResolver($assigned);

    $this->artisan('products:publish-sourced-brands --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY-RUN — Publishing local brands to Woo product_brand for 1 product(s).')
        ->expectsOutputToContain('would publish GJ2-BACKLOG (Woo #201, brand Yealink)')
        ->expectsOutputToContain('DRY-RUN complete');

    // No assign happened — woo_brand_count untouched.
    expect($assigned)->toBe([]);
    expect(Product::where('sku', 'GJ2-BACKLOG')->first()->woo_brand_count)->toBe(0);
});

it('--skus force-publishes a specific SKU even when it already has a brand on Woo', function (): void {
    seedBrandBacklog();
    bindWooBrandList();
    $assigned = [];
    bindBrandResolver($assigned);

    // P4 already has woo_brand_count=1 — auto-select would skip it, but --skus forces it.
    $this->artisan('products:publish-sourced-brands --skus=GJ2-DONE')
        ->assertExitCode(0)
        ->expectsOutputToContain('Done — 1 published, 0 no brand term (create the brand first), 0 skipped.');

    expect($assigned)->toBe([204]);
});

it('--skus skips the null-woo_product_id and no-brand products (never pushes them)', function (): void {
    seedBrandBacklog();
    bindWooBrandList();
    $assigned = [];
    bindBrandResolver($assigned);

    // P2 (no woo id) excluded by whereNotNull('woo_product_id'); P3 (no brand_id)
    // by whereNotNull('brand_id') — neither reaches the publisher.
    $this->artisan('products:publish-sourced-brands --skus=GJ2-DRAFT,GJ2-NOBRAND')
        ->assertExitCode(0)
        ->expectsOutputToContain('Publishing local brands to Woo product_brand for 0 product(s).')
        ->expectsOutputToContain('Done — 0 published, 0 no brand term (create the brand first), 0 skipped.');

    expect($assigned)->toBe([]);
});

it('registers the products:publish-sourced-brands command', function (): void {
    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('products:publish-sourced-brands');
});
