<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CRM;

use App\Domain\CRM\Models\CrmFieldMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmFieldMapping>
 */
class CrmFieldMappingFactory extends Factory
{
    protected $model = CrmFieldMapping::class;

    public function definition(): array
    {
        return [
            'entity_type' => CrmFieldMapping::ENTITY_DEAL,
            'woo_field' => 'billing.first_name',
            'bitrix_field' => 'NAME',
            'is_custom' => false,
            'transformer' => null,
            'sort_order' => 0,
        ];
    }

    public function forContact(): static
    {
        return $this->state(fn () => ['entity_type' => CrmFieldMapping::ENTITY_CONTACT]);
    }

    public function forCompany(): static
    {
        return $this->state(fn () => ['entity_type' => CrmFieldMapping::ENTITY_COMPANY]);
    }

    public function customField(string $bitrixField): static
    {
        return $this->state(fn () => [
            'is_custom' => true,
            'bitrix_field' => $bitrixField,
        ]);
    }
}
