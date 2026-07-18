<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Quick task 260713-rsp — products:restore-sourceable-pending
|--------------------------------------------------------------------------
|
| Cutover-prep realignment. supplier:db-sync --flag-obsolete demotes
| publish→pending any on-Woo product with NO fresh-supplier offer; a batch of
| products were demoted that DO have a current in-stock supplier offer. Those
| are still `publish` on the live Woo store (the demotion is LOCAL-only). This
| command restores the genuinely-sourceable ones to `publish` LOCALLY so the
| local DB realigns with the store BEFORE cutover.
|
| The restore signal is the CONSISTENT INVERSE of flag-obsolete's keep-set — a
| live fresh-supplier offer (LiveSupplierStockResolver), NOT supplier_sku_cache
| membership (which includes stale/excluded/OOS SKUs the sync drops → churn).
|
|   default (strict):  restore only products with a CURRENT IN-STOCK offer.
|   --include-listed-out-of-stock:  also restore fresh-supplier-listed (any stock).
|
| --dry-run is the DEFAULT (report only); --live applies. LOCAL-only — MUST
| NOT call WooClient / push to Woo (products are already publish on Woo). The
| WooClient guard below fails the test on ANY Woo call.
*/

/**
 * Bind a Mockery LiveSupplierStockResolver.
 *
 * @param  array<int, string>  $inStockSkus  SKUs with a current in-stock offer
 * @param  array<int, string>  $listedSkus   SKUs merely listed (any stock) by a fresh supplier
 */
function bindRestoreResolver(array $inStockSkus, array $listedSkus = []): void
{
    $mock = Mockery::mock(LiveSupplierStockResolver::class);
    $mock->shouldReceive('resolveForSku')->andReturnUsing(
        static fn (string $sku): ?array => in_array($sku, $inStockSkus, true)
            ? ['stock_quantity' => 10, 'stock_status' => 'instock', 'buy_price' => 12.34]
            : null,
    );
    $mock->shouldReceive('isListedByFreshSupplier')->andReturnUsing(
        static fn (string $sku): bool => in_array($sku, $inStockSkus, true)
            || in_array($sku, $listedSkus, true),
    );
    app()->instance(LiveSupplierStockResolver::class, $mock);
}

/**
 * Throwing WooClient guard — ANY Woo call from the command fails the test.
 * products:restore-sourceable-pending is LOCAL-only (no Woo writes/reads).
 */
function bindRestoreNoWooGuard(): void
{
    $stub = new class extends WooClient
    {
        public function __construct()
        {
            // Skip parent constructor — every method throws.
        }

        public function get(string $endpoint, array $query = []): array
        {
            throw new RuntimeException("260713-rsp invariant violated: WooClient::get({$endpoint}).");
        }

        public function put(string $endpoint, array $payload): array
        {
            throw new RuntimeException("260713-rsp invariant violated: WooClient::put({$endpoint}).");
        }

        public function post(string $endpoint, array $payload): array
        {
            throw new RuntimeException("260713-rsp invariant violated: WooClient::post({$endpoint}).");
        }
    };

    app()->instance(WooClient::class, $stub);
}

beforeEach(function (): void {
    bindRestoreNoWooGuard();
    // Second belt: no outbound HTTP of any kind may leave the command.
    Http::preventStrayRequests();
    Http::fake();
});

it('registers products:restore-sourceable-pending as an artisan command', function (): void {
    expect(array_keys(Artisan::all()))->toContain('products:restore-sourceable-pending');
});

it('restores an in-stock pending + on-Woo product to publish (--live)', function (): void {
    bindRestoreResolver(inStockSkus: ['IN-STOCK-1']);
    $product = Product::factory()->create([
        'status' => 'pending',
        'sku' => 'IN-STOCK-1',
        'woo_product_id' => 555,
    ]);

    $exit = Artisan::call('products:restore-sourceable-pending', ['--live' => true]);

    expect($exit)->toBe(0);
    expect($product->fresh()->status)->toBe('publish');
    Http::assertNothingSent();
});

it('SKIPS a listed-but-out-of-stock product by default (strict in-stock signal)', function (): void {
    bindRestoreResolver(inStockSkus: [], listedSkus: ['LISTED-OOS-1']);
    $product = Product::factory()->create([
        'status' => 'pending',
        'sku' => 'LISTED-OOS-1',
        'woo_product_id' => 556,
    ]);

    Artisan::call('products:restore-sourceable-pending', ['--live' => true]);

    expect($product->fresh()->status)->toBe('pending');
});

it('restores a listed-but-out-of-stock product WITH --include-listed-out-of-stock', function (): void {
    bindRestoreResolver(inStockSkus: [], listedSkus: ['LISTED-OOS-2']);
    $product = Product::factory()->create([
        'status' => 'pending',
        'sku' => 'LISTED-OOS-2',
        'woo_product_id' => 557,
    ]);

    Artisan::call('products:restore-sourceable-pending', [
        '--live' => true,
        '--include-listed-out-of-stock' => true,
    ]);

    expect($product->fresh()->status)->toBe('publish');
});

it('SKIPS a product with no fresh-supplier offer at all (correctly demoted)', function (): void {
    bindRestoreResolver(inStockSkus: [], listedSkus: []);
    $product = Product::factory()->create([
        'status' => 'pending',
        'sku' => 'NO-OFFER-1',
        'woo_product_id' => 558,
    ]);

    Artisan::call('products:restore-sourceable-pending', [
        '--live' => true,
        '--include-listed-out-of-stock' => true,
    ]);

    expect($product->fresh()->status)->toBe('pending');
});

it('SKIPS is_custom_ms / exclude_from_auto_update / custom-ms tag carve-outs even when in-stock', function (): void {
    bindRestoreResolver(inStockSkus: ['CUSTOM-1', 'EXCLUDED-1', 'TAGGED-1']);

    $custom = Product::factory()->customMs()->create([
        'status' => 'pending', 'sku' => 'CUSTOM-1', 'woo_product_id' => 561,
    ]);
    $excluded = Product::factory()->excluded()->create([
        'status' => 'pending', 'sku' => 'EXCLUDED-1', 'woo_product_id' => 562,
    ]);
    $tagged = Product::factory()->create([
        'status' => 'pending', 'sku' => 'TAGGED-1', 'woo_product_id' => 563,
        'tags' => ['custom-ms', 'bespoke'],
    ]);

    Artisan::call('products:restore-sourceable-pending', ['--live' => true]);

    expect($custom->fresh()->status)->toBe('pending');
    expect($excluded->fresh()->status)->toBe('pending');
    expect($tagged->fresh()->status)->toBe('pending');
});

it('never touches a product that is NOT pending (only pending is a restore candidate)', function (): void {
    bindRestoreResolver(inStockSkus: ['DRAFT-1', 'ALREADY-PUB-1']);
    $draft = Product::factory()->create([
        'status' => 'draft', 'sku' => 'DRAFT-1', 'woo_product_id' => 564,
    ]);
    $published = Product::factory()->create([
        'status' => 'publish', 'sku' => 'ALREADY-PUB-1', 'woo_product_id' => 565,
    ]);

    Artisan::call('products:restore-sourceable-pending', ['--live' => true]);

    expect($draft->fresh()->status)->toBe('draft');
    expect($published->fresh()->status)->toBe('publish');
});

it('never touches a pending product without a woo_product_id (not on the live store)', function (): void {
    bindRestoreResolver(inStockSkus: ['NO-WOO-1']);
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'NO-WOO-1', 'woo_product_id' => null,
    ]);

    Artisan::call('products:restore-sourceable-pending', ['--live' => true]);

    expect($product->fresh()->status)->toBe('pending');
});

it('--dry-run is the DEFAULT: reports but changes nothing', function (): void {
    bindRestoreResolver(inStockSkus: ['DRYRUN-1']);
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'DRYRUN-1', 'woo_product_id' => 566,
    ]);

    $exit = Artisan::call('products:restore-sourceable-pending');

    expect($exit)->toBe(0);
    expect($product->fresh()->status)->toBe('pending');
    Http::assertNothingSent();
});

it('is idempotent — a second --live run restores nothing further', function (): void {
    bindRestoreResolver(inStockSkus: ['IDEM-1']);
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'IDEM-1', 'woo_product_id' => 567,
    ]);

    Artisan::call('products:restore-sourceable-pending', ['--live' => true]);
    expect($product->fresh()->status)->toBe('publish');

    // Second run: the product is now publish, no longer a pending candidate.
    $exit = Artisan::call('products:restore-sourceable-pending', ['--live' => true]);
    expect($exit)->toBe(0);
    expect($product->fresh()->status)->toBe('publish');
});

it('makes NO Woo call on a full mixed batch', function (): void {
    bindRestoreResolver(inStockSkus: ['MIX-IN-1'], listedSkus: ['MIX-OOS-1']);
    Product::factory()->create(['status' => 'pending', 'sku' => 'MIX-IN-1', 'woo_product_id' => 571]);
    Product::factory()->create(['status' => 'pending', 'sku' => 'MIX-OOS-1', 'woo_product_id' => 572]);
    Product::factory()->create(['status' => 'pending', 'sku' => 'MIX-NONE-1', 'woo_product_id' => 573]);

    Artisan::call('products:restore-sourceable-pending', ['--live' => true]);

    // The throwing WooClient guard + the HTTP fake both assert no Woo egress.
    Http::assertNothingSent();
})->throwsNoExceptions();
