<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Competitor;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorCsvMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitorCsvMapping>
 */
class CompetitorCsvMappingFactory extends Factory
{
    protected $model = CompetitorCsvMapping::class;

    public function definition(): array
    {
        return [
            'competitor_id' => Competitor::factory(),
            'sku_column_index' => 0,
            'price_column_index' => 1,
            'decimal_format' => CompetitorCsvMapping::FORMAT_DOT,
            'detected_at' => now(),
        ];
    }

    public function commaDecimal(): static
    {
        return $this->state(fn () => ['decimal_format' => CompetitorCsvMapping::FORMAT_COMMA]);
    }
}
