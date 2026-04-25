<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Services\TradeRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 03 Task 3 — Golden fixture v2 ship gate (TRDE-03)
|--------------------------------------------------------------------------
|
| Penny-exact end-to-end run across all 80 golden fixtures (50 v1 retail +
| 30 v2 trade) through PriceCalculator + (Trade)RuleResolver:
|
|   - v1 entries (fx-001..fx-050, no customer_group_id key): build a retail
|     default_tier PricingRule, resolve via base RuleResolver, assert source
|     string and PriceCalculator output match. v1 path is byte-identical to
|     Phase 3.
|
|   - v2 entries (fx-051..fx-080, with customer_group_id key): build the
|     trade or retail rule per fixture's `customer_group_id` field, resolve
|     via TradeRuleResolver passing `lookup_customer_group_id`, assert BOTH
|     the resolution.source matches `expected_resolution_source` AND the
|     calculator output matches `expected_final_pennies`.
|
| Each test BUILDS the rule from the fixture's metadata: rule_scope,
| customer_group_id, brand_id, category_id, margin_basis_points, plus
| optional ProductOverride (Group D fx-079, fx-080).
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01/09-02. Probe
| runs in beforeEach BEFORE RefreshDatabase fires.
|
| Dataset shape: keyed by fixture id (matches Phase 3 PriceCalculatorGoldenFixtureTest)
| so failing-test descriptions show "with dataset 'fx-051'" etc. — easy debug.
*/

beforeEach(function (): void {
    // Probe BEFORE any test body runs. If MySQL is offline this throws and
    // Pest marks the test skipped — RefreshDatabase migrations never fire.
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
});

/**
 * Load all 80 golden fixtures keyed by id (matches Phase 3 helper pattern).
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function goldenFixturesV1V2(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = __DIR__.'/../../Fixtures/Pricing/golden-fixtures.json';
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read golden fixtures: {$path}");
    }

    $fixtures = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    $rows = [];
    foreach ($fixtures as $fx) {
        $rows[$fx['id']] = [$fx];
    }

    return $cache = $rows;
}

it('penny-exact through PriceCalculator + (Trade)RuleResolver', function (array $entry): void {
    $isV2 = array_key_exists('customer_group_id', $entry);

    if ($isV2) {
        // v2 fixture — full trade-aware build.
        $product = Product::factory()->create([
            'brand_id' => $entry['brand_id'] ?? null,
            'category_id' => $entry['category_id'] ?? null,
            'buy_price' => $entry['supplier_pennies'] / 100,
        ]);

        // Create the underlying rule (skip for 'override' scope — Group D
        // builds only the override; the trade rule is intentionally absent).
        if ($entry['rule_scope'] !== 'override') {
            $isTier = $entry['rule_scope'] === 'default_tier';

            // Group A row 5 fall-through scenarios: lookup_group_id is set
            // but there's no trade rule — assert source 'brand_category'
            // (v1 path). Build the rule as a RETAIL rule (group_id = null)
            // so v1 RuleResolver picks it up at Layer 5 fall-through.
            $isFallthrough = $entry['expected_resolution_source'] === 'brand_category'
                && ! empty($entry['lookup_customer_group_id']);

            $ruleGroupId = $isFallthrough ? null : ($entry['customer_group_id'] ?? null);

            PricingRule::factory()->create([
                'scope' => $entry['rule_scope'],
                'brand_id' => $entry['brand_id'] ?? null,
                'category_id' => $entry['category_id'] ?? null,
                'customer_group_id' => $ruleGroupId,
                'margin_basis_points' => $entry['margin_basis_points'],
                'priority' => 100,
                'is_default_tier' => $isTier,
                'tier_min_pennies' => $isTier ? 0 : null,
                'tier_max_pennies' => $isTier ? 999999 : null,
                'active' => true,
            ]);
        }

        if (! empty($entry['has_product_override'])) {
            ProductOverride::factory()->create([
                'product_id' => $product->id,
                'margin_basis_points' => $entry['override_margin_basis_points'],
            ]);
        }

        $resolver = app(TradeRuleResolver::class);
        $resolution = $resolver->resolve(
            $product->fresh(),
            $entry['lookup_customer_group_id'] ?? null,
        );

        expect($resolution->source)->toBe(
            $entry['expected_resolution_source'],
            "Fixture {$entry['id']} resolution source mismatch: expected {$entry['expected_resolution_source']}, got {$resolution->source}",
        );
    } else {
        // v1 fixture — base RuleResolver path, byte-identical to Phase 3.
        // v1 fixtures don't carry brand_id / category_id metadata; use a
        // default_tier rule that covers the supplier_pennies range so the
        // resolver picks it up via Layer 4.
        $product = Product::factory()->create([
            'brand_id' => null,
            'category_id' => null,
            'buy_price' => $entry['supplier_pennies'] / 100,
        ]);

        PricingRule::factory()->create([
            'scope' => PricingRule::SCOPE_DEFAULT_TIER,
            'brand_id' => null,
            'category_id' => null,
            'customer_group_id' => null,
            'margin_basis_points' => $entry['margin_basis_points'],
            'priority' => 100,
            'is_default_tier' => true,
            'tier_min_pennies' => 0,
            'tier_max_pennies' => 99999999,
            'active' => true,
        ]);

        $resolver = app(RuleResolver::class);
        $resolution = $resolver->resolve($product->fresh());
    }

    $calculator = app(PriceCalculator::class);
    $actual = $calculator->compute(
        $entry['supplier_pennies'],
        $resolution->marginBasisPoints,
        $entry['vat_basis_points'],
    );

    expect($actual)->toBe(
        $entry['expected_final_pennies'],
        "Fixture {$entry['id']} expected {$entry['expected_final_pennies']}p, got {$actual}p",
    );
})->with(goldenFixturesV1V2());

it('total dataset count is 80 (50 v1 retail + 30 v2 trade)', function (): void {
    $path = base_path('tests/Fixtures/Pricing/golden-fixtures.json');
    $fixtures = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect($fixtures)->toHaveCount(80);

    $v2 = array_slice($fixtures, 50);
    foreach ($v2 as $entry) {
        expect($entry)->toHaveKeys([
            'customer_group_id',
            'lookup_customer_group_id',
            'expected_resolution_source',
            'rule_scope',
        ]);
    }
});
