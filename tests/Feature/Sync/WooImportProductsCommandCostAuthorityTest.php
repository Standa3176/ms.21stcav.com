<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierClient;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

/*
|--------------------------------------------------------------------------
| Quick task 260809-uza PART 1 — woo:import-products cost authority
|--------------------------------------------------------------------------
|
| Regression harness for the circular cost-authority loop traced on SKU
| 9C941AA: woo:import-products used to overwrite the local buy_price from
| Woo's _alg_wc_cog_cost meta on EVERY update, which — combined with the
| nightly cutover:auto-sync push of local buy_price back to Woo COG —
| cemented a wrong cost forever. The supplier feed could never win durably.
|
| New contract:
|   - Woo COG may only SEED buy_price on CREATE or when local buy_price IS NULL.
|   - On an existing row with a non-null buy_price and no --with-supplier
|     override, buy_price is left UNTOUCHED (all other fields still refresh).
|   - --with-supplier remains the sole AUTHORITATIVE overwrite (supplier feed).
|
| Self-contained fakes (uniquely named to avoid colliding with the sibling
| WooImportProductsCommandTest.php global helpers).
*/

function fakeWooPageCostAuth(array $products): MockInterface
{
    $mock = Mockery::mock(WooClient::class);
    $mock->shouldReceive('get')
        ->withArgs(fn ($endpoint, $args) => $endpoint === 'products' && ($args['page'] ?? 1) === 1)
        ->andReturn($products);
    $mock->shouldReceive('get')
        ->withArgs(fn ($endpoint, $args) => $endpoint === 'products' && ($args['page'] ?? 1) > 1)
        ->andReturn([]);

    return $mock;
}

function fakeSupplierCostAuth(array $skuMap): MockInterface
{
    $mock = Mockery::mock(SupplierClient::class);
    $mock->shouldReceive('fetchAllProducts')->andReturn($skuMap);

    return $mock;
}

/** Seed an existing local product WITHOUT firing the ProductObserver echo-loop. */
function seedExistingProduct(array $overrides = []): Product
{
    return Product::withoutEvents(fn (): Product => Product::create(array_merge([
        'woo_product_id' => 9001,
        'sku' => 'EXISTING-SKU',
        'name' => 'Old Name',
        'type' => 'simple',
        'status' => 'draft',
        'stock_status' => 'outofstock',
        'stock_quantity' => null,
        'sell_price' => '10.0000',
        'buy_price' => '1019.8900',
    ], $overrides)));
}

it('PRESERVES an existing non-null buy_price on update (no --with-supplier)', function (): void {
    seedExistingProduct(['buy_price' => '1019.8900']);

    app()->instance(WooClient::class, fakeWooPageCostAuth([
        [
            'id' => 9001, 'sku' => 'EXISTING-SKU', 'name' => 'New Name', 'type' => 'simple',
            'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '3999.00',
            'manage_stock' => true, 'stock_quantity' => 7,
            'meta_data' => [['key' => '_alg_wc_cog_cost', 'value' => '3759.59']],
        ],
    ]));
    app()->instance(SupplierClient::class, fakeSupplierCostAuth([]));

    $this->artisan('woo:import-products')->assertExitCode(0);

    $p = Product::where('woo_product_id', 9001)->first();
    // buy_price frozen — Woo COG (3759.59) must NOT overwrite the local cost.
    expect((string) $p->buy_price)->toBe('1019.8900');
    // Every other field still refreshes on the existing row.
    expect($p->name)->toBe('New Name')
        ->and($p->status)->toBe('publish')
        ->and($p->stock_status)->toBe('instock')
        ->and($p->stock_quantity)->toBe(7)
        ->and((string) $p->sell_price)->toBe('3999.0000');
});

it('SEEDS buy_price from Woo COG on CREATE (new product)', function (): void {
    app()->instance(WooClient::class, fakeWooPageCostAuth([
        [
            'id' => 9100, 'sku' => 'NEW-SKU', 'name' => 'Brand New', 'type' => 'simple',
            'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '99.99',
            'meta_data' => [['key' => '_alg_wc_cog_cost', 'value' => '60.00']],
        ],
    ]));
    app()->instance(SupplierClient::class, fakeSupplierCostAuth([]));

    $this->artisan('woo:import-products')->assertExitCode(0);

    expect((string) Product::where('woo_product_id', 9100)->first()->buy_price)->toBe('60.0000');
});

it('SEEDS buy_price from Woo COG on an existing row whose buy_price IS NULL', function (): void {
    seedExistingProduct(['woo_product_id' => 9200, 'sku' => 'NULL-COST', 'buy_price' => null]);

    app()->instance(WooClient::class, fakeWooPageCostAuth([
        [
            'id' => 9200, 'sku' => 'NULL-COST', 'name' => 'Now Costed', 'type' => 'simple',
            'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '99.99',
            'meta_data' => [['key' => '_alg_wc_cog_cost', 'value' => '42.50']],
        ],
    ]));
    app()->instance(SupplierClient::class, fakeSupplierCostAuth([]));

    $this->artisan('woo:import-products')->assertExitCode(0);

    expect((string) Product::where('woo_product_id', 9200)->first()->buy_price)->toBe('42.5000');
});

it('--with-supplier still authoritatively OVERRIDES an existing non-null buy_price', function (): void {
    seedExistingProduct(['woo_product_id' => 9300, 'sku' => 'SUPPLIER-WINS', 'buy_price' => '1019.8900']);

    app()->instance(WooClient::class, fakeWooPageCostAuth([
        [
            'id' => 9300, 'sku' => 'SUPPLIER-WINS', 'name' => 'Supplier Wins', 'type' => 'simple',
            'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '4999.00',
            'meta_data' => [['key' => '_alg_wc_cog_cost', 'value' => '3759.59']],
        ],
    ]));
    app()->instance(SupplierClient::class, fakeSupplierCostAuth([
        'SUPPLIER-WINS' => ['price' => '3759.59', 'stock' => 3],
    ]));

    $this->artisan('woo:import-products', ['--with-supplier' => true])->assertExitCode(0);

    // Supplier feed IS the authoritative path — it wins over the frozen local cost.
    expect((string) Product::where('woo_product_id', 9300)->first()->buy_price)->toBe('3759.5900');
});

it('never seeds a NULL COG over nothing on create (buy_price stays null)', function (): void {
    app()->instance(WooClient::class, fakeWooPageCostAuth([
        [
            'id' => 9400, 'sku' => 'NO-COG', 'name' => 'No COG', 'type' => 'simple',
            'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '49.99',
            'meta_data' => [],
        ],
    ]));
    app()->instance(SupplierClient::class, fakeSupplierCostAuth([]));

    $this->artisan('woo:import-products')->assertExitCode(0);

    expect(Product::where('woo_product_id', 9400)->first()->buy_price)->toBeNull();
});

it('--dry-run writes nothing — an existing non-null buy_price is untouched', function (): void {
    seedExistingProduct(['woo_product_id' => 9500, 'sku' => 'DRY', 'buy_price' => '1019.8900', 'name' => 'Old Name']);

    app()->instance(WooClient::class, fakeWooPageCostAuth([
        [
            'id' => 9500, 'sku' => 'DRY', 'name' => 'Would Change', 'type' => 'simple',
            'status' => 'publish', 'stock_status' => 'instock', 'regular_price' => '3999.00',
            'meta_data' => [['key' => '_alg_wc_cog_cost', 'value' => '3759.59']],
        ],
    ]));
    app()->instance(SupplierClient::class, fakeSupplierCostAuth([]));

    $this->artisan('woo:import-products', ['--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    $p = Product::where('woo_product_id', 9500)->first();
    expect((string) $p->buy_price)->toBe('1019.8900')
        ->and($p->name)->toBe('Old Name'); // nothing written
    expect(Product::count())->toBe(1); // no phantom create
});
