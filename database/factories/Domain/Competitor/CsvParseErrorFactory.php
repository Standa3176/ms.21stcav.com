<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\CsvParseError;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CsvParseError>
 */
class CsvParseErrorFactory extends Factory
{
    protected $model = CsvParseError::class;

    public function definition(): array
    {
        return [
            'ingest_run_id' => null,
            'competitor_id' => null,
            'filename' => fake()->slug(2).'_'.now()->format('Y-m-d').'.csv',
            'issue_type' => CsvParseError::TYPE_UNPARSEABLE_PRICE,
            'line_number' => fake()->numberBetween(1, 100),
            'raw_line' => fake()->sentence(),
            'context' => [],
            'resolved_at' => null,
        ];
    }

    public function ambiguousMapping(): static
    {
        return $this->state(fn () => ['issue_type' => CsvParseError::TYPE_AMBIGUOUS_MAPPING]);
    }

    public function orphanSku(): static
    {
        return $this->state(fn () => ['issue_type' => CsvParseError::TYPE_ORPHAN_SKU]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()]);
    }
}
