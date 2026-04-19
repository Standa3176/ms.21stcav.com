<?php

declare(strict_types=1);

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\PriceRecomputer;
use App\Domain\Pricing\Services\RecomputeOutcome;
use App\Domain\Pricing\Services\RecomputeOutcomeKind;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Models\ImportIssue;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 04 Task 1 — PriceRecomputer shared core service.
|--------------------------------------------------------------------------
|
| PriceRecomputer is the unified "given a SKU, recompute its price" pipeline
| shared by:
|   - RecomputePriceListener (Plan 02)   → persist=true, called per
|                                          SupplierPriceChanged event
|   - RecomputePriceJob      (Plan 04)   → persist from command flag
|                                          (--live → true, --dry-run → false)
|
| Dry-run semantics (D-12, mirroring Phase 2 D-04):
|   - persist=false → ImportIssue still written for zero/null buy_price (the
|                     issue is a data-quality fact regardless of the flag)
|                  → sell_price NOT written
|                  → ProductPriceChanged NOT dispatched
|                  → outcome kind still reports what WOULD have changed
|                    (so the bulk command's report can show "would update N")
|
| Formula for a £50 supplier price under the seeded <£100 35% tier + 20% VAT:
|   5000 × 13500 × 12000 / 100_000_000 = 8100 pennies = £81.00
*/

beforeEach(function () {
    $this->seed(DefaultPricingTierSeeder::class);
    Event::fake([ProductPriceChanged::class]);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — persist=true + price changed
// ══════════════════════════════════════════════════════════════════════════════

it('persist=true + price changed: writes sell_price, emits event, reports Changed', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77001,
        'sku' => 'PR-CHG-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '80.0000',  // will differ from computed 8100
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77001,
        wooVariationId: null,
        sku: 'PR-CHG-001',
        correlationId: '11111111-2222-4333-8444-555555555501',
        persist: true,
    );

    expect($outcome)->toBeInstanceOf(RecomputeOutcome::class);
    expect($outcome->kind)->toBe(RecomputeOutcomeKind::Changed);
    expect($outcome->productId)->toBe($product->id);
    expect($outcome->variantId)->toBeNull();
    expect($outcome->oldPennies)->toBe(8000);
    expect($outcome->newPennies)->toBe(8100);
    expect($outcome->resolutionSource)->toBe('default_tier');
    expect($outcome->marginBasisPoints)->toBe(3500);

    expect($product->fresh()->sell_price)->toBe('81.0000');

    Event::assertDispatched(
        ProductPriceChanged::class,
        fn ($e) => $e->newPennies === 8100 && $e->oldPennies === 8000,
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — persist=true + price unchanged
// ══════════════════════════════════════════════════════════════════════════════

it('persist=true + price unchanged: no write, no event, reports Unchanged', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77002,
        'sku' => 'PR-NOOP-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '81.0000',  // already matches computed
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77002,
        wooVariationId: null,
        sku: 'PR-NOOP-001',
        correlationId: '11111111-2222-4333-8444-555555555502',
        persist: true,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::Unchanged);
    expect($outcome->oldPennies)->toBe(8100);
    expect($outcome->newPennies)->toBe(8100);

    expect($product->fresh()->sell_price)->toBe('81.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — persist=false + price would change (DRY-RUN)
// ══════════════════════════════════════════════════════════════════════════════

it('persist=false + would change: reports Changed, DB untouched, no event dispatched', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77003,
        'sku' => 'PR-DRY-CHG-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '80.0000',
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77003,
        wooVariationId: null,
        sku: 'PR-DRY-CHG-001',
        correlationId: '11111111-2222-4333-8444-555555555503',
        persist: false,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::Changed);
    expect($outcome->oldPennies)->toBe(8000);
    expect($outcome->newPennies)->toBe(8100);

    expect($product->fresh()->sell_price)->toBe('80.0000');  // UNCHANGED — dry-run
    Event::assertNotDispatched(ProductPriceChanged::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — persist=false + price same
// ══════════════════════════════════════════════════════════════════════════════

it('persist=false + price same: reports Unchanged, nothing touched', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77004,
        'sku' => 'PR-DRY-NOOP-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '81.0000',
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77004,
        wooVariationId: null,
        sku: 'PR-DRY-NOOP-001',
        correlationId: '11111111-2222-4333-8444-555555555504',
        persist: false,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::Unchanged);
    expect($outcome->newPennies)->toBe(8100);

    expect($product->fresh()->sell_price)->toBe('81.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — persist=true + zero buy_price → ImportIssue + ZeroPriceSkipped
// ══════════════════════════════════════════════════════════════════════════════

it('persist=true + zero buy_price: writes ImportIssue, reports ZeroPriceSkipped, sell_price untouched', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77005,
        'sku' => 'PR-ZERO-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => null,
        'sell_price' => '80.0000',
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77005,
        wooVariationId: null,
        sku: 'PR-ZERO-001',
        correlationId: '11111111-2222-4333-8444-555555555505',
        persist: true,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::ZeroPriceSkipped);

    expect($product->fresh()->sell_price)->toBe('80.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);

    $issue = ImportIssue::where('sku', 'PR-ZERO-001')->first();
    expect($issue)->not->toBeNull();
    expect($issue->issue_type)->toBe(ImportIssue::TYPE_MISSING_COST_PRICE);
    expect($issue->correlation_id)->toBe('11111111-2222-4333-8444-555555555505');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — persist=false + zero buy_price → STILL writes ImportIssue (data-quality fact)
// ══════════════════════════════════════════════════════════════════════════════

it('persist=false + zero buy_price: STILL writes ImportIssue (data-quality fact regardless of dry-run)', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77006,
        'sku' => 'PR-DRY-ZERO-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => null,
        'sell_price' => '80.0000',
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77006,
        wooVariationId: null,
        sku: 'PR-DRY-ZERO-001',
        correlationId: '11111111-2222-4333-8444-555555555506',
        persist: false,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::ZeroPriceSkipped);

    expect($product->fresh()->sell_price)->toBe('80.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);

    $issue = ImportIssue::where('sku', 'PR-DRY-ZERO-001')->first();
    expect($issue)->not->toBeNull();
    expect($issue->issue_type)->toBe(ImportIssue::TYPE_MISSING_COST_PRICE);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 7 — persist=true + no rule matched
// ══════════════════════════════════════════════════════════════════════════════

it('persist=true + no rule matched: reports NoRuleMatched, no write, no event, no ImportIssue', function () {
    // Clear the seeded default tiers so the resolver has nothing to match.
    \App\Domain\Pricing\Models\PricingRule::query()->delete();

    $product = Product::factory()->create([
        'woo_product_id' => 77007,
        'sku' => 'PR-NORULE-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '80.0000',
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77007,
        wooVariationId: null,
        sku: 'PR-NORULE-001',
        correlationId: '11111111-2222-4333-8444-555555555507',
        persist: true,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::NoRuleMatched);

    expect($product->fresh()->sell_price)->toBe('80.0000');
    Event::assertNotDispatched(ProductPriceChanged::class);
    expect(ImportIssue::count())->toBe(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 8 — product not found
// ══════════════════════════════════════════════════════════════════════════════

it('product not found: reports ProductNotFound, nothing touched', function () {
    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 99999999,  // does not exist
        wooVariationId: null,
        sku: 'PR-MISSING-001',
        correlationId: '11111111-2222-4333-8444-555555555508',
        persist: true,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::ProductNotFound);
    expect($outcome->productId)->toBe(0);
    expect($outcome->newPennies)->toBeNull();

    Event::assertNotDispatched(ProductPriceChanged::class);
    expect(ImportIssue::count())->toBe(0);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 9 — variant path
// ══════════════════════════════════════════════════════════════════════════════

it('variant path: updates product_variants.sell_price and reports variantId', function () {
    $product = Product::factory()->variable()->create([
        'woo_product_id' => 77009,
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => null,
    ]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'woo_variation_id' => 88801,
        'sku' => 'PR-VAR-001',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '70.0000',
    ]);

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77009,
        wooVariationId: 88801,
        sku: 'PR-VAR-001',
        correlationId: '11111111-2222-4333-8444-555555555509',
        persist: true,
    );

    expect($outcome->kind)->toBe(RecomputeOutcomeKind::Changed);
    expect($outcome->variantId)->toBe($variant->id);
    expect($outcome->newPennies)->toBe(8100);

    expect($variant->fresh()->sell_price)->toBe('81.0000');

    Event::assertDispatched(
        ProductPriceChanged::class,
        fn ($e) => $e->variantId === $variant->id && $e->newPennies === 8100,
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 10 — ImportIssue idempotency on repeat zero-price call
// ══════════════════════════════════════════════════════════════════════════════

it('repeat zero-price recompute produces exactly ONE unresolved ImportIssue and bumps last_seen_at', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77010,
        'sku' => 'PR-IDEM-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => null,
        'sell_price' => '80.0000',
    ]);

    $recomputer = app(PriceRecomputer::class);

    $recomputer->recompute(
        wooProductId: 77010,
        wooVariationId: null,
        sku: 'PR-IDEM-001',
        correlationId: '11111111-2222-4333-8444-555555555510',
        persist: true,
    );

    $first = ImportIssue::where('sku', 'PR-IDEM-001')->unresolved()->firstOrFail();
    $firstSeen = $first->last_seen_at?->toIso8601String();

    usleep(1_100_000);  // 1.1s so bump is observable

    $recomputer->recompute(
        wooProductId: 77010,
        wooVariationId: null,
        sku: 'PR-IDEM-001',
        correlationId: '11111111-2222-4333-8444-555555555510',
        persist: true,
    );

    $rows = ImportIssue::where('sku', 'PR-IDEM-001')->unresolved()->get();
    expect($rows)->toHaveCount(1);

    $second = $rows->first();
    expect($second->id)->toBe($first->id);
    expect($second->last_seen_at?->toIso8601String())->not->toBe($firstSeen);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 11 — correlation_id threaded onto ProductPriceChanged
// ══════════════════════════════════════════════════════════════════════════════

it('correlation_id threads from recompute() into dispatched ProductPriceChanged', function () {
    $cid = '77777777-aaaa-4bbb-8ccc-dddddddddddd';
    Context::add('correlation_id', $cid);

    $product = Product::factory()->create([
        'woo_product_id' => 77011,
        'sku' => 'PR-CID-001',
        'type' => 'simple',
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
        'sell_price' => '80.0000',
    ]);

    app(PriceRecomputer::class)->recompute(
        wooProductId: 77011,
        wooVariationId: null,
        sku: 'PR-CID-001',
        correlationId: $cid,
        persist: true,
    );

    Event::assertDispatched(
        ProductPriceChanged::class,
        fn ($e) => $e->correlationId === $cid,
    );
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 12 — ProductOverride precedence path also works
// ══════════════════════════════════════════════════════════════════════════════

it('ProductOverride(margin=4000) beats default tier and reports resolutionSource=override', function () {
    $product = Product::factory()->create([
        'woo_product_id' => 77012,
        'sku' => 'PR-OVR-001',
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

    $outcome = app(PriceRecomputer::class)->recompute(
        wooProductId: 77012,
        wooVariationId: null,
        sku: 'PR-OVR-001',
        correlationId: '11111111-2222-4333-8444-555555555512',
        persist: true,
    );

    // 5000 × 14000 × 12000 / 100_000_000 = 8400 pennies → £84.00
    expect($outcome->kind)->toBe(RecomputeOutcomeKind::Changed);
    expect($outcome->resolutionSource)->toBe('override');
    expect($outcome->marginBasisPoints)->toBe(4000);
    expect($outcome->newPennies)->toBe(8400);

    expect($product->fresh()->sell_price)->toBe('84.0000');
});
