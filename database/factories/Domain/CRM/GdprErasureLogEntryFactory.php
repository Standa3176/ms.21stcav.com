<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CRM;

use App\Domain\CRM\Models\GdprErasureLogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GdprErasureLogEntry>
 */
class GdprErasureLogEntryFactory extends Factory
{
    protected $model = GdprErasureLogEntry::class;

    public function definition(): array
    {
        return [
            'email_hash' => hash('sha256', fake()->unique()->safeEmail()),
            'contact_bitrix_id' => (string) fake()->numberBetween(1, 9999),
            'deal_bitrix_ids' => [(string) fake()->numberBetween(1, 9999)],
            'actor_id' => null,
            'correlation_id' => fake()->uuid(),
            'fields_scrubbed_count' => 22,
            'status' => GdprErasureLogEntry::STATUS_APPLIED,
            'notes' => null,
            'erased_at' => now(),
        ];
    }

    public function noMatch(): static
    {
        return $this->state(fn () => [
            'contact_bitrix_id' => null,
            'deal_bitrix_ids' => null,
            'fields_scrubbed_count' => 0,
            'status' => GdprErasureLogEntry::STATUS_NO_MATCH,
        ]);
    }
}
