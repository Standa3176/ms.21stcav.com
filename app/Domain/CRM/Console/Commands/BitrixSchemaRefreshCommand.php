<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Services\BitrixSchemaCache;
use App\Foundation\Audit\Services\Auditor;
use Throwable;

/**
 * CRM-02 — invalidate + refetch the 24h Bitrix field-schema cache.
 *
 * Runs manually after a Bitrix admin adds/removes/renames a UF_CRM_* field
 * so the Filament field-mapping UI + push-time validation pick up the change
 * before the 24h TTL expires. Writes a `bitrix.schema.refreshed` audit row on
 * success; exits non-zero on any fetch failure (no audit row in that case —
 * partial success is still a failure from ops' perspective).
 */
final class BitrixSchemaRefreshCommand extends BaseCommand
{
    protected $signature = 'bitrix:schema:refresh';

    protected $description = 'Invalidate the 24h Bitrix field-schema cache and refetch deal/contact/company schemas. Run after any Bitrix admin edits a UF_CRM_* field.';

    public function __construct(
        private readonly BitrixSchemaCache $cache,
        private readonly Auditor $auditor,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $this->cache->invalidate();
        $this->info('BitrixSchemaRefresh: cache invalidated');

        $counts = [];
        foreach (BitrixSchemaCache::ENTITIES as $entity) {
            try {
                $fields = $this->cache->fieldsFor($entity);
                $counts[$entity] = count($fields);
                $this->info(sprintf('BitrixSchemaRefresh: %s -> %d fields', $entity, $counts[$entity]));
            } catch (Throwable $e) {
                $this->error(sprintf('BitrixSchemaRefresh: failed to fetch %s schema: %s', $entity, $e->getMessage()));

                return self::FAILURE;
            }
        }

        $this->auditor->record('bitrix.schema.refreshed', [
            'entities' => BitrixSchemaCache::ENTITIES,
            'counts' => $counts,
        ]);

        return self::SUCCESS;
    }
}
