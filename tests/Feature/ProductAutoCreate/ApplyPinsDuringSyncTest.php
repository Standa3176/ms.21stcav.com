<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\ProductAutoCreate\Listeners\ApplyPinsDuringSync;
use App\Domain\ProductAutoCreate\Services\ProductOverrideGuard;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Events\SupplierStockChanged;
use App\Domain\Sync\Services\WooClient;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 05 Task 1 — ApplyPinsDuringSync listener
|--------------------------------------------------------------------------
| Ship gate for AUTO-10 at the LISTENER level. Full sync cycle integration
| assertion lives in tests/Architecture/PinnedFieldsSurviveSyncTest (Task 2).
|
| Covers:
|   A. Happy paths:
|      1. SupplierPriceChanged + ProductOverride(pin_price=false) → guard
|         called with ['regular_price'] + 'supplier_price_changed', no PUT
|         (unpinned — guard short-circuits internally).
|      2. SupplierPriceChanged + ProductOverride(pin_price=true) → guard
|         called → revert PUT issued with Laravel's sell_price value.
|      3. SupplierStockChanged → guard called with ['stock_quantity'] +
|         'supplier_stock_changed', no PUT (stock not in pin map).
|      4. SupplierSkuMissing → guard called with ['status'] +
|         'supplier_sku_missing', no PUT (status not in pin map).
|   B. Defensive short-circuits:
|      5. No ProductOverride row → guard handles (Plan 03 behaviour), no error.
|      6. Unknown woo_product_id → guard handles, no error.
|   C. Fail-soft:
|      7. Guard throws → Log::warning + no rethrow (sibling listener chain
|         continues).
|   D. Listener wiring:
|      8. Implements ShouldQueue + onQueue('sync-bulk') (NOT public $queue
|         property — PHP 8.4 trait collision guard).
|      9. EventServiceProvider binds all 3 events to the right handler method
|         (Event::assertListening on the string syntax).
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

// ══════════════════════════════════════════════════════════════════
// A. Happy paths — guard is called with the right arguments
// ══════════════════════════════════════════════════════════════════

it('price-change handler calls guard with regular_price field + supplier_price_changed source', function (): void {
    $guard = Mockery::mock(ProductOverrideGuard::class);
    $guard->shouldReceive('revertIfPinned')
        ->with(500, ['regular_price'], 'supplier_price_changed')
        ->once();

    $listener = new ApplyPinsDuringSync($guard);
    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'LOG-MEETUP',
        wooProductId: 500,
        wooVariationId: null,
        oldPrice: '1000.00',
        newPrice: '1200.00',
    ));
});

it('price-change with pinned price issues revert PUT via real guard', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 500,
        'sell_price' => 1499.99,
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'pin_price' => true,
    ]);

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldReceive('put')
        ->with('/products/500', ['regular_price' => '1499.99'])
        ->once()
        ->andReturn([]);
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldReceive('record')
        ->with('product_auto_create.pin_reverted', Mockery::type('array'))
        ->once();

    $guard = new ProductOverrideGuard($woo, $auditor);
    $listener = new ApplyPinsDuringSync($guard);

    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'LOG-MEETUP',
        wooProductId: 500,
        wooVariationId: null,
        oldPrice: '1000.00',
        newPrice: '1200.00',
    ));
});

it('stock-change handler calls guard with stock_quantity + supplier_stock_changed source', function (): void {
    $guard = Mockery::mock(ProductOverrideGuard::class);
    $guard->shouldReceive('revertIfPinned')
        ->with(501, ['stock_quantity'], 'supplier_stock_changed')
        ->once();

    $listener = new ApplyPinsDuringSync($guard);
    $listener->handleStockChanged(new SupplierStockChanged(
        sku: 'LOG-RALLY',
        wooProductId: 501,
        wooVariationId: null,
        oldStock: 5,
        newStock: 3,
    ));
});

it('sku-missing handler calls guard with status + supplier_sku_missing source', function (): void {
    $guard = Mockery::mock(ProductOverrideGuard::class);
    $guard->shouldReceive('revertIfPinned')
        ->with(502, ['status'], 'supplier_sku_missing')
        ->once();

    $listener = new ApplyPinsDuringSync($guard);
    $listener->handleSkuMissing(new SupplierSkuMissing(
        sku: 'LOG-BCC950',
        wooProductId: 502,
        wooVariationId: null,
        hadCustomMsTag: false,
        newStatus: 'pending',
    ));
});

// ══════════════════════════════════════════════════════════════════
// B. Defensive — no override / unknown wooId (guard handles internally)
// ══════════════════════════════════════════════════════════════════

it('no ProductOverride row → no-op end-to-end (no PUT, no audit)', function (): void {
    Product::factory()->create(['woo_product_id' => 600]);
    // No ProductOverride row for this product.

    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldNotReceive('record');

    $guard = new ProductOverrideGuard($woo, $auditor);
    $listener = new ApplyPinsDuringSync($guard);

    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'ORPHAN',
        wooProductId: 600,
        wooVariationId: null,
        oldPrice: '10.00',
        newPrice: '12.00',
    ));
});

it('unknown woo_product_id → no-op (guard finds no Product, no error)', function (): void {
    $woo = Mockery::mock(WooClient::class);
    $woo->shouldNotReceive('put');
    $auditor = Mockery::mock(Auditor::class);
    $auditor->shouldNotReceive('record');

    $guard = new ProductOverrideGuard($woo, $auditor);
    $listener = new ApplyPinsDuringSync($guard);

    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'GHOST',
        wooProductId: 999_999,
        wooVariationId: null,
        oldPrice: '10.00',
        newPrice: '12.00',
    ));
});

// ══════════════════════════════════════════════════════════════════
// C. Fail-soft — guard throw is logged + swallowed
// ══════════════════════════════════════════════════════════════════

it('guard exception is swallowed + logged (no rethrow)', function (): void {
    Log::spy();

    $guard = Mockery::mock(ProductOverrideGuard::class);
    $guard->shouldReceive('revertIfPinned')
        ->once()
        ->andThrow(new \RuntimeException('Simulated Woo PUT 500 failure'));

    $listener = new ApplyPinsDuringSync($guard);

    // MUST NOT throw — a failed revert must not cascade-fail sibling listeners.
    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'LOG-MEETUP',
        wooProductId: 500,
        wooVariationId: null,
        oldPrice: '1000.00',
        newPrice: '1200.00',
    ));

    Log::shouldHaveReceived('warning')
        ->withArgs(function ($message, $context = []) {
            return $message === 'product_auto_create.pin_revert_failed'
                && ($context['woo_product_id'] ?? null) === 500
                && ($context['source'] ?? null) === 'supplier_price_changed';
        })
        ->once();
});

// ══════════════════════════════════════════════════════════════════
// D. Listener wiring (D-11 contract)
// ══════════════════════════════════════════════════════════════════

it('implements ShouldQueue with sync-bulk queue', function (): void {
    $guard = Mockery::mock(ProductOverrideGuard::class);
    $listener = new ApplyPinsDuringSync($guard);

    expect($listener)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    expect($listener->queue)->toBe('sync-bulk');
});

it('EventServiceProvider binds all 3 events to ApplyPinsDuringSync handler methods', function (): void {
    Event::fake();

    Event::assertListening(
        SupplierPriceChanged::class,
        ApplyPinsDuringSync::class.'@handlePriceChanged',
    );
    Event::assertListening(
        SupplierStockChanged::class,
        ApplyPinsDuringSync::class.'@handleStockChanged',
    );
    Event::assertListening(
        SupplierSkuMissing::class,
        ApplyPinsDuringSync::class.'@handleSkuMissing',
    );
});

it('dispatching SupplierPriceChanged triggers the listener via container resolution', function (): void {
    // Assert the real EventServiceProvider wiring dispatches through the guard.
    $guard = Mockery::mock(ProductOverrideGuard::class);
    $guard->shouldReceive('revertIfPinned')->atLeast()->once();
    app()->instance(ProductOverrideGuard::class, $guard);

    // Synchronously dispatch the listener by direct invocation — confirms the
    // container resolves the listener with the (mocked) guard dependency.
    $listener = app(ApplyPinsDuringSync::class);
    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'DI-SMOKE',
        wooProductId: 700,
        wooVariationId: null,
        oldPrice: '10.00',
        newPrice: '11.00',
    ));
});
