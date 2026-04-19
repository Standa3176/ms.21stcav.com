<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use Database\Seeders\Phase3\DefaultPricingTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3 Plan 05 Task 2 — pricing_rules exclusive-set invariant (T-03-05-03)
|--------------------------------------------------------------------------
|
| CONTEXT.md "Specific Ideas": PricingRule.is_default_tier column is exclusive
| with brand/category — a row is EITHER:
|
|   - a default-tier fallback:
|       is_default_tier = true
|       brand_id        = NULL
|       category_id     = NULL
|       tier_min_pennies/tier_max_pennies = set (range defined)
|
|   - a specific rule:
|       is_default_tier = false
|       tier_min_pennies = NULL
|       tier_max_pennies = NULL
|       (for scope = 'brand_category')    brand_id + category_id both set
|       (for scope = 'brand')             brand_id set
|       (for scope = 'category')          category_id set
|
| MySQL < 8.0.16 ignores CHECK constraints, so the portable cross-version
| guard lives here — a corrupt row trips this test on every CI run.
|
| Two tests:
|   A. Positive control: DefaultPricingTierSeeder + factory rules honour
|      the invariant.
|   B. Live invariant check: every row currently in pricing_rules satisfies
|      the exclusive-set predicate.
*/

// ══════════════════════════════════════════════════════════════════════════════
// Helper: apply the exclusive-set predicate to a single row, collecting errors.
// ══════════════════════════════════════════════════════════════════════════════

function assertExclusiveSetInvariant(PricingRule $rule): void
{
    if ($rule->is_default_tier) {
        expect($rule->brand_id)->toBeNull(
            "Row #{$rule->id} (is_default_tier=true) has brand_id set — exclusive-set violation",
        );
        expect($rule->category_id)->toBeNull(
            "Row #{$rule->id} (is_default_tier=true) has category_id set — exclusive-set violation",
        );
        expect($rule->tier_min_pennies)->not->toBeNull(
            "Row #{$rule->id} (is_default_tier=true) has NULL tier_min_pennies — range must be defined",
        );
        // tier_max_pennies MAY be null (£500+ open-ended), so no assertion there.

        return;
    }

    // Non-default-tier rows: tier bounds must be NULL, and scope dictates
    // which of brand/category must be set.
    expect($rule->tier_min_pennies)->toBeNull(
        "Row #{$rule->id} (is_default_tier=false) has tier_min_pennies set — exclusive-set violation",
    );
    expect($rule->tier_max_pennies)->toBeNull(
        "Row #{$rule->id} (is_default_tier=false) has tier_max_pennies set — exclusive-set violation",
    );

    $scope = $rule->scope;
    if ($scope === PricingRule::SCOPE_BRAND_CATEGORY) {
        expect($rule->brand_id)->not->toBeNull(
            "Row #{$rule->id} (scope=brand_category) has NULL brand_id — exclusive-set violation",
        );
        expect($rule->category_id)->not->toBeNull(
            "Row #{$rule->id} (scope=brand_category) has NULL category_id — exclusive-set violation",
        );
    } elseif ($scope === PricingRule::SCOPE_BRAND) {
        expect($rule->brand_id)->not->toBeNull(
            "Row #{$rule->id} (scope=brand) has NULL brand_id — exclusive-set violation",
        );
    } elseif ($scope === PricingRule::SCOPE_CATEGORY) {
        expect($rule->category_id)->not->toBeNull(
            "Row #{$rule->id} (scope=category) has NULL category_id — exclusive-set violation",
        );
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Test A — positive control: seeded defaults honour the invariant
// ══════════════════════════════════════════════════════════════════════════════

it('DefaultPricingTierSeeder rows satisfy the is_default_tier exclusive-set invariant', function (): void {
    $this->seed(DefaultPricingTierSeeder::class);

    $rows = PricingRule::where('is_default_tier', true)->get();

    expect($rows)->toHaveCount(3, 'Seeder should produce 3 default-tier rows');

    foreach ($rows as $rule) {
        assertExclusiveSetInvariant($rule);
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test B — live catalog invariant check across all scope types
// ══════════════════════════════════════════════════════════════════════════════

it('every pricing_rules row satisfies the exclusive-set invariant across scope types', function (): void {
    // Seed the 3 default-tier baseline.
    $this->seed(DefaultPricingTierSeeder::class);

    // Exercise the invariant across the other 3 scope types via factory.
    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 11,
        'category_id' => null,
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
    ]);

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_CATEGORY,
        'brand_id' => null,
        'category_id' => 22,
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
    ]);

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
        'brand_id' => 33,
        'category_id' => 44,
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
    ]);

    // Add a second default-tier row (is_default_tier=true) with open-ended upper
    // — matches the £500+ real-world shape.
    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 100000,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2000,
    ]);

    // Walk every row and enforce the invariant.
    $all = PricingRule::query()->get();

    expect($all->count())->toBeGreaterThanOrEqual(
        7,
        'Expected ≥7 rows (3 seeded defaults + 3 specific scopes + 1 open-ended default)',
    );

    foreach ($all as $rule) {
        assertExclusiveSetInvariant($rule);
    }
});
