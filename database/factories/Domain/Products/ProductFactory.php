<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Products;

use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'woo_product_id' => fake()->unique()->numberBetween(1_000, 9_999_999),
            'sku' => fake()->unique()->bothify('SIMPLE-####'),
            'name' => fake()->words(3, true),
            'type' => 'simple',
            'status' => 'publish',
            'stock_status' => 'instock',
            'buy_price' => fake()->randomFloat(2, 10, 500),
            'sell_price' => fake()->randomFloat(2, 20, 700),
            'cost_price' => null,
            'is_custom_ms' => false,
            'exclude_from_auto_update' => false,
            'tags' => [],
            'last_synced_at' => null,
        ];
    }

    public function variable(): static
    {
        // Per D-01: variable parents have empty SKU at the parent level
        return $this->state(fn () => [
            'type' => 'variable',
            'sku' => null,
        ]);
    }

    public function customMs(): static
    {
        return $this->state(fn () => ['is_custom_ms' => true]);
    }

    public function excluded(): static
    {
        return $this->state(fn () => ['exclude_from_auto_update' => true]);
    }
}
