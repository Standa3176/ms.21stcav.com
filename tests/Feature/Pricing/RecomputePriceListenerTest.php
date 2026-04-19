<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Listeners\RecomputePriceListener;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Models\ImportIssue;
use App\Providers\EventServiceProvider;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 02 Task 2 — RecomputePriceListener happy-path tests.
|--------------------------------------------------------------------------
|
| End-to-end: SupplierPriceChanged (Phase 2 event) → listener loads target
| Product/ProductVariant → RuleResolver picks the default tier (35% bucket
| via DefaultPricingTierSeeder) → PriceCalculator produces newPennies →
| sell_price written via forceFill+saveQuietly (activity-log-quiet) →
| ProductPriceChanged dispatched ONLY when newPennies !== oldPennies (D-13).
|
| Formula for a £50 supplier price under the seeded <£100 35% tier + 20% VAT:
|   5000 × (10000 + 3500) × (10000 + 2000) / 100_000_000
|   = 5000 × 13500 × 12000 / 100_000_000
|   = 810_000_000_000 / 100_000_000
|   = 8100 pennies = £81.00
*/

beforeEach(function () {
    $this->seed(DefaultPricingTierSeeder::class);
    Event::fake([ProductPriceChanged::class]);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — simple product happy path — sell_price updated, event fired
// ══════════════════════════════════════════════════════════════════════════════

it('simple product happy path: writes sell_price and dispatches ProductPriceChanged', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 55501,
        'sku' => 'HP-SIMPLE-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '80.0000',  // deliberately wrong so diff fires
    ]);

    $event = new SupplierPriceChanged(
        sku: 'HP-SIMPLE-001',
        wooProductId: 55501,
        wooVariationId: null,
        oldPrice: '40.00',
        newPrice: '50.00',
    );

    app(RecomputePriceListener::class)->handle($event);

    // 5000 × 13500 × 12000 / 100_000_000 = 8100 pennies → "81.0000" via number_format(…, 4)
    expect($product->fresh()->sell_price)->toBe('81.0000');

    Event::assertDispatched(
        ProductPriceChanged::class,
        fn ($e) => $e->productId === $product->id
            && $e->variantId === null
            && $e->sku === 'HP-SIMPLE-001'
            && $e->oldPennies === 8000
            && $e->newPennies === 8100
            && $e->marginBasisPoints === 3500
            && $e->resolutionSource === 'default_tier',
    );

    expect(ImportIssue::count())->toBe(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — no integer-penny diff → no event (D-13)
// ══════════════════════════════════════════════════════════════════════════════

it('no event when recomputed pennies equal stored sell_price pennies (D-13)', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 55502,
        'sku' => 'HP-NOOP-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '81.0000',  // already matches computed value
    ]);

    $event = new SupplierPriceChanged(
        sku: 'HP-NOOP-001',
        wooProductId: 55502,
        wooVariationId: null,
        oldPrice: '50.00',
        newPrice: '50.00',
    );

    app(RecomputePriceListener::class)->handle($event);

    expect($product->fresh()->sell_price)->toBe('81.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — variant path: SupplierPriceChanged with wooVariationId
// ══════════════════════════════════════════════════════════════════════════════

it('variant path: updates product_variants.sell_price and emits event with variantId', function () {
    $product = Product::factory()->variable()->create([
        'woo_product_id' => 55503,
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => null,
    ]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'woo_variation_id' => 99991,
        'sku' => 'HP-VAR-001',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '70.0000',  // deliberately wrong
    ]);

    $event = new SupplierPriceChanged(
        sku: 'HP-VAR-001',
        wooProductId: 55503,
        wooVariationId: 99991,
        oldPrice: '40.00',
        newPrice: '50.00',
    );

    app(RecomputePriceListener::class)->handle($event);

    expect($variant->fresh()->sell_price)->toBe('81.0000');

    Event::assertDispatched(
        ProductPriceChanged::class,
        fn ($e) => $e->productId === $product->id
            && $e->variantId === $variant->id
            && $e->sku === 'HP-VAR-001'
            && $e->newPennies === 8100,
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — ProductOverride precedence — resolutionSource='override'
// ══════════════════════════════════════════════════════════════════════════════

it('ProductOverride(margin=4000) wins and event carries resolutionSource=override', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 55504,
        'sku' => 'HP-OVR-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '80.0000',
    ]);

    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'margin_basis_points' => 4000,
    ]);

    $event = new SupplierPriceChanged(
        sku: 'HP-OVR-001',
        wooProductId: 55504,
        wooVariationId: null,
        oldPrice: '40.00',
        newPrice: '50.00',
    );

    app(RecomputePriceListener::class)->handle($event);

    // 5000 × 14000 × 12000 / 100_000_000 = 8400 pennies → £84.00
    expect($product->fresh()->sell_price)->toBe('84.0000');

    Event::assertDispatched(
        ProductPriceChanged::class,
        fn ($e) => $e->marginBasisPoints === 4000
            && $e->resolutionSource === 'override'
            && $e->newPennies === 8400,
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — ShouldQueue interface
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceListener implements ShouldQueue', function () {
    $ref = new ReflectionClass(RecomputePriceListener::class);
    expect($ref->implementsInterface(ShouldQueue::class))->toBeTrue();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — default queue (not sync-woo-push)
// ══════════════════════════════════════════════════════════════════════════════

it('RecomputePriceListener runs on the default queue (not sync-woo-push)', function () {
    $listener = app(RecomputePriceListener::class);
    expect($listener->queue)->toBe('default');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 7 — correlation_id threading
// ══════════════════════════════════════════════════════════════════════════════

it('correlation_id threads from SupplierPriceChanged to emitted ProductPriceChanged', function () {
    Context::add('correlation_id', '11111111-2222-4333-8444-555555555555');

    $product = Product::factory()->create([
        'woo_product_id' => 55507,
        'sku' => 'HP-CID-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '80.0000',
    ]);

    // Event will inherit '11111111-2222-4333-8444-555555555555' via Context::get in DomainEvent constructor.
    $event = new SupplierPriceChanged(
        sku: 'HP-CID-001',
        wooProductId: 55507,
        wooVariationId: null,
        oldPrice: '40.00',
        newPrice: '50.00',
    );

    expect($event->correlationId)->toBe('11111111-2222-4333-8444-555555555555');

    app(RecomputePriceListener::class)->handle($event);

    Event::assertDispatched(
        ProductPriceChanged::class,
        fn ($e) => $e->correlationId === '11111111-2222-4333-8444-555555555555',
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 8 — EventServiceProvider registers RecomputePriceListener
// ══════════════════════════════════════════════════════════════════════════════

it('EventServiceProvider maps SupplierPriceChanged → RecomputePriceListener', function () {
    $provider = new EventServiceProvider(app());
    $listens = $provider->listens();

    expect($listens)->toHaveKey(SupplierPriceChanged::class);
    expect($listens[SupplierPriceChanged::class])
        ->toContain(RecomputePriceListener::class);
});
