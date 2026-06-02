<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Products;

use App\Domain\Products\Models\ProductException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductException>
 */
class ProductExceptionFactory extends Factory
{
    protected $model = ProductException::class;

    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('EXC-####-??'),
            'reason' => fake()->randomElement([
                'In-house assembly',
                'Direct vendor (non-integrated)',
                'Strategic loss-leader',
                'Refurbished stock',
            ]),
            'is_paused' => false,
            'notes' => null,
            'created_by_user_id' => null,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn () => ['is_paused' => true]);
    }
}
