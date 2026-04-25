<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\TradePricing\Services\TradeRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 06 Task 2 — TRDE-05 anonymous display posture (Pitfall B2)
|--------------------------------------------------------------------------
|
| Locks the resolver-layer behaviour for both config('b2b.anonymous_display')
| settings:
|   - 'retail' (default): anonymous viewer (customer_group_id = null) gets
|     the retail rule via v1 fast-path; trade rules never leak.
|   - 'hidden' (W-06 honesty): the resolver still returns retail when group
|     is null. The 'hidden' UI gate that renders "Login to see trade pricing"
|     is a UI-LAYER flag — implemented in the next phase that renders prices
|     to anonymous users (Phase 11 Quote / Phase 14 chatbot). Phase 9 ships
|     ONLY the config plumbing.
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01..05.
*/

function skipIfMySqlOfflineAnonymous(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineAnonymous();

    $this->trade = CustomerGroup::factory()->create(['slug' => 'trade', 'name' => 'Trade']);
    $this->product = Product::factory()->create([
        'brand_id' => 1,
        'category_id' => 1,
        'buy_price' => 50.00,
    ]);

    // Retail rule (no group) — base brand_category at 25% margin.
    PricingRule::query()->create([
        'scope' => 'brand_category',
        'brand_id' => 1,
        'category_id' => 1,
        'customer_group_id' => null,
        'margin_basis_points' => 2500,
        'priority' => 100,
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
        'active' => true,
    ]);

    // Trade rule (group = trade) — same scope, lower margin (15%) + priority+100 bias.
    PricingRule::query()->create([
        'scope' => 'brand_category',
        'brand_id' => 1,
        'category_id' => 1,
        'customer_group_id' => $this->trade->id,
        'margin_basis_points' => 1500,
        'priority' => 200,     // priority+100 over retail's 100 (D-03 bias)
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
        'active' => true,
    ]);
});

it('anonymous viewer with anonymous_display=retail sees retail price (Pitfall B2)', function (): void {
    Config::set('b2b.anonymous_display', 'retail');
    $resolver = app(TradeRuleResolver::class);

    $resolution = $resolver->resolve($this->product, null);

    expect($resolution->source)->toBe('brand_category');
    expect($resolution->marginBasisPoints)->toBe(2500);
});

it('anonymous viewer never sees trade-priority+100 discount under retail mode', function (): void {
    Config::set('b2b.anonymous_display', 'retail');
    $resolver = app(TradeRuleResolver::class);

    $resolution = $resolver->resolve($this->product, null);

    // The trade rule has priority 200 (100+100 bias) and would beat retail
    // if the resolver mistakenly considered it for an anonymous viewer.
    // Pitfall B2 says: never. The retail fast-path short-circuits.
    expect($resolution->marginBasisPoints)->not->toBe(1500);
});

/**
 * W-06 honesty — 'hidden' is a UI-layer flag, not a resolver-layer flag.
 *
 * The Phase 11 consumer (Quote flow) is the layer that will inspect
 * config('b2b.anonymous_display') === 'hidden' and choose to render
 * "Login to see trade pricing" instead of the resolved price.
 *
 * Phase 9 ships ONLY the config plumbing. The resolver returns retail
 * regardless of the 'retail' vs 'hidden' setting when group is null —
 * the UI surface decides whether to display that retail value or hide it.
 */
it('hidden mode is a display-layer flag — resolver still returns retail when group is null (W-06)', function (): void {
    Config::set('b2b.anonymous_display', 'hidden');
    $resolver = app(TradeRuleResolver::class);

    $resolution = $resolver->resolve($this->product, null);

    expect($resolution->source)->toBe('brand_category');
    expect($resolution->marginBasisPoints)->toBe(2500);
});

it('logged-in trade customer sees trade price under retail mode', function (): void {
    Config::set('b2b.anonymous_display', 'retail');
    $resolver = app(TradeRuleResolver::class);

    $resolution = $resolver->resolve($this->product, $this->trade->id);

    expect($resolution->source)->toBe('trade_brand_category');
    expect($resolution->marginBasisPoints)->toBe(1500);
});

it('logged-in trade customer sees trade price under hidden mode (UI gate is upstream)', function (): void {
    Config::set('b2b.anonymous_display', 'hidden');
    $resolver = app(TradeRuleResolver::class);

    $resolution = $resolver->resolve($this->product, $this->trade->id);

    // Authenticated trade users always get their group price; the 'hidden'
    // setting only governs how the UI renders for anonymous viewers.
    expect($resolution->source)->toBe('trade_brand_category');
    expect($resolution->marginBasisPoints)->toBe(1500);
});
