<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * CRM-02 — 24-hour TTL cache of Bitrix deal/contact/company field schemas.
 *
 * The Filament field-mapping UI (Plan 04-04) renders select dropdowns of
 * available Bitrix fields; push-time validation (Plan 04-03) checks that
 * mapped field names still exist in Bitrix. Hitting Bitrix on every read
 * would burn the ~2 req/sec ceiling, so we cache.
 *
 * Cache keys are predictable for test verification:
 *   bitrix:schema:deal
 *   bitrix:schema:contact
 *   bitrix:schema:company
 *
 * A schema-retrieval failure (BitrixPermanentException from BitrixClient)
 * propagates to the caller — we do NOT fall back to stale cache because that
 * would hide auth breakage from ops. The `bitrix:schema:refresh` command
 * re-fetches + writes a `bitrix.schema.refreshed` audit row on success.
 */
final class BitrixSchemaCache
{
    public const ENTITY_DEAL = 'deal';

    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_COMPANY = 'company';

    public const ENTITIES = [self::ENTITY_DEAL, self::ENTITY_CONTACT, self::ENTITY_COMPANY];

    public function __construct(
        private readonly BitrixClient $client,
        private readonly IntegrationLogger $logger,
    ) {
    }

    /**
     * Returns the full Bitrix field-description map for the entity, cached 24h
     * (or services.bitrix.cache_ttl_hours).
     *
     * @return array<string, mixed>  field_name => descriptor
     */
    public function fieldsFor(string $entityType): array
    {
        if (! in_array($entityType, self::ENTITIES, true)) {
            throw new InvalidArgumentException("BitrixSchemaCache: unknown entity_type '{$entityType}'");
        }

        $ttlSeconds = ((int) config('services.bitrix.cache_ttl_hours', 24)) * 3600;

        return Cache::remember($this->cacheKey($entityType), $ttlSeconds, function () use ($entityType): array {
            return (array) match ($entityType) {
                self::ENTITY_DEAL => $this->client->dealFieldsGet(),
                self::ENTITY_CONTACT => $this->client->contactFieldsGet(),
                self::ENTITY_COMPANY => $this->client->companyFieldsGet(),
            };
        });
    }

    /** Clears all three entity schema caches in one call. */
    public function invalidate(): void
    {
        foreach (self::ENTITIES as $entity) {
            Cache::forget($this->cacheKey($entity));
        }
    }

    /**
     * Push-time stale-mapping detector: true if the cached schema contains the
     * named Bitrix field, false otherwise. On live-fetch failure the cache is
     * invalidated and false returned so the caller can route the push into the
     * `crm_push_failed` suggestion lane.
     */
    public function validateMapping(string $entityType, string $bitrixField): bool
    {
        try {
            return array_key_exists($bitrixField, $this->fieldsFor($entityType));
        } catch (Throwable) {
            $this->invalidate();

            return false;
        }
    }

    private function cacheKey(string $entityType): string
    {
        return 'bitrix:schema:'.$entityType;
    }
}
