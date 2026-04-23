<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Listeners\RecomputeCompletenessOnSupplierChange;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Events\SupplierStockChanged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 03 Task 2 — RecomputeCompletenessOnSupplierChange listener
|--------------------------------------------------------------------------
| Plan 01 A3 FINDING: forceFill + saveQuietly suppresses both saving + saved
| events. Therefore an Eloquent observer approach would NEVER fire during the
| real Phase 2 sync path. This listener replaces the observer by subscribing
| directly to the Phase 2 supplier-change domain events.
|
| Covers:
|   - price changed → completeness score recomputed + 3 columns persisted.
|   - stock changed → recompute fires.
|   - sku missing → recompute fires.
|   - unknown woo_product_id → silent no-op (Product not yet auto-created).
|   - Event bindings in EventServiceProvider (Event::assertListening).
*/

beforeEach(function (): void {
    Context::add('correlation_id', (string) Str::uuid());
});

it('price-change handler recomputes + persists completeness', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 600,
        'auto_create_status' => 'draft',
        'completeness_score' => null,
        'completeness_missing_fields' => null,
        'completeness_computed_at' => null,
    ]);

    $listener = app(RecomputeCompletenessOnSupplierChange::class);
    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'FOO-01',
        wooProductId: 600,
        wooVariationId: null,
        oldPrice: '10.00',
        newPrice: '12.50',
    ));

    $product->refresh();
    expect($product->completeness_score)->not->toBeNull();
    expect($product->completeness_computed_at)->not->toBeNull();
    expect($product->completeness_missing_fields)->toBeArray();
});

it('stock-change handler recomputes', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 601,
        'completeness_score' => null,
    ]);

    $listener = app(RecomputeCompletenessOnSupplierChange::class);
    $listener->handleStockChanged(new SupplierStockChanged(
        sku: 'FOO-02',
        wooProductId: 601,
        wooVariationId: null,
        oldStock: 10,
        newStock: 3,
    ));

    expect($product->fresh()->completeness_score)->not->toBeNull();
});

it('sku-missing handler recomputes', function (): void {
    $product = Product::factory()->create([
        'woo_product_id' => 602,
        'completeness_score' => null,
    ]);

    $listener = app(RecomputeCompletenessOnSupplierChange::class);
    $listener->handleSkuMissing(new SupplierSkuMissing(
        sku: 'FOO-03',
        wooProductId: 602,
        wooVariationId: null,
        hadCustomMsTag: false,
        newStatus: 'pending',
    ));

    expect($product->fresh()->completeness_score)->not->toBeNull();
});

it('silent no-op when no product matches wooProductId', function (): void {
    $listener = app(RecomputeCompletenessOnSupplierChange::class);

    // No exception, no side effect. Product::count is unchanged.
    $before = Product::count();
    $listener->handlePriceChanged(new SupplierPriceChanged(
        sku: 'GHOST',
        wooProductId: 999999,
        wooVariationId: null,
        oldPrice: '1.00',
        newPrice: '2.00',
    ));

    expect(Product::count())->toBe($before);
});

it('listener is registered for all 3 supplier events in EventServiceProvider', function (): void {
    Event::fake();

    Event::assertListening(
        SupplierPriceChanged::class,
        RecomputeCompletenessOnSupplierChange::class.'@handlePriceChanged',
    );
    Event::assertListening(
        SupplierStockChanged::class,
        RecomputeCompletenessOnSupplierChange::class.'@handleStockChanged',
    );
    Event::assertListening(
        SupplierSkuMissing::class,
        RecomputeCompletenessOnSupplierChange::class.'@handleSkuMissing',
    );
});
