<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Pricing;

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductOverride>
 */
class ProductOverrideFactory extends Factory
{
    protected $model = ProductOverride::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'margin_basis_points' => 4000,
            'reason' => 'Manual tune (test)',
            'created_by_user_id' => null,
        ];
    }
}
