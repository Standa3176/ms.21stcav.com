<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompetitorIngestRun>
 */
class CompetitorIngestRunFactory extends Factory
{
    protected $model = CompetitorIngestRun::class;

    public function definition(): array
    {
        $slug = Str::lower(fake()->word());
        $date = now()->format('Y-m-d');

        return [
            'competitor_id' => Competitor::factory(),
            'filename' => sprintf('%s_%s.csv', $slug, $date),
            'rows_total' => 0,
            'rows_written' => 0,
            'rows_errored' => 0,
            'rows_orphaned' => 0,
            'status' => CompetitorIngestRun::STATUS_STARTED,
            'started_at' => now(),
            'completed_at' => null,
            // Plan 02-02 lesson: correlation_id column is VARCHAR(36); Str::uuid()
            // emits exactly 36 characters — do NOT prefix in tests.
            'correlation_id' => (string) Str::uuid(),
            'error_message' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => CompetitorIngestRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CompetitorIngestRun::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => 'simulated ingest failure',
        ]);
    }
}
