<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Dashboard;

use App\Domain\Dashboard\Models\UserSavedFilter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSavedFilter>
 */
class UserSavedFilterFactory extends Factory
{
    protected $model = UserSavedFilter::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resource_slug' => fake()->randomElement(['products', 'crm-push-logs', 'suggestions', 'competitor-prices']),
            'filter_name' => fake()->words(3, true),
            'filter_payload_json' => [
                'status' => 'pending',
                'brand' => fake()->randomElement(['LG', 'Samsung', 'Sony']),
                'created_after' => now()->subDays(7)->toDateString(),
            ],
        ];
    }

    public function forResource(string $slug): static
    {
        return $this->state(fn () => ['resource_slug' => $slug]);
    }
}
