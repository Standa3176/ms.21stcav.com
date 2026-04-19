<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\Competitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Competitor>
 */
class CompetitorFactory extends Factory
{
    protected $model = Competitor::class;

    public function definition(): array
    {
        // Lowercased slug fits the filename-prefix pattern `{slug}_{YYYY-MM-DD}.csv`.
        $slug = Str::lower(fake()->unique()->slug(2));

        return [
            'slug' => $slug,
            'name' => fake()->company(),
            'website_url' => 'https://'.$slug.'.example.com',
            'map_policy_notes' => null,
            'status' => Competitor::STATUS_ACTIVE,
            'is_active' => true,
            'last_ingest_at' => now()->subHours(fake()->numberBetween(1, 47)),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => Competitor::STATUS_PENDING,
            'last_ingest_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => Competitor::STATUS_INACTIVE,
            'is_active' => false,
        ]);
    }

    public function stale(): static
    {
        // Last ingest older than the 48h stale-feed threshold (COMP-11).
        return $this->state(fn () => [
            'last_ingest_at' => now()->subHours(72),
        ]);
    }
}
