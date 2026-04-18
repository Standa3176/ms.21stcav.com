<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Sync;

use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncError>
 */
class SyncErrorFactory extends Factory
{
    protected $model = SyncError::class;

    public function definition(): array
    {
        return [
            'sync_run_id' => SyncRun::factory(),
            'sku' => fake()->bothify('SKU-####'),
            'woo_product_id' => fake()->numberBetween(1_000, 999_999),
            'woo_variation_id' => null,
            'error_class' => 'Illuminate\Http\Client\RequestException',
            'error_message' => fake()->sentence(),
            'correlation_id' => fake()->uuid(),
            'created_at' => now(),
        ];
    }
}
