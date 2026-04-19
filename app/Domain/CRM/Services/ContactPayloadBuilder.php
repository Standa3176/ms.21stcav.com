<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\CrmFieldMapping;
use App\Foundation\Integration\Services\IntegrationLogger;

/**
 * Phase 4 Plan 03 — builds the `crm.contact.add` payload from a Woo order OR
 * customer JSON.
 *
 * Handles both shapes because the same builder is shared by PushOrderToBitrixJob
 * (input = order with billing block) and PushCustomerToBitrixJob (input =
 * customer). The `normalise()` helper picks the right source:
 *
 *   - order payload  → billing.* block for name/email/address/phone
 *   - customer payload → first_name/last_name/email at root + billing.*
 *
 * Multi-value COMM fields (EMAIL, PHONE) are always emitted in the Bitrix
 * `[['VALUE' => ..., 'VALUE_TYPE' => 'WORK']]` shape per legacy parity.
 *
 * D-04: UTM fields merged at the Contact level (not just Deal) so sales can
 * see attribution on registered-but-not-yet-purchased leads.
 */
final class ContactPayloadBuilder
{
    public function __construct(
        private readonly UtmExtractor $utm,
        private readonly BitrixSchemaCache $schema,
        private readonly IntegrationLogger $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input  Either an order JSON or a customer JSON
     * @return array<string, mixed>
     */
    public function build(array $input, ?string $correlationId = null): array
    {
        $normalised = $this->normalise($input);

        $phone = PayloadTransformer::normalisePhone((string) ($normalised['phone'] ?? ''));

        $base = [
            'NAME' => (string) ($normalised['first_name'] ?? ''),
            'LAST_NAME' => (string) ($normalised['last_name'] ?? ''),
            'ADDRESS' => (string) ($normalised['address_1'] ?? ''),
            'ADDRESS_2' => (string) ($normalised['address_2'] ?? ''),
            'ADDRESS_CITY' => (string) ($normalised['city'] ?? ''),
            'ADDRESS_POSTAL_CODE' => (string) ($normalised['postcode'] ?? ''),
            'ADDRESS_COUNTRY' => mb_strtoupper((string) ($normalised['country'] ?? '')),
            'UF_CRM_WOO_CUSTOMER_ID' => (string) ($normalised['customer_id'] ?? ''),
        ];

        if (($email = (string) ($normalised['email'] ?? '')) !== '') {
            $base['EMAIL'] = [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
        }
        if ($phone !== null && $phone !== '') {
            $base['PHONE'] = [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
        }

        // D-04: merge UTM fields from whichever payload we were given.
        $base = array_merge($base, $this->utm->fromOrderPayload($input));

        // Apply admin-configured CrmFieldMapping overrides (entity_type='contact').
        $this->applyMappings($base, $input);

        return FieldWhitelister::filter(
            $base,
            $this->schema,
            $this->logger,
            BitrixSchemaCache::ENTITY_CONTACT,
            'crm.contact.builder',
            $correlationId,
        );
    }

    /**
     * Reduce order-or-customer to a single flat shape the builder reads from.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function normalise(array $input): array
    {
        // Order shape: top-level `billing` block + customer_id/email may live at root.
        if (isset($input['billing']) && is_array($input['billing'])) {
            $b = $input['billing'];

            return [
                'first_name' => (string) ($b['first_name'] ?? ''),
                'last_name' => (string) ($b['last_name'] ?? ''),
                'email' => (string) ($b['email'] ?? $input['email'] ?? $input['billing_email'] ?? ''),
                'phone' => (string) ($b['phone'] ?? ''),
                'address_1' => (string) ($b['address_1'] ?? ''),
                'address_2' => (string) ($b['address_2'] ?? ''),
                'city' => (string) ($b['city'] ?? ''),
                'postcode' => (string) ($b['postcode'] ?? ''),
                'country' => (string) ($b['country'] ?? ''),
                'customer_id' => (string) ($input['customer_id'] ?? $input['id'] ?? ''),
            ];
        }

        // Customer shape: first_name/last_name/email at root, optional billing block.
        $b = isset($input['billing']) && is_array($input['billing']) ? $input['billing'] : [];

        return [
            'first_name' => (string) ($input['first_name'] ?? $b['first_name'] ?? ''),
            'last_name' => (string) ($input['last_name'] ?? $b['last_name'] ?? ''),
            'email' => (string) ($input['email'] ?? $b['email'] ?? ''),
            'phone' => (string) ($input['billing_phone'] ?? $b['phone'] ?? ''),
            'address_1' => (string) ($b['address_1'] ?? ''),
            'address_2' => (string) ($b['address_2'] ?? ''),
            'city' => (string) ($b['city'] ?? ''),
            'postcode' => (string) ($b['postcode'] ?? ''),
            'country' => (string) ($b['country'] ?? ''),
            'customer_id' => (string) ($input['id'] ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $base  modified in place */
    private function applyMappings(array &$base, array $input): void
    {
        $mappings = CrmFieldMapping::query()
            ->where('entity_type', CrmFieldMapping::ENTITY_CONTACT)
            ->get();

        foreach ($mappings as $mapping) {
            $wooField = $mapping->woo_field;
            $bitrixField = $mapping->bitrix_field;

            // Standard keys already populated by base — only overlay when mapping
            // points at a different (usually custom UF_CRM_*) destination.
            if (in_array($bitrixField, ['NAME', 'LAST_NAME', 'EMAIL', 'PHONE', 'ADDRESS', 'ADDRESS_2', 'ADDRESS_CITY', 'ADDRESS_POSTAL_CODE', 'ADDRESS_COUNTRY'], true)) {
                continue;
            }

            $value = PayloadTransformer::apply(
                data_get($input, $wooField),
                $mapping->transformer,
            );

            if ($value === null || $value === '') {
                continue;
            }

            $base[$bitrixField] = $value;
        }
    }
}
