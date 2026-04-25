<?php

declare(strict_types=1);

namespace Database\Factories\Domain\TradePricing;

use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerGroup>
 */
class CustomerGroupFactory extends Factory
{
    protected $model = CustomerGroup::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->company(),
            'is_active' => true,
            'display_order' => 100,
        ];
    }
}
