<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Services\PriceSnapshotter;
use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\TradePricing\Services\TradeRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 02 — PriceSnapshotterTest (QUOT-01 + QUOT-02 unit gate)
|--------------------------------------------------------------------------
|
| The PriceSnapshotter is the SOLE entry-point for line-price resolution
| within app/Domain/Quotes/. These four behavioural assertions lock the
| invariants the SHIP GATE (PinnedQuotePricesSurviveRuleEditTest) relies on:
|
|   1. unit_price_pence_at_quote IS int (Pitfall 1 — never decimal/float)
|      AND matches PriceCalculator::compute output for known buy_price +
|      margin_bps + default 20% VAT.
|
|   2. product_snapshot.matched_rule_id captures the winning rule id from
|      PricingResolution — proves the snapshot has the auditable resolution
|      trail before the integrity test downstream (Plan 11-02 Task 2)
|      mutates the rule.
|
|   3. line_total_pence_at_quote === unit_price_pence_at_quote * quantity_int
|      (integer math, no rounding in the multiplication step).
|
|   4. Quote.customer_group_id=null hits the Phase 9 retail fast-path
|      verbatim (Pitfall B1 4-quadrant NULL matrix). Asserts the result
|      is the same as if PriceCalculator had been called directly with
|      the override or v1-rule margin.
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01 + Plan 11-01.
| MySQL meetingstore_ops_testing must be online (phpunit.xml).
*/

function skipIfMySqlOfflinePriceSnapshotter(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflinePriceSnapshotter();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
});

it('returns integer pennies VAT-inclusive unit_price matching PriceCalculator output', function (): void {
    skipIfMySqlOfflinePriceSnapshotter();
    // Setup: a brand-scope rule at 25% margin for a known customer group;
    // product buy_price £100.00 (10000p). Expected: PriceCalculator::compute
    // returns the integer-pennies result; PriceSnapshotter must return the
    // SAME number byte-identical.
    $group = CustomerGroup::factory()->create();
    $product = Product::factory()->create([
        'sku' => 'SNAP-INT-001',
        'buy_price' => 100.0000,
        'brand_id' => 42,
        'category_id' => null,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $group->id,
        'brand_id' => 42,
        'category_id' => null,
        'margin_basis_points' => 2500,
        'priority' => 200,
        'active' => true,
    ]);

    $quote = Quote::factory()->create(['customer_group_id' => $group->id]);
    $snapshotter = app(PriceSnapshotter::class);
    $expectedUnit = app(PriceCalculator::class)->compute(10000, 2500, 2000);

    $line = $snapshotter->buildLine($quote, 'SNAP-INT-001', 1);

    expect($line['unit_price_pence_at_quote'])->toBeInt();
    expect($line['unit_price_pence_at_quote'])->toBe($expectedUnit);
})->uses(RefreshDatabase::class);

it('captures matched_rule_id of the highest-priority winning rule in product_snapshot', function (): void {
    $group = CustomerGroup::factory()->create();
    $product = Product::factory()->create([
        'sku' => 'SNAP-RULE-002',
        'buy_price' => 200.0000,
        'brand_id' => 7,
        'category_id' => null,
    ]);

    // Two competing brand rules — priority 200 should win over priority 100.
    $loser = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $group->id,
        'brand_id' => 7,
        'margin_basis_points' => 1500,
        'priority' => 100,
        'active' => true,
    ]);
    $winner = PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $group->id,
        'brand_id' => 7,
        'margin_basis_points' => 3500,
        'priority' => 200,
        'active' => true,
    ]);

    $quote = Quote::factory()->create(['customer_group_id' => $group->id]);
    $line = app(PriceSnapshotter::class)->buildLine($quote, 'SNAP-RULE-002', 1);

    expect($line['product_snapshot'])->toBeArray();
    expect($line['product_snapshot']['matched_rule_id'])->toBe($winner->id);
    expect($line['product_snapshot']['resolution_chain'])->toContain('trade_brand');
    expect($line['product_snapshot']['resolution_source'])->toBe('trade_brand');
    // Sentinel: snapshot_at IS captured (audit trail T-11-02-04 / T-11-02-05).
    expect($line['product_snapshot'])->toHaveKey('snapshot_at');
    // Sentinel: supplier_price is NEVER snapshotted (T-11-02-05).
    expect($line['product_snapshot'])->not->toHaveKey('buy_price');
    expect($line['product_snapshot'])->not->toHaveKey('supplier_price');
})->uses(RefreshDatabase::class);

it('computes line_total_pence_at_quote as unit_price * quantity_int (integer math)', function (): void {
    $group = CustomerGroup::factory()->create();
    Product::factory()->create([
        'sku' => 'SNAP-TOT-003',
        'buy_price' => 50.0000,
        'brand_id' => 11,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $group->id,
        'brand_id' => 11,
        'margin_basis_points' => 2000,
        'priority' => 100,
        'active' => true,
    ]);

    $quote = Quote::factory()->create(['customer_group_id' => $group->id]);
    $line = app(PriceSnapshotter::class)->buildLine($quote, 'SNAP-TOT-003', 5);

    expect($line['line_total_pence_at_quote'])->toBeInt();
    expect($line['line_total_pence_at_quote'])->toBe($line['unit_price_pence_at_quote'] * 5);
    expect($line['quantity_int'])->toBe(5);
})->uses(RefreshDatabase::class);

it('falls through to base RuleResolver retail fast-path when Quote.customer_group_id is null (Pitfall B1)', function (): void {
    // Retail-only rule (customer_group_id NULL) at 30% margin.
    Product::factory()->create([
        'sku' => 'SNAP-NULL-004',
        'buy_price' => 80.0000,
        'brand_id' => 99,
    ]);
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => null,        // retail rule
        'brand_id' => 99,
        'margin_basis_points' => 3000,
        'priority' => 100,
        'active' => true,
    ]);
    // Add a noise group rule that would WIN if customer_group_id leaked
    // through — proves the null fast-path doesn't reach for it.
    $noiseGroup = CustomerGroup::factory()->create();
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'customer_group_id' => $noiseGroup->id,
        'brand_id' => 99,
        'margin_basis_points' => 9999,      // would be obvious if it leaked
        'priority' => 999,
        'active' => true,
    ]);

    $quote = Quote::factory()->create(['customer_group_id' => null]);
    $line = app(PriceSnapshotter::class)->buildLine($quote, 'SNAP-NULL-004', 1);

    // Phase 9 Pitfall B1 — retail fast-path returns from the v1 RuleResolver,
    // which returns 'brand' as source (not 'trade_brand'). Group rule with
    // 9999 BPS is never reached.
    $expectedUnit = app(PriceCalculator::class)->compute(8000, 3000, 2000);
    expect($line['unit_price_pence_at_quote'])->toBe($expectedUnit);
    expect($line['product_snapshot']['resolution_source'])->toBe('brand');
})->uses(RefreshDatabase::class);
