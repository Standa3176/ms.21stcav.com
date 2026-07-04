<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Quick task 260701-pmr — products:backfill-woo-stock
|--------------------------------------------------------------------------
|
| One-time (re-runnable) backfill that PUTs manage_stock=true + current
| stock_quantity + stock_status to Woo for existing app-created published
| products so they show a stock line like legacy products.
|
| Boundary strategy: an anonymous-subclass WooClient stub records every
| put() call as ['path' => , 'body' => ] and can be told to throw for one
| specific woo_product_id (used to exercise the invalid-id self-heal guard).
*/

uses(RefreshDatabase::class);

it('pushes the stock payload for each published product and excludes the draft one', function (): void {
    Product::factory()->create(['sku' => 'A-SKU', 'woo_product_id' => 100, 'auto_create_status' => 'published', 'stock_quantity' => 5, 'stock_status' => 'instock']);
    Product::factory()->create(['sku' => 'B-SKU', 'woo_product_id' => 200, 'auto_create_status' => 'published', 'stock_quantity' => 0, 'stock_status' => 'outofstock']);
    Product::factory()->create(['sku' => 'C-SKU', 'woo_product_id' => 300, 'auto_create_status' => 'published', 'stock_quantity' => 12, 'stock_status' => 'instock']);
    // D — non-published; default scope must exclude it.
    Product::factory()->create(['sku' => 'D-SKU', 'woo_product_id' => 400, 'auto_create_status' => 'draft', 'stock_quantity' => 9, 'stock_status' => 'instock']);

    $stub = bindBackfillWooStockStub();

    $exit = Artisan::call('products:backfill-woo-stock');

    expect($exit)->toBe(0);

    $paths = array_column($stub->calls, 'path');
    expect($paths)->toHaveCount(3);
    expect($paths)->toContain('products/100', 'products/200', 'products/300');
    expect($paths)->not->toContain('products/400');

    $byPath = collect($stub->calls)->keyBy('path');
    expect($byPath['products/100']['body'])->toBe(['manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock']);
    expect($byPath['products/200']['body'])->toBe(['manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock']);
    expect($byPath['products/300']['body'])->toBe(['manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock']);

    expect(Artisan::output())->toContain('pushed=3');
});

it('dry-run records zero PUTs and changes nothing', function (): void {
    Product::factory()->create(['sku' => 'A-SKU', 'woo_product_id' => 100, 'auto_create_status' => 'published', 'stock_quantity' => 5, 'stock_status' => 'instock']);
    Product::factory()->create(['sku' => 'B-SKU', 'woo_product_id' => 200, 'auto_create_status' => 'published', 'stock_quantity' => 0, 'stock_status' => 'outofstock']);
    Product::factory()->create(['sku' => 'C-SKU', 'woo_product_id' => 300, 'auto_create_status' => 'published', 'stock_quantity' => 12, 'stock_status' => 'instock']);

    $stub = bindBackfillWooStockStub();

    Artisan::call('products:backfill-woo-stock', ['--dry-run' => true]);

    expect($stub->calls)->toHaveCount(0);
    expect(Product::where('sku', 'A-SKU')->value('woo_product_id'))->toBe(100);
    expect(Product::where('sku', 'B-SKU')->value('woo_product_id'))->toBe(200);
    expect(Product::where('sku', 'C-SKU')->value('woo_product_id'))->toBe(300);
});

it('invalid-id error nulls that product id, still pushes the rest, and exits SUCCESS', function (): void {
    Product::factory()->create(['sku' => 'A-SKU', 'woo_product_id' => 100, 'auto_create_status' => 'published', 'stock_quantity' => 5, 'stock_status' => 'instock']);
    $b = Product::factory()->create(['sku' => 'B-SKU', 'woo_product_id' => 200, 'auto_create_status' => 'published', 'stock_quantity' => 0, 'stock_status' => 'outofstock']);
    Product::factory()->create(['sku' => 'C-SKU', 'woo_product_id' => 300, 'auto_create_status' => 'published', 'stock_quantity' => 12, 'stock_status' => 'instock']);

    // Woo product 200 no longer exists — the stub throws the WC invalid-id error.
    $stub = bindBackfillWooStockStub(throwForWooId: 200);

    $exit = Artisan::call('products:backfill-woo-stock');

    expect($exit)->toBe(0);

    // B's stale id is nulled; A + C still pushed.
    expect($b->fresh()->woo_product_id)->toBeNull();

    $paths = array_column($stub->calls, 'path');
    expect($paths)->toContain('products/100', 'products/200', 'products/300');

    $output = Artisan::output();
    expect($output)->toContain('pushed=2');
    expect($output)->toContain('skipped_stale=1');
});

it('--skus targets only the named product ignoring the published-scope default', function (): void {
    Product::factory()->create(['sku' => 'A-SKU', 'woo_product_id' => 100, 'auto_create_status' => 'published', 'stock_quantity' => 5, 'stock_status' => 'instock']);
    Product::factory()->create(['sku' => 'B-SKU', 'woo_product_id' => 200, 'auto_create_status' => 'published', 'stock_quantity' => 0, 'stock_status' => 'outofstock']);

    $stub = bindBackfillWooStockStub();

    $exit = Artisan::call('products:backfill-woo-stock', ['--skus' => 'A-SKU']);

    expect($exit)->toBe(0);
    $paths = array_column($stub->calls, 'path');
    expect($paths)->toBe(['products/100']);
});

/**
 * Bind an anonymous-subclass WooClient stub into the container.
 *
 * Records every put() as ['path' => , 'body' => ] in public $calls. When
 * $throwForWooId matches the id embedded in the "products/{id}" path, put()
 * throws a RuntimeException carrying 'woocommerce_rest_product_invalid_id'
 * (the observed WC error) so the command's invalid-id guard is exercised.
 *
 * @return object the bound stub with public array $calls
 */
function bindBackfillWooStockStub(?int $throwForWooId = null): object
{
    $stub = new class($throwForWooId) extends WooClient
    {
        /** @var array<int, array{path:string, body:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(private ?int $throwForWooId)
        {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->calls[] = ['path' => $endpoint, 'body' => $payload];

            if ($this->throwForWooId !== null && $endpoint === "products/{$this->throwForWooId}") {
                throw new RuntimeException('woocommerce_rest_product_invalid_id: Invalid ID.');
            }

            return [];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}
