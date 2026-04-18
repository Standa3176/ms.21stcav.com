<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Sync;

use App\Domain\Sync\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    protected $model = SyncRun::class;

    public function definition(): array
    {
        return [
            'started_at' => now(),
            'completed_at' => null,
            'status' => SyncRun::STATUS_QUEUED,
            'dry_run' => true,
            'total_skus' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'missing_count' => 0,
            'unknown_sku_count' => 0,
            'consecutive_failures' => 0,
            'abort_reason' => null,
            'abort_message' => null,
            'cursor_page' => 0,
            'cursor_sku' => null,
            'correlation_id' => fake()->uuid(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => SyncRun::STATUS_RUNNING,
        ]);
    }

    public function aborted(): static
    {
        return $this->state(fn () => [
            'status' => SyncRun::STATUS_ABORTED,
            'abort_reason' => SyncRun::ABORT_ERROR_RATE,
            'abort_message' => 'simulated abort for test',
            'completed_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => SyncRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => SyncRun::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    public function live(): static
    {
        return $this->state(fn () => ['dry_run' => false]);
    }
}
