<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CRM;

use App\Domain\CRM\Models\CrmStatusMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmStatusMapping>
 */
class CrmStatusMappingFactory extends Factory
{
    protected $model = CrmStatusMapping::class;

    public function definition(): array
    {
        return [
            'woo_status' => fake()->unique()->randomElement([
                'pending', 'processing', 'on-hold', 'completed',
                'cancelled', 'refunded', 'failed',
            ]),
            'bitrix_stage_id' => null,
            'bitrix_stage_label' => 'NEW',
            'is_terminal' => false,
        ];
    }

    public function terminal(): static
    {
        return $this->state(fn () => [
            'is_terminal' => true,
            'bitrix_stage_label' => 'LOSE',
        ]);
    }
}
