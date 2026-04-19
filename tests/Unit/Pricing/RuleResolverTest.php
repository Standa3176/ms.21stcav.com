<?php

declare(strict_types=1);

use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\PricingResolution;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 02 Task 1 — RuleResolver resolution-order tests.
|--------------------------------------------------------------------------
|
| Exercises every layer of the D-07 resolver walk:
|   1. ProductOverride (D-08 — beats all rules)
|   2. brand_category
|   3. category
|   4. brand
|   5. default_tier (Pitfall 7 null-safe buy_price walk)
|
| Plus the two tiebreak layers (priority DESC, id ASC), the active=false skip,
| chain-array ordering, and the catalogue-empty throw.
*/

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — ProductOverride beats a matching brand_category rule (D-08)
// ══════════════════════════════════════════════════════════════════════════════

it('returns ProductOverride margin even when a matching brand_category rule exists (D-08)', function () {
    $product = Product::factory()->create([
        'brand_id' => 10,
        'category_id' => 20,
        'buy_price' => '50.0000',
    ]);

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 10,
        'category_id' => 20,
        'margin_basis_points' => 2200,
    ]);

    $override = ProductOverride::factory()->create([
        'product_id' => $product->id,
        'margin_basis_points' => 4000,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res)->toBeInstanceOf(PricingResolution::class);
    expect($res->marginBasisPoints)->toBe(4000);
    expect($res->source)->toBe('override');
    expect($res->matchedRuleId)->toBeNull();
    expect($res->overrideId)->toBe($override->id);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — brand_category beats category
// ══════════════════════════════════════════════════════════════════════════════

it('prefers brand_category over a matching category rule', function () {
    $product = Product::factory()->create([
        'brand_id' => 11,
        'category_id' => 21,
        'buy_price' => '50.0000',
    ]);

    $bc = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 11,
        'category_id' => 21,
        'margin_basis_points' => 2500,
    ]);

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_CATEGORY,
        'brand_id' => null,
        'category_id' => 21,
        'margin_basis_points' => 2000,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->marginBasisPoints)->toBe(2500);
    expect($res->source)->toBe('brand_category');
    expect($res->matchedRuleId)->toBe($bc->id);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — category beats brand (category is more specific)
// ══════════════════════════════════════════════════════════════════════════════

it('prefers category over a matching brand rule', function () {
    $product = Product::factory()->create([
        'brand_id' => 12,
        'category_id' => 22,
        'buy_price' => '50.0000',
    ]);

    $cat = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_CATEGORY,
        'brand_id' => null,
        'category_id' => 22,
        'margin_basis_points' => 2700,
    ]);

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 12,
        'category_id' => null,
        'margin_basis_points' => 2400,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->marginBasisPoints)->toBe(2700);
    expect($res->source)->toBe('category');
    expect($res->matchedRuleId)->toBe($cat->id);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 4 — brand beats default_tier
// ══════════════════════════════════════════════════════════════════════════════

it('prefers brand over default_tier when both match', function () {
    $product = Product::factory()->create([
        'brand_id' => 13,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    $brand = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 13,
        'category_id' => null,
        'margin_basis_points' => 2300,
    ]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 9999,
        'margin_basis_points' => 3500,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->marginBasisPoints)->toBe(2300);
    expect($res->source)->toBe('brand');
    expect($res->matchedRuleId)->toBe($brand->id);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 5 — default_tier fallback when nothing else matches
// ══════════════════════════════════════════════════════════════════════════════

it('falls through to the correct default_tier bucket for buy_price £50', function () {
    $product = Product::factory()->create([
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 9999,
        'margin_basis_points' => 3500,
    ]);
    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 10000,
        'tier_max_pennies' => 49999,
        'margin_basis_points' => 2800,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->marginBasisPoints)->toBe(3500);
    expect($res->source)->toBe('default_tier');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 6 — default_tier boundary semantics (upper inclusive)
// ══════════════════════════════════════════════════════════════════════════════

it('selects tiers by inclusive bounds — £99.99 → <£100, £100.00 → £100-499', function () {
    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => 9999,
        'margin_basis_points' => 3500,
    ]);
    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 10000,
        'tier_max_pennies' => 49999,
        'margin_basis_points' => 2800,
    ]);

    $just_under = Product::factory()->create([
        'brand_id' => null, 'category_id' => null, 'buy_price' => '99.9900',
    ]);
    $on_boundary = Product::factory()->create([
        'brand_id' => null, 'category_id' => null, 'buy_price' => '100.0000',
    ]);

    $resolver = app(RuleResolver::class);

    expect($resolver->resolve($just_under->fresh())->marginBasisPoints)->toBe(3500);
    expect($resolver->resolve($on_boundary->fresh())->marginBasisPoints)->toBe(2800);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 7 — open-ended upper bound (£500+)
// ══════════════════════════════════════════════════════════════════════════════

it('matches open-ended upper tier (tier_max_pennies IS NULL) for high buy_price', function () {
    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 50000,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2200,
    ]);

    $product = Product::factory()->create([
        'brand_id' => null, 'category_id' => null, 'buy_price' => '5000.0000',
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->marginBasisPoints)->toBe(2200);
    expect($res->source)->toBe('default_tier');
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 8 — priority DESC tiebreak
// ══════════════════════════════════════════════════════════════════════════════

it('higher priority wins when two brand_category rules are otherwise identical', function () {
    $product = Product::factory()->create([
        'brand_id' => 14, 'category_id' => 24, 'buy_price' => '50.0000',
    ]);

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 14, 'category_id' => 24,
        'margin_basis_points' => 2500,
        'priority' => 100,
    ]);
    $high = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 14, 'category_id' => 24,
        'margin_basis_points' => 3300,
        'priority' => 200,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->marginBasisPoints)->toBe(3300);
    expect($res->matchedRuleId)->toBe($high->id);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 9 — id ASC tiebreak (when priorities equal)
// ══════════════════════════════════════════════════════════════════════════════

it('earlier id wins when priority ties', function () {
    $product = Product::factory()->create([
        'brand_id' => 15, 'category_id' => 25, 'buy_price' => '50.0000',
    ]);

    $first = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 15, 'category_id' => 25,
        'margin_basis_points' => 2500,
        'priority' => 100,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 15, 'category_id' => 25,
        'margin_basis_points' => 3300,
        'priority' => 100,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->matchedRuleId)->toBe($first->id);
    expect($res->marginBasisPoints)->toBe(2500);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 10 — active=false rules are skipped
// ══════════════════════════════════════════════════════════════════════════════

it('skips inactive rules and falls through to the next layer', function () {
    $product = Product::factory()->create([
        'brand_id' => 16, 'category_id' => 26, 'buy_price' => '50.0000',
    ]);

    PricingRule::factory()->inactive()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 16, 'category_id' => 26,
        'margin_basis_points' => 9999,
    ]);

    $category = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_CATEGORY,
        'brand_id' => null, 'category_id' => 26,
        'margin_basis_points' => 2700,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->source)->toBe('category');
    expect($res->matchedRuleId)->toBe($category->id);
    expect($res->marginBasisPoints)->toBe(2700);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 11 — NoPricingRuleMatchedException when catalogue is empty
// ══════════════════════════════════════════════════════════════════════════════

it('throws NoPricingRuleMatchedException when no rule matches any layer', function () {
    $product = Product::factory()->create([
        'brand_id' => null,
        'category_id' => null,
        'buy_price' => '50.0000',
    ]);

    app(RuleResolver::class)->resolve($product->fresh());
})->throws(NoPricingRuleMatchedException::class);

// ══════════════════════════════════════════════════════════════════════════════
// Test 12 — chain ordering reflects the walk even when an earlier layer wins
// ══════════════════════════════════════════════════════════════════════════════

it('populates chain in walk order — brand resolution yields [brand_category, category, brand]', function () {
    $product = Product::factory()->create([
        'brand_id' => 17,
        'category_id' => 27,
        'buy_price' => '50.0000',
    ]);

    // Only a brand rule exists — so the resolver walks brand_category (no match),
    // then category (no match), then brand (hit).
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 17, 'category_id' => null,
        'margin_basis_points' => 2300,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->source)->toBe('brand');
    expect($res->chain)->toBe(['brand_category', 'category', 'brand']);
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 13 — override short-circuits the chain to ['override']
// ══════════════════════════════════════════════════════════════════════════════

it('override source returns chain=[override] with no rule layers visited', function () {
    $product = Product::factory()->create([
        'brand_id' => 18, 'category_id' => 28, 'buy_price' => '50.0000',
    ]);

    ProductOverride::factory()->create([
        'product_id' => $product->id,
        'margin_basis_points' => 4000,
    ]);

    $res = app(RuleResolver::class)->resolve($product->fresh());

    expect($res->source)->toBe('override');
    expect($res->chain)->toBe(['override']);
});
