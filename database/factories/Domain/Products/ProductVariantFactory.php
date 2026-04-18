<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Products;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->variable(),
            'woo_variation_id' => fake()->unique()->numberBetween(10_000, 99_999_999),
            'sku' => fake()->unique()->bothify('VAR-####-??'),
            'name' => fake()->words(2, true),
            'buy_price' => fake()->randomFloat(2, 10, 500),
            'sell_price' => fake()->randomFloat(2, 20, 700),
            'stock_quantity' => fake()->numberBetween(0, 50),
            'status' => 'publish',
            'attributes' => [
                ['name' => 'Colour', 'option' => fake()->safeColorName()],
            ],
            'last_synced_at' => null,
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => ['status' => 'private']);
    }
}
