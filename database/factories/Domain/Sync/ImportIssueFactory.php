<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Sync;

use App\Domain\Sync\Models\ImportIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportIssue>
 */
class ImportIssueFactory extends Factory
{
    protected $model = ImportIssue::class;

    public function definition(): array
    {
        return [
            'sku' => fake()->bothify('SKU-####'),
            'woo_product_id' => fake()->numberBetween(1_000, 999_999),
            'woo_variation_id' => null,
            'issue_type' => ImportIssue::TYPE_MISSING_AT_SUPPLIER,
            'detected_at' => now(),
            'last_seen_at' => now(),
            'resolved_at' => null,
            'notes' => null,
            'correlation_id' => fake()->uuid(),
        ];
    }

    public function unknownSku(): static
    {
        return $this->state(fn () => [
            'issue_type' => ImportIssue::TYPE_UNKNOWN_SKU,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'resolved_at' => now(),
        ]);
    }
}
