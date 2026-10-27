<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Support\BitrixFieldGuards;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

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
 *   - Phase 12.1 Plan 02: CrmPipelineSetting.deal_static_fields (plugin constants
 *     such as TYPE_ID / OPENED / SOURCE_ID, with {placeholder} support) are merged
 *     after the deterministic base and never override it. CrmFieldMapping.woo_field
 *     may be `meta:<key>` to read the order's meta_data (utm_*, wciwtb_streaming).
 *     UF_CRM_1610623973323 (Actual Margin) and the configured Cost / Predicted
 *     Margin codes are NEVER emitted from static/mapping sources. UtmExtractor's
 *     UF_CRM_WOO_UTM_* keys are still emitted (dropped by FieldWhitelister when
 *     absent on the portal); plugin parity uses the standard UTM_* fields via
 *     meta: mappings.
 *   - Every emitted key passes through FieldWhitelister::filter() — stale
 *     UF_CRM_* mappings are skipped with an integration_events audit row.
 */
final class DealPayloadBuilder
{
    /** Keys owned by the deterministic base — static fields never override these. */
    private const DETERMINISTIC_KEYS = [
        'TITLE', 'OPPORTUNITY', 'CURRENCY_ID', 'UF_CRM_WOO_ORDER_ID', 'CONTACT_ID',
        'COMPANY_ID', 'CATEGORY_ID', 'STAGE_ID', 'ASSIGNED_BY_ID',
    ];

    public function __construct(
        private readonly UtmExtractor $utm,
        private readonly BitrixSchemaCache $schema,
        private readonly IntegrationLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $order  Woo order JSON (decoded raw_body)
     * @param  string  $contactId  Bitrix Contact ID (from EntityDeduper)
     * @param  string  $companyId  Bitrix Company ID ('' if billing.company empty)
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

        // Phase 12.1 D-08: plugin constants from CrmPipelineSetting.deal_static_fields.
        $this->applyStaticFields($base, $pipeline, $order, $correlationId);

        // D-03: merge 6 UTM fields (Deal-level).
        $base = array_merge($base, $this->utm->fromOrderPayload($order));

        // Admin-configured CrmFieldMapping overrides + custom UF_CRM_* fields.
        $this->applyMappings($base, $order, $pipeline, $correlationId);

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

        return $this->resolvePlaceholders($template, $order);
    }

    /** Placeholder substitution shared by TITLE and static string fields. */
    private function resolvePlaceholders(string $template, array $order): string
    {
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

    /**
     * Phase 12.1 D-08 — merge plugin constants. Never overrides deterministic base keys.
     *
     * @param  array<string, mixed>  $base  modified in place
     */
    private function applyStaticFields(array &$base, CrmPipelineSetting $pipeline, array $order, ?string $correlationId): void
    {
        $static = $pipeline->deal_static_fields;
        if (! is_array($static)) {
            return;
        }

        foreach ($static as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::DETERMINISTIC_KEYS, true) || array_key_exists($key, $base)) {
                continue;
            }
            if ($this->isBlockedTarget($key, $pipeline)) {
                $this->logBlocked($key, 'static', $correlationId);

                continue;
            }
            $base[$key] = is_string($value) ? $this->resolvePlaceholders($value, $order) : $value;
        }
    }

    /** Actual Margin (never write) + the configured Cost / Predicted Margin codes. */
    private function isBlockedTarget(string $code, CrmPipelineSetting $pipeline): bool
    {
        if (BitrixFieldGuards::isForbiddenTarget($code)) {
            return true;
        }
        foreach ([$pipeline->deal_cost_field, $pipeline->deal_predicted_margin_field] as $guarded) {
            if (is_string($guarded) && $guarded !== '' && strcasecmp($guarded, $code) === 0) {
                return true;
            }
        }

        return false;
    }

    private function logBlocked(string $code, string $source, ?string $correlationId): void
    {
        $this->logger->log([
            'channel' => 'bitrix',
            'direction' => 'internal',
            'method' => 'BUILD',
            'operation' => 'crm.deal.forbidden_field_skipped',
            'endpoint' => 'crm.deal.builder',
            'request_body' => ['field' => $code, 'source' => $source],
            'http_status' => 200,
            'correlation_id' => $correlationId ?? (Context::get('correlation_id') ?: (string) Str::uuid()),
            'status' => 'success',
        ]);
    }

    /** @param  array<string, mixed>  $base  modified in place */
    private function applyMappings(array &$base, array $order, CrmPipelineSetting $pipeline, ?string $correlationId): void
    {
        $mappings = CrmFieldMapping::query()
            ->where('entity_type', CrmFieldMapping::ENTITY_DEAL)
            ->get();

        foreach ($mappings as $mapping) {
            $wooField = $mapping->woo_field;
            $bitrixField = $mapping->bitrix_field;

            if ($this->isBlockedTarget($bitrixField, $pipeline)) {
                $this->logBlocked($bitrixField, 'mapping', $correlationId);

                continue;
            }

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
            if ($wooField === 'line_items') {
                $sourceValue = $order['line_items'] ?? [];
            } elseif (str_starts_with($wooField, 'meta:')) {
                $sourceValue = $this->metaValue($order, substr($wooField, 5));
            } else {
                $sourceValue = data_get($order, $wooField);
            }

            $value = PayloadTransformer::apply($sourceValue, $mapping->transformer);

            if ($value === null || $value === '') {
                continue;
            }

            $base[$bitrixField] = $value;
        }
    }

    /** First order meta_data entry whose key equals $key (value cast to string); null when absent. */
    private function metaValue(array $order, string $key): ?string
    {
        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && (string) ($meta['key'] ?? '') === $key) {
                $value = $meta['value'] ?? null;

                return is_scalar($value) ? (string) $value : null;
            }
        }

        return null;
    }
}
