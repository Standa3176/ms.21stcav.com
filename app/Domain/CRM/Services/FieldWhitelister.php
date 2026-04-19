<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Foundation\Integration\Services\IntegrationLogger;

/**
 * Phase 4 Plan 03 — push-time stale-mapping detector shared across all 3
 * payload builders.
 *
 * Every mapped bitrix_field is validated against BitrixSchemaCache::validateMapping()
 * before emission. Keys that fail (either missing from the cached schema or
 * caught by an auth-break fallthrough) are dropped AND logged as
 * `step='stale_mapping_skipped'` on an integration_events row under the
 * caller's declared endpoint (crm.deal.builder / crm.contact.builder / crm.company.builder).
 *
 * Plan 04-04 will expose the resulting audit trail as a Filament warning so
 * admins can fix the mapping before the Deal pushes with silently-dropped fields.
 */
final class FieldWhitelister
{
    /**
     * @param  array<string, mixed>  $payload  field_name => value (pre-validation)
     * @return array<string, mixed>            field_name => value (only schema-valid keys)
     */
    public static function filter(
        array $payload,
        BitrixSchemaCache $schema,
        IntegrationLogger $logger,
        string $entityType,
        string $endpoint,
        ?string $correlationId,
    ): array {
        $allowed = [];
        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }
            // NAME / LAST_NAME / EMAIL / PHONE / TITLE / etc. are always-safe standard fields.
            // Standard fields are uppercase-alpha prefixed (e.g. UF_CRM_*, NAME, TITLE).
            // We only apply the schema check when the cache is warm; otherwise we let
            // the SDK bounce bad keys with a 400 (caller gets a permanent exception).
            if (str_starts_with($key, 'UF_CRM_') && ! $schema->validateMapping($entityType, $key)) {
                $logger->log([
                    'channel' => 'bitrix',
                    'direction' => 'internal',
                    'method' => 'BUILD',
                    'operation' => $endpoint,
                    'endpoint' => $endpoint,
                    'request_body' => ['key' => $key],
                    'response_body' => ['step' => 'stale_mapping_skipped', 'key' => $key, 'entity_type' => $entityType],
                    'http_status' => 200,
                    'correlation_id' => $correlationId,
                    'status' => 'failed',
                    'error_message' => "stale_mapping_skipped:{$key}",
                ]);
                continue;
            }
            $allowed[$key] = $value;
        }

        return $allowed;
    }
}
