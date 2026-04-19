<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CRM;

use App\Domain\CRM\Models\BitrixEntityMap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BitrixEntityMap>
 */
class BitrixEntityMapFactory extends Factory
{
    protected $model = BitrixEntityMap::class;

    public function definition(): array
    {
        return [
            'entity_type' => BitrixEntityMap::ENTITY_DEAL,
            'woo_id' => fake()->unique()->numberBetween(1, 999_999_999),
            'bitrix_id' => (string) fake()->numberBetween(1, 999999),
            'email_hash' => null,
            'last_payload_hash' => null,
            'last_status_snapshot' => null,
            'last_pushed_at' => now(),
            'last_correlation_id' => fake()->uuid(),
            'created_via' => BitrixEntityMap::VIA_PUSH,
        ];
    }

    /** Deal row keyed to a specific Woo order ID (common test fixture). */
    public function dealFor(int $wooOrderId, string $bitrixId): static
    {
        return $this->state(fn () => [
            'entity_type' => BitrixEntityMap::ENTITY_DEAL,
            'woo_id' => $wooOrderId,
            'bitrix_id' => $bitrixId,
            'last_status_snapshot' => 'processing',
        ]);
    }

    /** Contact row with deterministic email_hash for GDPR-lookup tests. */
    public function contactFor(int $wooCustomerId, string $bitrixId, string $email): static
    {
        return $this->state(fn () => [
            'entity_type' => BitrixEntityMap::ENTITY_CONTACT,
            'woo_id' => $wooCustomerId,
            'bitrix_id' => $bitrixId,
            'email_hash' => hash('sha256', mb_strtolower($email)),
        ]);
    }

    /** Company row (woo_id=0 sentinel; payload-hash carries title+postcode signature). */
    public function companyFor(string $bitrixId, string $title, string $postcode): static
    {
        return $this->state(fn () => [
            'entity_type' => BitrixEntityMap::ENTITY_COMPANY,
            'woo_id' => 0,
            'bitrix_id' => $bitrixId,
            'last_payload_hash' => hash('sha256', json_encode([
                'title' => $title,
                'postcode' => $postcode,
            ])),
        ]);
    }

    public function viaBackfill(): static
    {
        return $this->state(fn () => ['created_via' => BitrixEntityMap::VIA_BACKFILL]);
    }

    public function viaAdoptedLegacy(): static
    {
        return $this->state(fn () => ['created_via' => BitrixEntityMap::VIA_ADOPTED_LEGACY]);
    }
}
