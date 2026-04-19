<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CRM;

use App\Domain\CRM\Models\BitrixBackfillRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BitrixBackfillRun>
 */
class BitrixBackfillRunFactory extends Factory
{
    protected $model = BitrixBackfillRun::class;

    public function definition(): array
    {
        return [
            'since_date' => now()->subMonth()->toDateString(),
            'mode' => BitrixBackfillRun::MODE_DRY_RUN,
            'started_at' => now(),
            'finished_at' => null,
            'total_orders' => 0,
            'processed_orders' => 0,
            'skipped_orders' => 0,
            'failed_orders' => 0,
            'adopted_legacy_count' => 0,
            'last_cursor' => null,
            'notes' => null,
            'correlation_id' => fake()->uuid(),
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => ['mode' => BitrixBackfillRun::MODE_LIVE]);
    }

    public function adoptLegacy(): static
    {
        return $this->state(fn () => ['mode' => BitrixBackfillRun::MODE_ADOPT_LEGACY]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'finished_at' => now(),
            'processed_orders' => 42,
            'total_orders' => 42,
        ]);
    }
}
