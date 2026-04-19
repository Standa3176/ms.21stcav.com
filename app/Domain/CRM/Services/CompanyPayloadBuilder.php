<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\CrmFieldMapping;
use App\Foundation\Integration\Services\IntegrationLogger;

/**
 * Phase 4 Plan 03 — builds the `crm.company.add` payload from a Woo billing block.
 *
 * Caller decides whether to create a Company at all (EntityDeduper skips the
 * company step when billing.company is empty). When called, this builder emits
 * TITLE + the 4 ADDRESS_* fields from billing, plus any admin-configured
 * CrmFieldMapping rows for entity_type='company'.
 *
 * Stale-mapping protection: every emitted key is validated against
 * BitrixSchemaCache::validateMapping(); mismatches write a
 * `crm.company.builder` integration_events row with step='stale_mapping_skipped'.
 */
final class CompanyPayloadBuilder
{
    public function __construct(
        private readonly BitrixSchemaCache $schema,
        private readonly IntegrationLogger $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $billing  Woo order billing block
     * @return array<string, mixed>
     */
    public function build(array $billing, ?string $correlationId = null): array
    {
        $base = [
            'TITLE' => (string) ($billing['company'] ?? ''),
            'ADDRESS' => (string) ($billing['address_1'] ?? ''),
            'ADDRESS_2' => (string) ($billing['address_2'] ?? ''),
            'ADDRESS_CITY' => (string) ($billing['city'] ?? ''),
            'ADDRESS_POSTAL_CODE' => (string) ($billing['postcode'] ?? ''),
            'ADDRESS_COUNTRY' => mb_strtoupper((string) ($billing['country'] ?? '')),
        ];

        $mappings = CrmFieldMapping::query()
            ->where('entity_type', CrmFieldMapping::ENTITY_COMPANY)
            ->get();

        foreach ($mappings as $mapping) {
            // The 5 base keys above are already authoritative — only apply
            // mappings that add new keys (e.g. UF_CRM_COMPANY_VAT).
            if (array_key_exists($mapping->bitrix_field, $base) && $base[$mapping->bitrix_field] !== '') {
                continue;
            }
            $value = PayloadTransformer::apply(
                data_get(['billing' => $billing], $this->wooPath($mapping->woo_field)),
                $mapping->transformer,
            );
            if ($value === null || $value === '') {
                continue;
            }
            $base[$mapping->bitrix_field] = $value;
        }

        return FieldWhitelister::filter(
            $base,
            $this->schema,
            $this->logger,
            BitrixSchemaCache::ENTITY_COMPANY,
            'crm.company.builder',
            $correlationId,
        );
    }

    /** Legacy seeder rows use `billing.vat` etc. — normalise to a data_get path. */
    private function wooPath(string $wooField): string
    {
        return $wooField;
    }
}
