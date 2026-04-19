<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Listeners\RecomputePriceListener;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Models\ImportIssue;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 02 Task 2 — RecomputePriceListener zero-price path (D-10).
|--------------------------------------------------------------------------
|
| D-10: supplier price ≤ 0 MUST NOT touch products.sell_price. Listener must:
|   - updateOrCreate an ImportIssue row (issue_type=missing_cost_price) keyed on
|     (sku, woo_product_id, woo_variation_id, issue_type, resolved_at IS NULL)
|     so a repeat sync bumps last_seen_at instead of piling up rows (D-11).
|   - Thread correlation_id onto the ImportIssue for joinability with Phase 2
|     integration_events + audit_log.
|   - NOT emit ProductPriceChanged (no price to propagate).
*/

beforeEach(function () {
    $this->seed(DefaultPricingTierSeeder::class);
    Event::fake([ProductPriceChanged::class]);
});

function zeroPriceProduct(int $wooProductId, string $sku, ?string $buyPrice, string $sellPrice = '80.0000'): Product
{
    return Product::factory()->create([
        'woo_product_id' => $wooProductId,
        'sku' => $sku,
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => $buyPrice,
        'sell_price' => $sellPrice,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// Test Z1 — null buy_price → ImportIssue + sell_price untouched + no event
// ══════════════════════════════════════════════════════════════════════════════

it('null buy_price creates ImportIssue(missing_cost_price), leaves sell_price untouched, fires no event', function () {
    $product = zeroPriceProduct(91001, 'ZP-NULL-001', null);

    $event = new SupplierPriceChanged(
        sku: 'ZP-NULL-001',
        wooProductId: 91001,
        wooVariationId: null,
        oldPrice: '0.00',
        newPrice: '0.00',
    );

    app(RecomputePriceListener::class)->handle($event);

    expect($product->fresh()->sell_price)->toBe('80.0000');  // UNCHANGED

    $issue = ImportIssue::where('sku', 'ZP-NULL-001')->first();
    expect($issue)->not->toBeNull();
    expect($issue->issue_type)->toBe(ImportIssue::TYPE_MISSING_COST_PRICE);
    expect($issue->resolved_at)->toBeNull();

    Event::assertNotDispatched(ProductPriceChanged::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test Z2 — zero buy_price (0.0000) → same outcome as Z1
// ══════════════════════════════════════════════════════════════════════════════

it('zero buy_price creates ImportIssue and leaves sell_price untouched', function () {
    $product = zeroPriceProduct(91002, 'ZP-ZERO-001', '0.0000');

    $event = new SupplierPriceChanged(
        sku: 'ZP-ZERO-001',
        wooProductId: 91002,
        wooVariationId: null,
        oldPrice: '0.00',
        newPrice: '0.00',
    );

    app(RecomputePriceListener::class)->handle($event);

    expect($product->fresh()->sell_price)->toBe('80.0000');

    $issue = ImportIssue::where('sku', 'ZP-ZERO-001')->first();
    expect($issue)->not->toBeNull();
    expect($issue->issue_type)->toBe(ImportIssue::TYPE_MISSING_COST_PRICE);

    Event::assertNotDispatched(ProductPriceChanged::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test Z3 — negative buy_price
// ══════════════════════════════════════════════════════════════════════════════

it('negative buy_price creates ImportIssue and leaves sell_price untouched', function () {
    $product = zeroPriceProduct(91003, 'ZP-NEG-001', '-5.0000');

    $event = new SupplierPriceChanged(
        sku: 'ZP-NEG-001',
        wooProductId: 91003,
        wooVariationId: null,
        oldPrice: '0.00',
        newPrice: '0.00',
    );

    app(RecomputePriceListener::class)->handle($event);

    expect($product->fresh()->sell_price)->toBe('80.0000');

    $issue = ImportIssue::where('sku', 'ZP-NEG-001')->first();
    expect($issue)->not->toBeNull();
    expect($issue->issue_type)->toBe(ImportIssue::TYPE_MISSING_COST_PRICE);

    Event::assertNotDispatched(ProductPriceChanged::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test Z4 — idempotency: two runs produce ONE row with bumped last_seen_at (D-11)
// ══════════════════════════════════════════════════════════════════════════════

it('two listener runs for the same zero-price product produce exactly one unresolved ImportIssue (D-11)', function () {
    $product = zeroPriceProduct(91004, 'ZP-IDEM-001', null);

    $makeEvent = fn () => new SupplierPriceChanged(
        sku: 'ZP-IDEM-001',
        wooProductId: 91004,
        wooVariationId: null,
        oldPrice: '0.00',
        newPrice: '0.00',
    );

    $listener = app(RecomputePriceListener::class);
    $listener->handle($makeEvent());

    $afterFirst = ImportIssue::where('sku', 'ZP-IDEM-001')->unresolved()->first();
    expect($afterFirst)->not->toBeNull();
    $firstSeen = $afterFirst->last_seen_at?->toIso8601String();

    // advance a little so last_seen_at bump is observable
    usleep(1_100_000);  // 1.1s

    $listener->handle($makeEvent());

    $rows = ImportIssue::where('sku', 'ZP-IDEM-001')->unresolved()->get();
    expect($rows)->toHaveCount(1, 'updateOrCreate must not insert a second unresolved row');

    $afterSecond = $rows->first();
    expect($afterSecond->id)->toBe($afterFirst->id);

    $secondSeen = $afterSecond->last_seen_at?->toIso8601String();
    expect($secondSeen)->not->toBe($firstSeen, 'last_seen_at must be bumped on repeat');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test Z5 — correlation_id lands on the ImportIssue row
// ══════════════════════════════════════════════════════════════════════════════

it('ImportIssue.correlation_id matches the originating SupplierPriceChanged.correlationId', function () {
    Context::add('correlation_id', '77777777-aaaa-bbbb-cccc-deadbeefdead');

    $product = zeroPriceProduct(91005, 'ZP-CID-001', null);

    $event = new SupplierPriceChanged(
        sku: 'ZP-CID-001',
        wooProductId: 91005,
        wooVariationId: null,
        oldPrice: '0.00',
        newPrice: '0.00',
    );

    expect($event->correlationId)->toBe('77777777-aaaa-bbbb-cccc-deadbeefdead');

    app(RecomputePriceListener::class)->handle($event);

    $issue = ImportIssue::where('sku', 'ZP-CID-001')->first();
    expect($issue)->not->toBeNull();
    expect($issue->correlation_id)->toBe('77777777-aaaa-bbbb-cccc-deadbeefdead');
});
