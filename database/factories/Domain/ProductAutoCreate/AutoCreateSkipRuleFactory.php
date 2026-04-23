<?php

declare(strict_types=1);

namespace Database\Factories\Domain\ProductAutoCreate;

use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoCreateSkipRule>
 */
class AutoCreateSkipRuleFactory extends Factory
{
    protected $model = AutoCreateSkipRule::class;

    private const REASONS = [
        'not_a_real_product',
        'duplicate_of_existing',
        'discontinued_by_supplier',
        'spare_part_or_accessory',
        'poor_quality_data',
        'misclassified_brand_or_category',
        'below_viability_threshold',
        'other',
    ];

    public function definition(): array
    {
        return [
            'scope' => AutoCreateSkipRule::SCOPE_BRAND,
            'value' => fake()->company(),
            'reason' => self::REASONS[array_rand(self::REASONS)],
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }

    public function brand(string $name): static
    {
        return $this->state(fn () => [
            'scope' => AutoCreateSkipRule::SCOPE_BRAND,
            'value' => $name,
        ]);
    }

    public function skuPattern(string $pattern): static
    {
        return $this->state(fn () => [
            'scope' => AutoCreateSkipRule::SCOPE_SKU_PATTERN,
            'value' => $pattern,
        ]);
    }

    public function priceRange(string $range): static
    {
        return $this->state(fn () => [
            'scope' => AutoCreateSkipRule::SCOPE_PRICE_RANGE,
            'value' => $range,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
