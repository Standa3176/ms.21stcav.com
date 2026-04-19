<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Foundation\Integration\Services\IntegrationLogger;

/**
 * Phase 4 Plan 03 — builds the `crm.deal.add` payload from a Woo order JSON.
 *
 * Key decisions:
 *   - TITLE comes from `CrmPipelineSetting::current()->deal_title_template` with
 *     {order_number}, {order_date}, {billing_first_name}, {billing_last_name},
 *     {billing_company}, {total} shortcodes (legacy parity).
 *   - CATEGORY_ID + STAGE_ID come from CrmPipelineSetting (singleton); if a
 *     CrmStatusMapping exists for the order's status, its bitrix_stage_id
 *     WINS over the landing_stage_id (so an adopted order in processing
 *     status drops into PREPAYMENT_INVOICE instead of NEW).
 *   - OPPORTUNITY = (float) order.total; CURRENCY_ID = order.currency.
 *   - UF_CRM_WOO_ORDER_ID (int) order.id — the Pitfall 6 dedup field.
 *   - 6 UTM fields merged from UtmExtractor (D-03).
 *   - CONTACT_ID / COMPANY_ID injected by caller (EntityDeduper resolves them
 *     first — Company → Contact → Deal sequence).
 *   - Mapped fields from CrmFieldMapping[entity_type='deal'] applied last.
 *   - Every emitted key passes through FieldWhitelister::filter() — stale
 *     UF_CRM_* mappings are skipped with an integration_events audit row.
 */
final class DealPayloadBuilder
{
    public function __construct(
        private readonly UtmExtractor $utm,
        private readonly BitrixSchemaCache $schema,
        private readonly IntegrationLogger $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $order      Woo order JSON (decoded raw_body)
     * @param  string                $contactId  Bitrix Contact ID (from EntityDeduper)
     * @param  string                $companyId  Bitrix Company ID ('' if billing.company empty)
     * @return array<string, mixed>
     */
    public function build(array $order, string $contactId, string $companyId, ?string $correlationId = null): array
    {
        $pipeline = CrmPipelineSetting::current();

        $stageId = $this->resolveStageId($order, $pipeline);
        $categoryId = (string) ($pipeline->bitrix_pipeline_id ?? '');

        $base = [
            'TITLE' => $this->resolveTitle((string) $pipeline->deal_title_template, $order),
            'OPPORTUNITY' => (float) ($order['total'] ?? 0),
            'CURRENCY_ID' => mb_strtoupper((string) ($order['currency'] ?? 'GBP')),
            'UF_CRM_WOO_ORDER_ID' => (int) ($order['id'] ?? 0),
            'CONTACT_ID' => $contactId,
        ];

        if ($categoryId !== '') {
            $base['CATEGORY_ID'] = $categoryId;
        }
        if ($stageId !== '') {
            $base['STAGE_ID'] = $stageId;
        }
        if ($companyId !== '') {
            $base['COMPANY_ID'] = $companyId;
        }
        if (($assigned = (string) ($pipeline->assigned_user_id ?? '')) !== '') {
            $base['ASSIGNED_BY_ID'] = $assigned;
        }

        if (($customerNote = (string) ($order['customer_note'] ?? '')) !== '') {
            $base['COMMENTS'] = $customerNote;
        }

        // D-03: merge 6 UTM fields (Deal-level).
        $base = array_merge($base, $this->utm->fromOrderPayload($order));

        // Admin-configured CrmFieldMapping overrides + custom UF_CRM_* fields.
        $this->applyMappings($base, $order);

        return FieldWhitelister::filter(
            $base,
            $this->schema,
            $this->logger,
            BitrixSchemaCache::ENTITY_DEAL,
            'crm.deal.builder',
            $correlationId,
        );
    }

    /** CrmStatusMapping.bitrix_stage_id wins over pipeline landing_stage_id when present. */
    private function resolveStageId(array $order, CrmPipelineSetting $pipeline): string
    {
        $status = (string) ($order['status'] ?? '');
        if ($status !== '' && ($mapped = CrmStatusMapping::stageIdForStatus($status)) !== null && $mapped !== '') {
            return $mapped;
        }

        return (string) ($pipeline->landing_stage_id ?? '');
    }

    /**
     * Supported placeholders: {order_number}, {order_date}, {billing_first_name},
     * {billing_last_name}, {billing_company}, {total}.
     */
    private function resolveTitle(string $template, array $order): string
    {
        if ($template === '') {
            $template = 'Woo Order #{order_number}';
        }

        $vars = [
            '{order_number}' => (string) ($order['number'] ?? $order['id'] ?? ''),
            '{order_date}' => (string) ($order['date_created'] ?? ''),
            '{billing_first_name}' => (string) data_get($order, 'billing.first_name', ''),
            '{billing_last_name}' => (string) data_get($order, 'billing.last_name', ''),
            '{billing_company}' => (string) data_get($order, 'billing.company', ''),
            '{total}' => (string) ($order['total'] ?? ''),
        ];

        return strtr($template, $vars);
    }

    /** @param  array<string, mixed>  $base  modified in place */
    private function applyMappings(array &$base, array $order): void
    {
        $mappings = CrmFieldMapping::query()
            ->where('entity_type', CrmFieldMapping::ENTITY_DEAL)
            ->get();

        foreach ($mappings as $mapping) {
            $wooField = $mapping->woo_field;
            $bitrixField = $mapping->bitrix_field;

            // Keys already populated by the deterministic base (TITLE, OPPORTUNITY,
            // CURRENCY_ID, UF_CRM_WOO_ORDER_ID, CONTACT_ID, CATEGORY_ID, STAGE_ID,
            // COMPANY_ID, ASSIGNED_BY_ID, COMMENTS) stay as-is — mappings only
            // overlay when the target is a custom or unpopulated field.
            if (array_key_exists($bitrixField, $base) && ! in_array($bitrixField, [], true)) {
                // Allow overlaying UF_CRM_* fields (UTM etc.) if they're empty.
                if (str_starts_with($bitrixField, 'UF_CRM_') && (string) $base[$bitrixField] === '') {
                    // fall through — let the mapping supply a value
                } else {
                    continue;
                }
            }

            // `line_items` is a special top-level key; other mappings use dot paths like billing.phone.
            $sourceValue = $wooField === 'line_items'
                ? ($order['line_items'] ?? [])
                : data_get($order, $wooField);

            $value = PayloadTransformer::apply($sourceValue, $mapping->transformer);

            if ($value === null || $value === '') {
                continue;
            }

            $base[$bitrixField] = $value;
        }
    }
}
