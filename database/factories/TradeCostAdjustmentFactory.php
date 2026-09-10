<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TradePricing\Models\TradeCostAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TradeCostAdjustment> */
final class TradeCostAdjustmentFactory extends Factory
{
    protected $model = TradeCostAdjustment::class;

    public function definition(): array
    {
        return [
            'brand_id' => null,
            'sku' => null,
            'adjustment_pct' => '10.000',
            'valid_from' => null,
            'valid_until' => null,
            'reason' => 'test arrangement',
            'is_active' => true,
        ];
    }
}
