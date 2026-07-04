<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Events\ProductPublished;
use App\Domain\ProductAutoCreate\Jobs\PublishProductJob;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Quick task 260701-opg — PublishProductJob carries WooCommerce stock keys
|--------------------------------------------------------------------------
|
| App-created products currently publish to Woo WITHOUT stock management, so
| the storefront shows no "In stock (N)" / "Out of stock" line the way legacy
| products do. This asserts both publish paths now merge the stock keys from
| the shared BuildsWooStockPayload trait:
|
|   Path A (existing Woo draft → PUT products/{id}): status=publish PLUS
|           manage_stock/stock_quantity/stock_status.
|   Path B (no woo_product_id → POST products): the stock keys are in the POST.
|
| WooClient stub: anonymous subclass that skips the parent constructor and
| overrides put()/post() to record [method, path, body] — mirrors bindWooStub
| in tests/Feature/Console/BackfillCategoryFromWooCommandTest.php. write_enabled
| is forced true so put/post actually fire (else the job runs in shadow mode).
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
    config()->set('services.woo.write_enabled', true);
    // 260702-pes — default to a null-returning live-stock resolver so the two
    // pre-existing stock tests exercise the UNCHANGED path (hydration no-op).
    // Individual hydration cases override this bind with a value-returning mock.
    bindLiveStockResolver(null);
});

/**
 * 260702-pes — bind a Mockery LiveSupplierStockResolver double whose
 * resolveForSku() returns $offer. Class is non-final → Mockery subclasses it
 * and the real constructor (with its supplier_db deps) is bypassed.
 *
 * @param  array{stock_quantity:int, stock_status:string, buy_price:?float}|null  $offer
 */
function bindLiveStockResolver(?array $offer): void
{
    $mock = Mockery::mock(LiveSupplierStockResolver::class);
    $mock->shouldReceive('resolveForSku')->andReturn($offer);
    app()->instance(LiveSupplierStockResolver::class, $mock);
}

/**
 * Bind a WooClient stub that records every put()/post() call as
 * ['method','path','body'] and returns a canned response.
 *
 * @return object stub with a public array $calls
 */
function bindPublishWooStockStub(array $putResponse = [], array $postResponse = []): object
{
    $stub = new class($putResponse, $postResponse) extends WooClient
    {
        /** @var array<int, array{method:string, path:string, body:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(
            public array $putResponse,
            public array $postResponse,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->calls[] = ['method' => 'PUT', 'path' => $endpoint, 'body' => $payload];

            return $this->putResponse;
        }

        public function post(string $endpoint, array $payload): array
        {
            $this->calls[] = ['method' => 'POST', 'path' => $endpoint, 'body' => $payload];

            return $this->postResponse;
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

it('Path A: publish PUT carries status=publish + manage_stock + stock_quantity + stock_status', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => 999,
        'stock_quantity' => 7,
        'stock_status' => 'instock',
        'auto_create_status' => 'approved',
        'status' => 'draft',
    ]);

    $stub = bindPublishWooStockStub(putResponse: ['id' => 999, 'status' => 'publish']);

    PublishProductJob::dispatchSync((int) $product->id, 0);

    // Exactly one PUT to products/999.
    $puts = array_values(array_filter($stub->calls, fn (array $c): bool => $c['method'] === 'PUT'));
    expect($puts)->toHaveCount(1);
    expect($puts[0]['path'])->toBe('products/999');

    $body = $puts[0]['body'];
    expect($body['status'])->toBe('publish');
    expect($body['manage_stock'])->toBeTrue();
    expect($body['stock_quantity'])->toBe(7);
    expect($body['stock_status'])->toBe('instock');
});

it('Path B: create POST carries manage_stock + stock_quantity=0 + stock_status=outofstock', function (): void {
    Event::fake([ProductPublished::class]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'OPG-STOCK-B',
        'name' => 'Out Of Stock Widget',
        'sell_price' => null,          // no price → no split-PUT to distract the assertion
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $stub = bindPublishWooStockStub(postResponse: ['id' => 4242, 'slug' => 'out-of-stock-widget']);

    PublishProductJob::dispatchSync((int) $product->id, 0);

    // Exactly one POST to products.
    $posts = array_values(array_filter($stub->calls, fn (array $c): bool => $c['method'] === 'POST'));
    expect($posts)->toHaveCount(1);
    expect($posts[0]['path'])->toBe('products');

    $body = $posts[0]['body'];
    expect($body['manage_stock'])->toBeTrue();
    expect($body['stock_quantity'])->toBe(0);
    expect($body['stock_status'])->toBe('outofstock');
});

/*
|--------------------------------------------------------------------------
| Quick task 260702-pes — publish-time LIVE stock hydration
|--------------------------------------------------------------------------
|
| PublishProductJob now hydrates local stock from LiveSupplierStockResolver
| BEFORE building either Woo payload, so a product created today (stock null)
| goes live with the real qty. A null resolver leaves existing stock untouched.
*/

it('260702-pes Path A: live resolver qty beats the null local qty on the PUT', function (): void {
    Event::fake([ProductPublished::class]);

    // Resolver reports 64 in stock at £50 — should override the null local qty.
    bindLiveStockResolver(['stock_quantity' => 64, 'stock_status' => 'instock', 'buy_price' => 50.0]);

    $product = Product::factory()->create([
        'woo_product_id' => 999,
        'stock_quantity' => null,
        'stock_status' => 'outofstock',
        'auto_create_status' => 'approved',
        'status' => 'draft',
    ]);

    $stub = bindPublishWooStockStub(putResponse: ['id' => 999, 'status' => 'publish']);

    PublishProductJob::dispatchSync((int) $product->id, 0);

    $puts = array_values(array_filter($stub->calls, fn (array $c): bool => $c['method'] === 'PUT'));
    expect($puts)->toHaveCount(1);
    expect($puts[0]['path'])->toBe('products/999');

    $body = $puts[0]['body'];
    expect($body['status'])->toBe('publish');
    expect($body['manage_stock'])->toBeTrue();
    expect($body['stock_quantity'])->toBe(64);
    expect($body['stock_status'])->toBe('instock');

    // Local row was hydrated before the payload was built.
    $product->refresh();
    expect($product->stock_quantity)->toBe(64);
    expect((float) $product->buy_price)->toBe(50.0);
});

it('260702-pes Path B: live resolver qty flows onto the create POST', function (): void {
    Event::fake([ProductPublished::class]);

    bindLiveStockResolver(['stock_quantity' => 64, 'stock_status' => 'instock', 'buy_price' => 50.0]);

    $product = Product::factory()->create([
        'woo_product_id' => null,
        'sku' => 'PES-STOCK-B',
        'name' => 'Live Stock Widget',
        'sell_price' => null,
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'auto_create_status' => 'draft',
        'status' => 'draft',
    ]);

    $stub = bindPublishWooStockStub(postResponse: ['id' => 4242, 'slug' => 'live-stock-widget']);

    PublishProductJob::dispatchSync((int) $product->id, 0);

    $posts = array_values(array_filter($stub->calls, fn (array $c): bool => $c['method'] === 'POST'));
    expect($posts)->toHaveCount(1);
    expect($posts[0]['path'])->toBe('products');

    $body = $posts[0]['body'];
    expect($body['manage_stock'])->toBeTrue();
    expect($body['stock_quantity'])->toBe(64);
    expect($body['stock_status'])->toBe('instock');
});

it('260702-pes null resolver leaves existing local stock untouched in the payload', function (): void {
    Event::fake([ProductPublished::class]);

    // Resolver returns null (genuinely OOS everywhere / supplier_db unreachable).
    bindLiveStockResolver(null);

    $product = Product::factory()->create([
        'woo_product_id' => 999,
        'stock_quantity' => 5,
        'stock_status' => 'instock',
        'auto_create_status' => 'approved',
        'status' => 'draft',
    ]);

    $stub = bindPublishWooStockStub(putResponse: ['id' => 999, 'status' => 'publish']);

    PublishProductJob::dispatchSync((int) $product->id, 0);

    $puts = array_values(array_filter($stub->calls, fn (array $c): bool => $c['method'] === 'PUT'));
    expect($puts)->toHaveCount(1);

    // Existing local qty preserved — hydration was a no-op.
    expect($puts[0]['body']['stock_quantity'])->toBe(5);
    expect($puts[0]['body']['stock_status'])->toBe('instock');

    $product->refresh();
    expect($product->stock_quantity)->toBe(5);
});
