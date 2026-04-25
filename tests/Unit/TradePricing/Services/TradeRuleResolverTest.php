<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\PricingResolution;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\TradePricing\Services\TradeRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 02 Task 2 — TradeRuleResolver resolution-order tests (TRDE-02)
|--------------------------------------------------------------------------
|
| Locks every load-bearing invariant of the decorator:
|
|   - 4-quadrant NULL matrix (Pitfall B1):
|       a) null group → base RuleResolver (retail v1 source string).
|       b) explicit 0 → base RuleResolver (treat 0 same as null per D-03).
|       c) non-existent group ID → walks all trade layers, falls through to
|          base retail (no rule for the group, but a retail rule exists).
|       d) valid group ID with matching trade rule → 'trade_*' source.
|
|   - ProductOverride Layer 0 invariant (Pitfall 3): override beats EVERY
|     rule including trade rules with `priority + 100` bias.
|
|   - 5-tier specificity sort: most-specific group rule wins; falls to
|     less-specific group rules; finally falls through to retail.
|
|   - Tiebreak: priority DESC then id ASC inside each trade layer.
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01.
*/

function skipIfMySqlOfflineTrade(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

/**
 * Wrapper that probes the DB BEFORE RefreshDatabase migrations would fire.
 * setUp() runs traits via setUpTraits() AFTER our beforeEach hook seats —
 * so we hook into Pest's setUp ordering by registering the probe earliest.
 */
beforeEach(function (): void {
    // Probe BEFORE any test body runs. If MySQL is offline this throws and
    // Pest marks the test skipped — RefreshDatabase migrations never fire.
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }

    $this->trade = CustomerGroup::factory()->create([
        'slug' => 'trade-test',
        'name' => 'Trade Test',
        'is_active' => true,
        'display_order' => 100,
    ]);
    $this->resolver = app(TradeRuleResolver::class);
});

// ══════════════════════════════════════════════════════════════════════════════
// Quadrant A — null customer_group_id delegates to base RuleResolver verbatim
// ══════════════════════════════════════════════════════════════════════════════

it('null customer_group_id delegates to base RuleResolver verbatim', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => null,
        'margin_basis_points' => 2500,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), null);

    expect($resolution)->toBeInstanceOf(PricingResolution::class);
    // v1 source string — NOT 'trade_*' — proves base resolver was used.
    expect($resolution->source)->toBe('brand_category');
    expect($resolution->marginBasisPoints)->toBe(2500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Quadrant B — explicit 0 treated identically to null
// ══════════════════════════════════════════════════════════════════════════════

it('zero customer_group_id treated identically to null', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => null,
        'margin_basis_points' => 3000,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), 0);

    expect($resolution->source)->toBe('brand_category');
    expect($resolution->marginBasisPoints)->toBe(3000);
});

// ══════════════════════════════════════════════════════════════════════════════
// Quadrant C — non-existent group ID walks trade layers then falls through
// ══════════════════════════════════════════════════════════════════════════════

it('non-existent customer_group_id walks trade layers then falls through to base retail', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    // Retail rule exists but no trade rule for the bogus group id.
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => null,
        'margin_basis_points' => 2500,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), 99999);

    // Retail rule wins — proves Layer 5 fall-through hit v1 RuleResolver.
    expect($resolution->source)->toBe('brand_category');
    expect($resolution->marginBasisPoints)->toBe(2500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Quadrant D — valid group with matching trade rule returns trade_* source
// ══════════════════════════════════════════════════════════════════════════════

it('valid customer_group_id with matching trade rule returns trade_brand_category source', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 1800,
        'priority' => 200, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->source)->toBe('trade_brand_category');
    expect($resolution->marginBasisPoints)->toBe(1800);
});

// ══════════════════════════════════════════════════════════════════════════════
// Override beats trade (Pitfall 3 invariant)
// ══════════════════════════════════════════════════════════════════════════════

it('ProductOverride beats trade rule even with priority+100', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'margin_basis_points' => 1500,
    ]);
    // Trade rule with high priority — should still LOSE to override.
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 5000,
        'priority' => 200, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->source)->toBe('override');
    expect($resolution->marginBasisPoints)->toBe(1500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Layer 1 — trade_brand_category wins over trade_brand and trade_category
// ══════════════════════════════════════════════════════════════════════════════

it('trade_brand_category wins over trade_brand and trade_category for same group', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 1500,
        'priority' => 100, 'active' => true,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 2500,
        'priority' => 100, 'active' => true,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_CATEGORY,
        'brand_id' => null, 'category_id' => 1,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 2700,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->source)->toBe('trade_brand_category');
    expect($resolution->marginBasisPoints)->toBe(1500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Layer 2 — trade_category wins when no brand_category match (only trade_brand
// would otherwise be available; this asserts the category-before-brand order
// the resolver enforces in Layer 2 vs Layer 3).
// ══════════════════════════════════════════════════════════════════════════════

it('trade_category wins over trade_brand when no brand_category rule matches', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 2500,
        'priority' => 100, 'active' => true,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_CATEGORY,
        'brand_id' => null, 'category_id' => 1,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 2200,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->source)->toBe('trade_category');
    expect($resolution->marginBasisPoints)->toBe(2200);
});

// ══════════════════════════════════════════════════════════════════════════════
// Layer 3 — trade_brand wins when only brand-scoped trade rule exists
// ══════════════════════════════════════════════════════════════════════════════

it('trade_brand wins when only brand-scoped trade rule matches', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 2400,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->source)->toBe('trade_brand');
    expect($resolution->marginBasisPoints)->toBe(2400);
});

// ══════════════════════════════════════════════════════════════════════════════
// Layer 4 — trade_default_tier wins when no scope-specific group rule matches
// ══════════════════════════════════════════════════════════════════════════════

it('trade_default_tier wins when no scope-specific group rule matches', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 99, 'category_id' => 99, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->defaultTier()->create([
        'customer_group_id' => $this->trade->id,
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 9999,
        'margin_basis_points' => 2000,
        'priority' => 50,
        'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->source)->toBe('trade_default_tier');
    expect($resolution->marginBasisPoints)->toBe(2000);
});

// ══════════════════════════════════════════════════════════════════════════════
// Layer 5 — fall-through to base retail when group has no matching rule
// ══════════════════════════════════════════════════════════════════════════════

it('falls through to base retail when group has no matching rule', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    $reseller = CustomerGroup::factory()->create(['slug' => 'reseller-test']);

    // Trade rule exists for a DIFFERENT group; reseller asks for resolution.
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 1500,
        'priority' => 200, 'active' => true,
    ]);
    // Retail fallback rule.
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 1, 'category_id' => 1,
        'customer_group_id' => null,
        'margin_basis_points' => 3500,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $reseller->id);

    // Retail (v1 source string) — proves Layer 5 fell through to base.
    expect($resolution->source)->toBe('brand_category');
    expect($resolution->marginBasisPoints)->toBe(3500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Tiebreak — priority DESC then id ASC inside trade layer
// ══════════════════════════════════════════════════════════════════════════════

it('within trade layer, priority DESC wins (higher priority beats lower)', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 2000,
        'priority' => 100, 'active' => true,
    ]);
    $high = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 1500,
        'priority' => 200, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->matchedRuleId)->toBe($high->id);
    expect($resolution->marginBasisPoints)->toBe(1500);
});

it('within trade layer, equal priority tiebreak is id ASC (earlier id wins)', function (): void {
    $product = Product::factory()->create([
        'brand_id' => 1, 'category_id' => 1, 'buy_price' => '50.0000',
    ]);
    $first = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 1700,
        'priority' => 100, 'active' => true,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1, 'category_id' => null,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 1900,
        'priority' => 100, 'active' => true,
    ]);

    $resolution = $this->resolver->resolve($product->fresh(), $this->trade->id);

    expect($resolution->matchedRuleId)->toBe($first->id);
    expect($resolution->marginBasisPoints)->toBe(1700);
});

// ══════════════════════════════════════════════════════════════════════════════
// Singleton binding — same instance returned per request
// ══════════════════════════════════════════════════════════════════════════════

it('AppServiceProvider singleton returns same instance per request', function (): void {
    $a = app(TradeRuleResolver::class);
    $b = app(TradeRuleResolver::class);

    expect($a)->toBe($b);
    expect($a)->toBeInstanceOf(TradeRuleResolver::class);
});
