<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\TradePricing\Models\TradeCostAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TradeCostAdjustment> */
final class TradeCostAdjustmentFactory extends Factory
{
    protected $model = TradeCostAdjustment::class;

    /** A manufacturer price-list row: a fixed ex-VAT cost, no percentage. */
    public function priceList(float $cost): self
    {
        return $this->state(fn (): array => ['adjustment_pct' => null, 'absolute_cost' => number_format($cost, 4, '.', '')]);
    }

    public function definition(): array
    {
        return [
            'brand_id' => null,
            'sku' => null,
            'adjustment_pct' => '10.000',
            'absolute_cost' => null,
            'valid_from' => null,
            'valid_until' => null,
            'reason' => 'test arrangement',
            'is_active' => true,
        ];
    }
}
