<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Dashboard;

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardSnapshot>
 */
class DashboardSnapshotFactory extends Factory
{
    protected $model = DashboardSnapshot::class;

    public function definition(): array
    {
        return [
            'metric_key' => 'metric_'.fake()->unique()->slug(2),
            'metric_value_json' => [
                'count' => fake()->numberBetween(0, 100),
                'label' => fake()->words(2, true),
            ],
            'computed_at' => now(),
        ];
    }

    /** Stale snapshot — older than the default TTL (15 min). */
    public function stale(): static
    {
        return $this->state(fn () => [
            'computed_at' => now()->subMinutes(30),
        ]);
    }

    /** Fresh snapshot — just computed. */
    public function fresh(): static
    {
        return $this->state(fn () => [
            'computed_at' => now(),
        ]);
    }
}
