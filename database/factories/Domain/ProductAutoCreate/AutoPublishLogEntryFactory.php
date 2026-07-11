<?php

declare(strict_types=1);

namespace Database\Factories\Domain\ProductAutoCreate;

use App\Domain\ProductAutoCreate\Models\AutoPublishLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutoPublishLogEntry>
 */
class AutoPublishLogEntryFactory extends Factory
{
    protected $model = AutoPublishLogEntry::class;

    public function definition(): array
    {
        return [
            'sku' => strtoupper($this->faker->bothify('SKU-####')),
            'product_id' => null,
            'woo_product_id' => $this->faker->numberBetween(1000, 999999),
            // Scheduled auto-publish only ever pushes 2- or 3-competitor SKUs.
            'competitor_count' => $this->faker->numberBetween(2, 3),
            'supplier_count' => null,
            'source' => AutoPublishLogEntry::SOURCE_SCHEDULED,
            'batch_correlation_id' => (string) Str::uuid(),
            'published_at' => now(),
        ];
    }

    public function competitors(int $count): static
    {
        return $this->state(fn () => ['competitor_count' => $count]);
    }
}
