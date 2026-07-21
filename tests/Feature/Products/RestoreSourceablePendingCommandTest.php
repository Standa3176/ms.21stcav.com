<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Quick task 260713-rsp — products:restore-sourceable-pending
| Quick task 260721-apr — + --push-to-woo (close the one-way door)
|--------------------------------------------------------------------------
|
| supplier:db-sync --flag-obsolete demotes publish→pending any on-Woo product
| with NO fresh-supplier offer (CORRECT per the operator's business rule). This
| command is the missing INVERSE: it restores products that have a current
| in-stock supplier offer back to `publish`.
|
| The restore signal is the CONSISTENT INVERSE of flag-obsolete's keep-set — a
| live fresh-supplier offer (LiveSupplierStockResolver), NOT supplier_sku_cache
| membership (which includes stale/excluded/OOS SKUs the sync drops → churn).
|
|   default (strict):  restore only products with a CURRENT IN-STOCK offer.
|   --include-listed-out-of-stock:  also restore fresh-supplier-listed (any stock).
|
| --dry-run is the DEFAULT (report only); --live applies.
|
| 260721-apr: Woo MIRRORS the local status (Woo admin Pending ≈ local pending),
| so a local-only restore leaves the product hidden on the storefront. The new
| --push-to-woo flag pushes status=publish to Woo VIA WooClient (throttle +
| shadow gate + audit). The flag is OFF by default — the throwing WooClient
| guard below still fails the test on ANY Woo call made without it.
*/

/**
 * Bind a Mockery LiveSupplierStockResolver.
 *
 * @param  array<int, string>  $inStockSkus  SKUs with a current in-stock offer
 * @param  array<int, string>  $listedSkus  SKUs merely listed (any stock) by a fresh supplier
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
 * WITHOUT --push-to-woo the command is LOCAL-only (no Woo writes/reads); the
 * 260721-apr push tests explicitly rebind a spy/real client over this guard.
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
});

/*
|--------------------------------------------------------------------------
| Quick task 260721-apr — --push-to-woo (default OFF)
|--------------------------------------------------------------------------
| Woo mirrors the local status, so a local-only restore leaves the product
| hidden on the storefront. --push-to-woo pushes status=publish to Woo THROUGH
| WooClient (never raw HTTP) so it inherits the 260719-wth throttle, the
| WOO_WRITE_ENABLED shadow gate, the AbortGuard and the audit trail.
*/

/**
 * Bind a WooClient spy that records every put() call (mirrors the
 * products:push-status-to-woo test double). Returns the recorder.
 *
 * @param  \Closure|null  $onPut  optional hook to throw / return a canned result
 */
function bindRestoreWooSpy(?Closure $onPut = null): stdClass
{
    $spy = new stdClass;
    $spy->calls = [];

    $double = Mockery::mock(WooClient::class);
    $double->shouldReceive('put')->andReturnUsing(
        function (string $endpoint, array $payload) use ($spy, $onPut): array {
            $spy->calls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            if ($onPut !== null) {
                return $onPut($endpoint, $payload);
            }

            return ['shadow_mode' => true, 'diff_id' => 1];
        },
    );
    app()->instance(WooClient::class, $double);

    return $spy;
}

it('--push-to-woo PUTs status=publish to products/{wooId} via WooClient', function (): void {
    bindRestoreResolver(inStockSkus: ['PUSH-1']);
    $spy = bindRestoreWooSpy(static fn (): array => ['id' => 601, 'status' => 'publish']);
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'PUSH-1', 'woo_product_id' => 601,
    ]);

    $exit = Artisan::call('products:restore-sourceable-pending', [
        '--live' => true,
        '--push-to-woo' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($product->fresh()->status)->toBe('publish')
        ->and($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0]['endpoint'])->toBe('products/601')
        ->and($spy->calls[0]['payload'])->toBe(['status' => 'publish'])
        ->and($output)->toContain('live_pushed=1');
    Http::assertNothingSent();
});

it('makes ZERO Woo calls WITHOUT --push-to-woo (backward compatible)', function (): void {
    bindRestoreResolver(inStockSkus: ['NOPUSH-1']);
    $spy = bindRestoreWooSpy();
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'NOPUSH-1', 'woo_product_id' => 602,
    ]);

    Artisan::call('products:restore-sourceable-pending', ['--live' => true]);

    expect($product->fresh()->status)->toBe('publish')
        ->and($spy->calls)->toBe([]);
    Http::assertNothingSent();
});

it('--push-to-woo in DRY-RUN (no --live) makes no Woo call at all', function (): void {
    bindRestoreResolver(inStockSkus: ['DRYPUSH-1']);
    $spy = bindRestoreWooSpy();
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'DRYPUSH-1', 'woo_product_id' => 603,
    ]);

    $exit = Artisan::call('products:restore-sourceable-pending', ['--push-to-woo' => true]);

    expect($exit)->toBe(0)
        ->and($product->fresh()->status)->toBe('pending')
        ->and($spy->calls)->toBe([]);
});

it('skips + counts a restored product with no usable woo_product_id', function (): void {
    bindRestoreResolver(inStockSkus: ['NOID-1', 'HASID-1']);
    $spy = bindRestoreWooSpy();
    // woo_product_id=0 survives the whereNotNull cohort filter but is NOT a
    // usable Woo id — restore locally, skip the push, and count it.
    $noId = Product::factory()->create([
        'status' => 'pending', 'sku' => 'NOID-1', 'woo_product_id' => 0,
    ]);
    $hasId = Product::factory()->create([
        'status' => 'pending', 'sku' => 'HASID-1', 'woo_product_id' => 604,
    ]);

    Artisan::call('products:restore-sourceable-pending', [
        '--live' => true,
        '--push-to-woo' => true,
    ]);
    $output = Artisan::output();

    expect($noId->fresh()->status)->toBe('publish')
        ->and($hasId->fresh()->status)->toBe('publish')
        ->and($spy->calls)->toHaveCount(1)
        ->and($spy->calls[0]['endpoint'])->toBe('products/604')
        ->and($output)->toContain('skipped_no_woo_id=1');
});

it('shadow mode (WOO_WRITE_ENABLED=false) records a SyncDiff and performs NO live write', function (): void {
    config(['services.woo.write_enabled' => false]);
    bindRestoreResolver(inStockSkus: ['SHADOW-1']);
    // Use the REAL WooClient: shadow writes never touch the Automattic SDK, so
    // there is no network to fake — recordDiff() is the entire code path.
    app()->forgetInstance(WooClient::class);
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'SHADOW-1', 'woo_product_id' => 605,
    ]);

    $exit = Artisan::call('products:restore-sourceable-pending', [
        '--live' => true,
        '--push-to-woo' => true,
    ]);
    $output = Artisan::output();

    $diff = SyncDiff::where('endpoint', 'products/605')->first();

    expect($exit)->toBe(0)
        ->and($product->fresh()->status)->toBe('publish')
        ->and($diff)->not->toBeNull()
        ->and($diff->channel)->toBe('woo')
        ->and($diff->woo_id)->toBe('605')
        ->and($diff->payload)->toBe(['status' => 'publish'])
        ->and($output)->toContain('shadowed=1')
        ->and($output)->toContain('live_pushed=0');
    Http::assertNothingSent();
});

it('rolls the local restore back to pending when the Woo push fails', function (): void {
    bindRestoreResolver(inStockSkus: ['FAIL-1']);
    $spy = bindRestoreWooSpy(static function (): array {
        throw new RuntimeException('Woo 500');
    });
    $product = Product::factory()->create([
        'status' => 'pending', 'sku' => 'FAIL-1', 'woo_product_id' => 606,
    ]);

    Artisan::call('products:restore-sourceable-pending', [
        '--live' => true,
        '--push-to-woo' => true,
    ]);
    $output = Artisan::output();

    // Local status must NOT diverge from Woo — leaving it publish locally would
    // mean nothing ever retries (the row drops out of the pending cohort).
    expect($spy->calls)->toHaveCount(1)
        ->and($product->fresh()->status)->toBe('pending')
        ->and($output)->toContain('push_failed=1');
});
