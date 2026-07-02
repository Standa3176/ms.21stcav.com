<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Quick task 260702-pes — products:hydrate-live-stock
|--------------------------------------------------------------------------
|
| MS-side backfill/repair that re-hydrates local stock from the LIVE
| cheapest-fresh-in-stock supplier offer (LiveSupplierStockResolver). Cases:
|
|   A — today's null-qty publish product → stock_quantity/stock_status/buy_price
|       hydrated from the resolver + last_synced_at set.
|   B — --dry-run leaves the row unchanged (still null).
|   C — resolver returns null → row unchanged, reported under no_offer.
|   D — --only-null-qty skips a product that already has stock_quantity set.
|
| Suite-wide guard: WooClient is bound to a throwing stub. ANY Woo call from the
| command fails the test — this command is strictly MS-side (mirrors the
| 260611-qcq hydrate-stock-from-offers invariant).
*/

/**
 * Bind a Mockery LiveSupplierStockResolver whose resolveForSku() returns $offer
 * for any SKU. Class is non-final → Mockery subclasses it (real supplier_db
 * constructor bypassed).
 *
 * @param  array{stock_quantity:int, stock_status:string, buy_price:?float}|null  $offer
 */
function bindLiveResolver(?array $offer): void
{
    $mock = Mockery::mock(LiveSupplierStockResolver::class);
    $mock->shouldReceive('resolveForSku')->andReturn($offer);
    app()->instance(LiveSupplierStockResolver::class, $mock);
}

/**
 * Throwing WooClient guard — ANY Woo call from the command fails the test.
 * products:hydrate-live-stock is MS-side only (no Woo writes).
 */
function bindLiveStockNoWooGuard(): void
{
    $stub = new class extends WooClient
    {
        public function __construct()
        {
            // Skip parent constructor — every method throws.
        }

        public function get(string $endpoint, array $query = []): array
        {
            throw new RuntimeException("260702-pes invariant violated: WooClient::get({$endpoint}).");
        }

        public function put(string $endpoint, array $payload): array
        {
            throw new RuntimeException("260702-pes invariant violated: WooClient::put({$endpoint}).");
        }

        public function post(string $endpoint, array $payload): array
        {
            throw new RuntimeException("260702-pes invariant violated: WooClient::post({$endpoint}).");
        }
    };

    app()->instance(WooClient::class, $stub);
}

beforeEach(function (): void {
    bindLiveStockNoWooGuard();
});

it('Case A: today null-qty publish product is hydrated from the live resolver', function (): void {
    bindLiveResolver(['stock_quantity' => 64, 'stock_status' => 'instock', 'buy_price' => 76.92]);

    $product = Product::factory()->create([
        'sku' => '43376',
        'woo_product_id' => 9001,
        'status' => 'publish',
        'stock_quantity' => null,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);

    $exit = Artisan::call('products:hydrate-live-stock');
    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBe(64);
    expect($product->stock_status)->toBe('instock');
    expect((float) $product->buy_price)->toBe(76.92);
    expect($product->last_synced_at)->not->toBeNull();

    expect(Artisan::output())->toContain('updated');
});

it('Case B: --dry-run writes nothing', function (): void {
    bindLiveResolver(['stock_quantity' => 64, 'stock_status' => 'instock', 'buy_price' => 76.92]);

    $product = Product::factory()->create([
        'sku' => 'DRY-001',
        'woo_product_id' => 9002,
        'status' => 'publish',
        'stock_quantity' => null,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);

    $exit = Artisan::call('products:hydrate-live-stock', ['--dry-run' => true]);
    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBeNull();
    expect($product->last_synced_at)->toBeNull();

    expect(Artisan::output())->toContain('dry-run');
});

it('Case C: resolver returns null → row unchanged, reported under no_offer', function (): void {
    bindLiveResolver(null);

    $product = Product::factory()->create([
        'sku' => 'NONE-001',
        'woo_product_id' => 9003,
        'status' => 'publish',
        'stock_quantity' => null,
        'stock_status' => 'outofstock',
        'buy_price' => 12.50,
        'last_synced_at' => null,
    ]);

    $exit = Artisan::call('products:hydrate-live-stock');
    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBeNull();
    expect((float) $product->buy_price)->toBe(12.5); // preserved
    expect($product->last_synced_at)->toBeNull();

    expect(Artisan::output())->toContain('no_offer');
});

it('Case D: --only-null-qty skips a product that already has stock_quantity set', function (): void {
    bindLiveResolver(['stock_quantity' => 64, 'stock_status' => 'instock', 'buy_price' => 76.92]);

    $nullQty = Product::factory()->create([
        'sku' => 'NULLQ-001',
        'woo_product_id' => 9004,
        'status' => 'publish',
        'stock_quantity' => null,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);
    $hasQty = Product::factory()->create([
        'sku' => 'HASQ-001',
        'woo_product_id' => 9005,
        'status' => 'publish',
        'stock_quantity' => 3,
        'stock_status' => 'instock',
        'buy_price' => 20.00,
        'last_synced_at' => null,
    ]);

    $exit = Artisan::call('products:hydrate-live-stock', ['--only-null-qty' => true]);
    expect($exit)->toBe(0);

    // Null-qty product hydrated.
    $nullQty->refresh();
    expect($nullQty->stock_quantity)->toBe(64);
    expect($nullQty->stock_status)->toBe('instock');

    // Product that already had a qty was excluded by --only-null-qty.
    $hasQty->refresh();
    expect($hasQty->stock_quantity)->toBe(3);
    expect($hasQty->stock_status)->toBe('instock');
    expect($hasQty->last_synced_at)->toBeNull();
});
