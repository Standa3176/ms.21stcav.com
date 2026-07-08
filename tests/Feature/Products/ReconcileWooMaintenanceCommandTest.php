<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Quick task 260708-b4f — products:reconcile-woo-maintenance (Pass 1)
|--------------------------------------------------------------------------
|
| Pages Woo GET /products (status=publish) and mirrors each returned product's
| REAL Woo state (image count, EAN, category count, stock status) into the new
| local woo_* columns, matched by woo_product_id. READ-ONLY on Woo — the stub's
| put()/post() THROW so any accidental Woo write fails the test.
|
| Scenarios:
|   A — Product 101 (2 images / gtin '50123' / 1 category / instock) reconciles.
|   B — Product 102 (0 images / blank gtin / 0 categories / outofstock) → gtin null.
|   C — A local product whose woo id is NOT returned by Woo stays untouched.
|   D — --dry-run pages Woo but writes NOTHING.
*/

/**
 * Bind a paging WooClient stub. get('products', page 1) returns the fixture rows;
 * page >= 2 returns [] (exhausted). put()/post() THROW — read-only guard.
 *
 * @param  array<int, array<string, mixed>>  $page1Rows
 * @return object the bound stub (public $getCalls records the get() query args)
 */
function bindReconcileWooStub(array $page1Rows): object
{
    $stub = new class($page1Rows) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $getCalls = [];

        public function __construct(
            /** @var array<int, array<string, mixed>> */
            public array $page1Rows,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed;
            // only get() returns data, writes throw.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];

            return ((int) ($query['page'] ?? 1)) === 1 ? $this->page1Rows : [];
        }

        public function put(string $endpoint, array $payload): array
        {
            throw new RuntimeException(
                "260708-b4f invariant violated: reconcile called WooClient::put({$endpoint}). Read-only on Woo."
            );
        }

        public function post(string $endpoint, array $payload): array
        {
            throw new RuntimeException(
                "260708-b4f invariant violated: reconcile called WooClient::post({$endpoint}). Read-only on Woo."
            );
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

/** The two live products Woo returns for page 1, plus one Woo omits. */
function seedReconcileProducts(): void
{
    Product::factory()->create(['woo_product_id' => 101, 'sku' => 'R-101', 'status' => 'publish']);
    Product::factory()->create(['woo_product_id' => 102, 'sku' => 'R-102', 'status' => 'publish']);
    // Not returned by Woo — must stay untouched.
    Product::factory()->create(['woo_product_id' => 999, 'sku' => 'R-999', 'status' => 'publish']);
}

/**
 * Fixture rows the stub returns for page 1.
 *
 * Returned as stdClass OBJECTS to match what WooClient actually yields: its
 * normaliseResponseBody() returns an already-array response (the /products list)
 * as-is, so each list ITEM stays a stdClass. This shape reproduces the prod crash
 * ("Cannot use object of type stdClass as array") on the unfixed command.
 */
function reconcileFixtureRows(): array
{
    return [
        (object) [
            'id' => 101,
            'images' => [(object) [], (object) []],
            'global_unique_id' => '50123',
            'categories' => [(object) []],
            'stock_status' => 'instock',
        ],
        (object) [
            'id' => 102,
            'images' => [],
            'global_unique_id' => '',
            'categories' => [],
            'stock_status' => 'outofstock',
        ],
    ];
}

it('mirrors Woo state into woo_* columns per matched product', function (): void {
    seedReconcileProducts();
    $stub = bindReconcileWooStub(reconcileFixtureRows());

    $exit = Artisan::call('products:reconcile-woo-maintenance');

    expect($exit)->toBe(0);

    $p101 = Product::where('woo_product_id', 101)->firstOrFail();
    expect($p101->woo_image_count)->toBe(2);
    expect($p101->woo_gtin)->toBe('50123');
    expect($p101->woo_category_count)->toBe(1);
    expect($p101->woo_stock_status)->toBe('instock');
    expect($p101->woo_reconciled_at)->not->toBeNull();

    $p102 = Product::where('woo_product_id', 102)->firstOrFail();
    expect($p102->woo_image_count)->toBe(0);
    expect($p102->woo_gtin)->toBeNull();
    expect($p102->woo_category_count)->toBe(0);
    expect($p102->woo_stock_status)->toBe('outofstock');
    expect($p102->woo_reconciled_at)->not->toBeNull();

    // Only GETs were issued — put()/post() would have thrown.
    expect($stub->getCalls)->not->toBeEmpty();
    expect($stub->getCalls[0]['endpoint'])->toBe('products');
    expect($stub->getCalls[0]['query']['status'])->toBe('publish');
    expect($stub->getCalls[0]['query']['_fields'])
        ->toBe('id,images,global_unique_id,categories,stock_status');
});

it('leaves a product Woo does not return untouched', function (): void {
    seedReconcileProducts();
    bindReconcileWooStub(reconcileFixtureRows());

    Artisan::call('products:reconcile-woo-maintenance');

    $p999 = Product::where('woo_product_id', 999)->firstOrFail();
    expect($p999->woo_image_count)->toBeNull();
    expect($p999->woo_gtin)->toBeNull();
    expect($p999->woo_category_count)->toBeNull();
    expect($p999->woo_stock_status)->toBeNull();
    expect($p999->woo_reconciled_at)->toBeNull();
});

it('--dry-run pages Woo but writes nothing', function (): void {
    seedReconcileProducts();
    $stub = bindReconcileWooStub(reconcileFixtureRows());

    $exit = Artisan::call('products:reconcile-woo-maintenance', ['--dry-run' => true]);

    expect($exit)->toBe(0);

    foreach ([101, 102, 999] as $wooId) {
        $p = Product::where('woo_product_id', $wooId)->firstOrFail();
        expect($p->woo_image_count)->toBeNull();
        expect($p->woo_gtin)->toBeNull();
        expect($p->woo_category_count)->toBeNull();
        expect($p->woo_stock_status)->toBeNull();
        expect($p->woo_reconciled_at)->toBeNull();
    }

    // Dry-run still PAGES Woo (read-only), so a match report can be printed.
    expect($stub->getCalls)->not->toBeEmpty();
});

it('clamps --per-page into the 1..100 Woo range', function (): void {
    seedReconcileProducts();
    $stub = bindReconcileWooStub(reconcileFixtureRows());

    Artisan::call('products:reconcile-woo-maintenance', ['--per-page' => 500]);

    expect($stub->getCalls[0]['query']['per_page'])->toBe(100);
});
