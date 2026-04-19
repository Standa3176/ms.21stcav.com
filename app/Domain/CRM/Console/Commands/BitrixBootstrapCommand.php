<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Services\BitrixClient;
use App\Foundation\Audit\Services\Auditor;
use Throwable;

/**
 * Phase 4 Plan 01 Task 3 — create the Bitrix custom fields Phase 4 needs.
 *
 * Runs on every deploy (not a one-shot migration per CONTEXT.md "Specific Ideas").
 * Idempotent: checks `crm.deal.userfield.list` + `crm.contact.userfield.list`
 * before creating anything so a second run reports "already exists, skipping".
 *
 * Field inventory — 14 total:
 *   Deal (7):    UF_CRM_WOO_ORDER_ID (integer) + 5 UTM (utm_source/medium/campaign/term/content) + UF_CRM_WOO_GA_CID
 *   Contact (7): UF_CRM_WOO_CUSTOMER_ID (integer) + 5 UTM + UF_CRM_WOO_GA_CID
 *
 * Fail-hard behaviour (Pitfall 6 mandate):
 *   - Empty BITRIX_WEBHOOK_URL → exit 1 with guidance. Never tries the SDK.
 *   - `userfield.list` throws → exit 1 immediately. Never creates without
 *     the existence check (prevents duplicate fields on auth-broken tenant).
 *
 * Audit: every run writes an `activity_log` row via Auditor::record so the
 * operator trail is permanent.
 */
final class BitrixBootstrapCommand extends BaseCommand
{
    protected $signature = 'bitrix:bootstrap {--dry-run : List what would be created without calling Bitrix}';

    protected $description = 'Create the Bitrix custom fields Phase 4 requires. Idempotent; safe on every deploy.';

    public function __construct(
        private readonly BitrixClient $client,
        private readonly Auditor $auditor,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        if (empty(config('services.bitrix.webhook_url'))) {
            $this->error('BitrixBootstrapCommand: BITRIX_WEBHOOK_URL is empty. Set it in .env before running this command.');

            return self::FAILURE;
        }

        $dealFields = $this->dealFieldInventory();
        $contactFields = $this->contactFieldInventory();

        $created = 0;
        $skipped = 0;
        $isDryRun = (bool) $this->option('dry-run');

        try {
            foreach ([['deal', $dealFields], ['contact', $contactFields]] as [$entity, $fields]) {
                $list = $entity === 'deal'
                    ? $this->client->dealUserfieldList([])
                    : $this->client->contactUserfieldList([]);

                $existing = [];
                foreach ($list as $row) {
                    if (isset($row['FIELD_NAME'])) {
                        $existing[$row['FIELD_NAME']] = true;
                    }
                }

                foreach ($fields as $f) {
                    if (isset($existing[$f['FIELD_NAME']])) {
                        $this->info("{$entity}: {$f['FIELD_NAME']} already exists, skipping.");
                        $skipped++;

                        continue;
                    }

                    if ($isDryRun) {
                        $this->line("{$entity}: would create {$f['FIELD_NAME']}");
                        $created++;

                        continue;
                    }

                    $payload = $this->buildUserfieldPayload($f);

                    if ($entity === 'deal') {
                        $this->client->dealUserfieldAdd($payload);
                    } else {
                        $this->client->contactUserfieldAdd($payload);
                    }

                    $this->info("{$entity}: created {$f['FIELD_NAME']}");
                    $created++;
                }
            }
        } catch (Throwable $e) {
            // Fail hard — Pitfall 6. Do NOT attempt creates when list failed.
            $this->error('BitrixBootstrapCommand: aborting — '.$e::class.': '.$e->getMessage());
            $this->auditor->record('bitrix.bootstrap.failed', [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
                'created' => $created,
                'skipped' => $skipped,
                'dry_run' => $isDryRun,
            ]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'BitrixBootstrap: created=%d skipped=%d dry-run=%s',
            $created,
            $skipped,
            $isDryRun ? 'yes' : 'no'
        ));

        $this->auditor->record('bitrix.bootstrap', [
            'created' => $created,
            'skipped' => $skipped,
            'dry_run' => $isDryRun,
        ]);

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string>> */
    private function dealFieldInventory(): array
    {
        return [
            ['FIELD_NAME' => 'UF_CRM_WOO_ORDER_ID',    'USER_TYPE_ID' => 'integer', 'XML_ID' => 'WOO_ORDER_ID',    'LABEL_EN' => 'WooCommerce Order ID', 'SHOW_IN_LIST' => 'Y', 'SHOW_FILTER' => 'I', 'IS_SEARCHABLE' => 'Y'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_SOURCE',  'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_SOURCE',  'LABEL_EN' => 'Woo UTM Source',       'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_MEDIUM',  'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_MEDIUM',  'LABEL_EN' => 'Woo UTM Medium',       'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CAMPAIGN','USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_CAMPAIGN','LABEL_EN' => 'Woo UTM Campaign',     'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_TERM',    'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_TERM',    'LABEL_EN' => 'Woo UTM Term',         'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CONTENT', 'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_CONTENT', 'LABEL_EN' => 'Woo UTM Content',      'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_GA_CID',      'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_GA_CID',      'LABEL_EN' => 'Woo GA Client ID',     'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
        ];
    }

    /** @return array<int, array<string, string>> */
    private function contactFieldInventory(): array
    {
        return [
            ['FIELD_NAME' => 'UF_CRM_WOO_CUSTOMER_ID', 'USER_TYPE_ID' => 'integer', 'XML_ID' => 'WOO_CUSTOMER_ID', 'LABEL_EN' => 'WooCommerce Customer ID','SHOW_IN_LIST' => 'Y', 'SHOW_FILTER' => 'I', 'IS_SEARCHABLE' => 'Y'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_SOURCE',  'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_SOURCE',  'LABEL_EN' => 'Woo UTM Source',       'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_MEDIUM',  'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_MEDIUM',  'LABEL_EN' => 'Woo UTM Medium',       'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CAMPAIGN','USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_CAMPAIGN','LABEL_EN' => 'Woo UTM Campaign',     'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_TERM',    'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_TERM',    'LABEL_EN' => 'Woo UTM Term',         'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_UTM_CONTENT', 'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_UTM_CONTENT', 'LABEL_EN' => 'Woo UTM Content',      'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
            ['FIELD_NAME' => 'UF_CRM_WOO_GA_CID',      'USER_TYPE_ID' => 'string',  'XML_ID' => 'WOO_GA_CID',      'LABEL_EN' => 'Woo GA Client ID',     'SHOW_IN_LIST' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N'],
        ];
    }

    /** @param array<string, string> $field */
    private function buildUserfieldPayload(array $field): array
    {
        return [
            'FIELD_NAME'        => $field['FIELD_NAME'],
            'USER_TYPE_ID'      => $field['USER_TYPE_ID'],
            'XML_ID'            => $field['XML_ID'],
            'EDIT_FORM_LABEL'   => ['en' => $field['LABEL_EN']],
            'LIST_COLUMN_LABEL' => ['en' => $field['LABEL_EN']],
            'LIST_FILTER_LABEL' => ['en' => $field['LABEL_EN']],
            'SHOW_IN_LIST'      => $field['SHOW_IN_LIST'],
            'SHOW_FILTER'       => $field['SHOW_FILTER'],
            'IS_SEARCHABLE'     => $field['IS_SEARCHABLE'],
            'MANDATORY'         => 'N',
            'MULTIPLE'          => 'N',
        ];
    }
}
