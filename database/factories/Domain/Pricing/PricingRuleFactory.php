<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Pricing;

use App\Domain\Pricing\Models\PricingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingRule>
 */
class PricingRuleFactory extends Factory
{
    protected $model = PricingRule::class;

    public function definition(): array
    {
        return [
            'scope' => PricingRule::SCOPE_BRAND,
            'brand_id' => fake()->numberBetween(1, 100),
            'category_id' => null,
            'margin_basis_points' => 2500,
            'priority' => 100,
            'is_default_tier' => false,
            'tier_min_pennies' => null,
            'tier_max_pennies' => null,
            'active' => true,
            'created_by_user_id' => null,
        ];
    }

    /**
     * Tier-fallback row state — <£100 / £100-499 / £500+ buckets.
     * Caller typically overrides tier_min_pennies / tier_max_pennies.
     */
    public function defaultTier(): static
    {
        return $this->state(fn () => [
            'scope' => PricingRule::SCOPE_DEFAULT_TIER,
            'brand_id' => null,
            'category_id' => null,
            'is_default_tier' => true,
            'tier_min_pennies' => 0,
            'tier_max_pennies' => 9999,
            'margin_basis_points' => 3500,
            'priority' => 50,
        ]);
    }

    /** Specific brand+category scope (highest specificity — beats brand or category alone). */
    public function brandCategory(): static
    {
        return $this->state(fn () => [
            'scope' => PricingRule::SCOPE_BRAND_CATEGORY,
            'brand_id' => fake()->numberBetween(1, 100),
            'category_id' => fake()->numberBetween(1, 50),
        ]);
    }

    /** Soft-toggled off (D-07 — preserves history without DELETE). */
    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
