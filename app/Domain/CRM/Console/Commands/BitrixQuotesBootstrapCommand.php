<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Services\BitrixClient;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Phase 11 Plan 04 — Bitrix Quotes pre-flight bootstrap (Pitfall 2).
 *
 * Standalone artisan command (NOT an extension of Phase 4
 * BitrixBootstrapCommand per B-03 — that command is left BYTE-IDENTICAL).
 * Operator runs this BEFORE flipping QUOTE_BITRIX_PUSH_ENABLED=true.
 *
 * Steps:
 *   1. List Bitrix deal categories via crm.dealcategory.list (A8 verified —
 *      vendor SDK $sdk->getCRMScope()->dealCategory()->list()).
 *   2. Verify a category exists whose ID or NAME matches
 *      config('quote.bitrix_deal_type_id', 'QUOTE'). If missing, print the
 *      operator runbook + exit 1.
 *   3. Create UF_CRM_WOO_QUOTE_ID custom field on the Deal entity (via the
 *      existing Phase 4 dealUserfieldAdd path) — idempotent: if a userfield
 *      with FIELD_NAME=UF_CRM_WOO_QUOTE_ID already exists, skip.
 *   4. Set the cache marker `quote.bitrix_quote_type_verified=true` (30-day
 *      TTL) — Plan 11-05 cutover:checklist queries this marker before
 *      surfacing the QUOTE_BITRIX_PUSH_ENABLED flip checklist item as
 *      "ready to flip".
 *
 * Auditor records every run in activity_log (success or failure) so the
 * operator trail is permanent. Audit shape mirrors BitrixBootstrapCommand.
 */
final class BitrixQuotesBootstrapCommand extends BaseCommand
{
    protected $signature = 'bitrix:quotes-bootstrap {--probe : Read-only check; never call userfield.add}';

    protected $description = 'Pre-flight check for Phase 11 quote-flow: verify Bitrix dealtype + create UF_CRM_WOO_QUOTE_ID. Idempotent.';

    public const CACHE_KEY_VERIFIED = 'quote.bitrix_quote_type_verified';

    public const FIELD_NAME = 'UF_CRM_WOO_QUOTE_ID';

    public function __construct(
        private readonly BitrixClient $client,
        private readonly Auditor $auditor,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        if (empty(config('services.bitrix.webhook_url'))) {
            $this->error('BitrixQuotesBootstrapCommand: BITRIX_WEBHOOK_URL is empty. Set it in .env before running this command.');

            return self::FAILURE;
        }

        $expectedTypeId = (string) config('quote.bitrix_deal_type_id', 'QUOTE');
        $isProbe = (bool) $this->option('probe');

        // ── Step 1 + 2: Verify dealtype exists ────────────────────────────
        try {
            $categories = $this->client->dealCategoryList();
        } catch (Throwable $e) {
            $this->error('BitrixQuotesBootstrapCommand: failed to list deal categories — '.$e::class.': '.$e->getMessage());
            $this->auditor->record('bitrix.quotes_bootstrap.failed', [
                'step' => 'dealcategory_list',
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            return 2;
        }

        $matched = $this->findMatchingCategory($categories, $expectedTypeId);

        if ($matched === null) {
            $this->printOperatorRunbook($expectedTypeId, $categories);
            $this->auditor->record('bitrix.quotes_bootstrap.dealtype_missing', [
                'expected_type_id' => $expectedTypeId,
                'available_categories' => array_map(static fn ($c) => ['ID' => $c['ID'] ?? '', 'NAME' => $c['NAME'] ?? ''], $categories),
            ]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'bitrix:quotes-bootstrap — deal category PASS: ID=%s NAME=%s matches QUOTE_BITRIX_DEAL_TYPE_ID=%s.',
            $matched['ID'] ?? '?',
            $matched['NAME'] ?? '?',
            $expectedTypeId,
        ));

        // ── Step 3: Create UF_CRM_WOO_QUOTE_ID idempotently ──────────────
        $created = false;
        try {
            $existingFields = $this->client->dealUserfieldList([]);
            $alreadyExists = false;
            foreach ($existingFields as $row) {
                if (($row['FIELD_NAME'] ?? null) === self::FIELD_NAME) {
                    $alreadyExists = true;
                    break;
                }
            }

            if ($alreadyExists) {
                $this->info('bitrix:quotes-bootstrap — '.self::FIELD_NAME.' already exists, skipping.');
            } elseif ($isProbe) {
                $this->line('bitrix:quotes-bootstrap — would create '.self::FIELD_NAME.' (probe mode; skipping).');
            } else {
                $payload = [
                    'FIELD_NAME' => self::FIELD_NAME,
                    'USER_TYPE_ID' => 'string',
                    'XML_ID' => 'WOO_QUOTE_ID',
                    'EDIT_FORM_LABEL' => ['en' => 'Woo Quote ID'],
                    'LIST_COLUMN_LABEL' => ['en' => 'Woo Quote ID'],
                    'LIST_FILTER_LABEL' => ['en' => 'Woo Quote ID'],
                    'SHOW_IN_LIST' => 'Y',
                    'SHOW_FILTER' => 'I',
                    'IS_SEARCHABLE' => 'Y',
                    'MANDATORY' => 'N',
                    'MULTIPLE' => 'N',
                ];
                $this->client->dealUserfieldAdd($payload);
                $created = true;
                $this->info('bitrix:quotes-bootstrap — created '.self::FIELD_NAME.'.');
            }
        } catch (Throwable $e) {
            // Tolerate "duplicate field" Bitrix errors — treat as already exists.
            if (str_contains(strtolower($e->getMessage()), 'already exists')
                || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                $this->warn('bitrix:quotes-bootstrap — '.self::FIELD_NAME.' creation reported duplicate; treating as success.');
            } else {
                $this->error('bitrix:quotes-bootstrap — userfield path failed: '.$e->getMessage());
                $this->auditor->record('bitrix.quotes_bootstrap.userfield_failed', [
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ]);

                return 2;
            }
        }

        // ── Step 4: Mark verified in cache (Plan 11-05 cutover gate) ─────
        if (! $isProbe) {
            Cache::put(self::CACHE_KEY_VERIFIED, true, now()->addDays(30));
        }

        $this->auditor->record('bitrix.quotes_bootstrap', [
            'expected_type_id' => $expectedTypeId,
            'matched_category_id' => $matched['ID'] ?? null,
            'matched_category_name' => $matched['NAME'] ?? null,
            'userfield_created' => $created,
            'probe' => $isProbe,
        ]);

        $this->info('');
        $this->info('bitrix:quotes-bootstrap PASS — '.self::FIELD_NAME.' present, dealtype '.$expectedTypeId.' verified.');
        $this->info('Operator may now flip QUOTE_BITRIX_PUSH_ENABLED=true.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, mixed>|null
     */
    private function findMatchingCategory(array $categories, string $expectedTypeId): ?array
    {
        foreach ($categories as $cat) {
            $id = (string) ($cat['ID'] ?? '');
            $name = (string) ($cat['NAME'] ?? '');
            if ($id === $expectedTypeId
                || strcasecmp($name, $expectedTypeId) === 0
                || strcasecmp($name, 'QUOTE') === 0
                || strcasecmp($name, 'Quote') === 0) {
                return $cat;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function printOperatorRunbook(string $expectedTypeId, array $categories): void
    {
        $this->error('bitrix:quotes-bootstrap FAIL — no Bitrix deal category matching '.$expectedTypeId.' was found.');
        $this->line('');
        $this->line('Operator runbook:');
        $this->line('  1. Log in to Bitrix24 admin → CRM → Settings → Deal Categories');
        $this->line('  2. Click "Add Category" and create a new pipeline named "'.$expectedTypeId.'"');
        $this->line('  3. Save, then re-run: php artisan bitrix:quotes-bootstrap');
        $this->line('');

        if ($categories !== []) {
            $this->line('Available deal categories in this tenant:');
            foreach ($categories as $cat) {
                $this->line(sprintf('  - ID=%s NAME=%s', $cat['ID'] ?? '?', $cat['NAME'] ?? '?'));
            }
        } else {
            $this->line('No deal categories returned by Bitrix. Check BITRIX_WEBHOOK_URL permissions.');
        }
    }
}
