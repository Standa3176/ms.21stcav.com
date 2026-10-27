<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Services\BitrixClient;
use App\Domain\CRM\Support\BitrixFieldGuards;
use Throwable;

/**
 * Phase 12.1 Plan 02 — read-only Bitrix deal-field discovery (D-07, D-09).
 *
 * Resolves the UF_CRM_* codes for the deal fields labelled "Cost" and
 * "Predicted Margin" BY LABEL (exact, case-insensitive, trimmed). Ambiguity or
 * absence is a hard failure — no guessing. Also reports required-ness (D-09),
 * every field labelled "Actual Margin" (one of which must never be written),
 * and the deal categories + stages so the operator can confirm stage '2'/'NEW'.
 *
 * Only *Get / *List BitrixClient methods are used. Never writes to Bitrix.
 */
final class BitrixDiscoverOrderFieldsCommand extends BaseCommand
{
    protected $signature = 'bitrix:discover-order-fields
        {--json= : Write the discovery report as JSON to this path}
        {--labels=Cost,Predicted Margin : Comma-separated field labels to resolve}';

    protected $description = 'Read-only: resolve Cost / Predicted Margin deal field codes by label, report required fields, Actual Margin fields, categories and stages (Phase 12.1 D-07/D-09).';

    /**
     * Plugin "mapped-but-blank" UF_CRM_* codes from 12.1-PLUGIN-PARITY.md —
     * informational cross-check only (the Cost / PM codes are presumed to be
     * among them).
     *
     * @var list<string>
     */
    private const PLUGIN_MAPPED_BLANK = [
        'UF_CRM_1604945547839', 'UF_CRM_1604945580869', 'UF_CRM_5FAA9FEC56347', 'UF_CRM_1607603333887',
        'UF_CRM_1608201669116', 'UF_CRM_1608201774757', 'UF_CRM_1608635373569', 'UF_CRM_1609669381678',
        'UF_CRM_1610623973323', 'UF_CRM_1610625133691', 'UF_CRM_1612088413', 'UF_CRM_1612089138580',
        'UF_CRM_1620897112651', 'UF_CRM_1621415398761', 'UF_CRM_1621509939190', 'UF_CRM_1621509982671',
        'UF_CRM_1622552323874', 'UF_CRM_1622552378015', 'UF_CRM_1622552434054', 'UF_CRM_1623322183817',
        'UF_CRM_1623748289599', 'UF_CRM_1627047076715', 'UF_CRM_1628166398846', 'UF_CRM_1633433226968',
        'UF_CRM_1645009194141', 'UF_CRM_1650878004', 'UF_CRM_CLASSROOMST73', 'UF_CRM_1718119546751',
        'UF_CRM_67D3F451A0D4E', 'UF_CRM_686639B920104',
    ];

    private const ACTUAL_MARGIN_LABEL = 'actual margin';

    public function __construct(private readonly BitrixClient $client)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        if (empty(config('services.bitrix.webhook_url'))) {
            $this->error('bitrix:discover-order-fields: BITRIX_WEBHOOK_URL is empty.');

            return self::FAILURE;
        }

        try {
            $fields = $this->client->dealFieldsGet();
            $userfields = $this->client->dealUserfieldList();
        } catch (Throwable $e) {
            $this->error('Failed to read deal fields: '.$e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        /** @var array<string, array{labels: list<string>, type: string, required: bool, mandatory: string}> $index */
        $index = $this->buildIndex($fields, $userfields);

        $labels = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('labels')))));
        $failed = false;
        $resolved = [];

        $this->info('== Label resolution (D-07) ==');
        foreach ($labels as $label) {
            $codes = $this->codesForLabel($index, $label);
            $slug = str_replace(' ', '_', mb_strtolower($label));

            if ($codes === []) {
                $this->error(sprintf('NOT FOUND: no deal field is labelled "%s".', $label));
                $failed = true;
                $resolved[$slug] = null;

                continue;
            }
            if (count($codes) > 1) {
                $this->error(sprintf('AMBIGUOUS: %d deal fields are labelled "%s": %s. Refusing to guess.', count($codes), $label, implode(', ', $codes)));
                $failed = true;
                $resolved[$slug] = null;

                continue;
            }

            $code = $codes[0];
            if (BitrixFieldGuards::isForbiddenTarget($code)) {
                $this->error(sprintf('FORBIDDEN: label "%s" resolves to %s (Actual Margin — DO-NOT-WRITE).', $label, $code));
                $failed = true;
                $resolved[$slug] = null;

                continue;
            }

            $meta = $index[$code];
            $this->line(sprintf(
                '%s => %s  type=%s  required=%s  MANDATORY=%s%s',
                $label,
                $code,
                $meta['type'],
                $meta['required'] ? 'YES' : 'no',
                $meta['mandatory'] !== '' ? $meta['mandatory'] : '-',
                in_array($code, self::PLUGIN_MAPPED_BLANK, true) ? '  [plugin mapped-but-blank: consistent]' : '  [NOT in plugin mapped-but-blank list]',
            ));
            $resolved[$slug] = [
                'code' => $code,
                'type' => $meta['type'],
                'required' => $meta['required'],
                'in_plugin_blank_list' => in_array($code, self::PLUGIN_MAPPED_BLANK, true),
            ];
        }

        // Actual Margin fields — always listed.
        $this->newLine();
        $this->info('== "Actual Margin" fields ==');
        $actualMargin = [];
        foreach ($this->codesForLabel($index, self::ACTUAL_MARGIN_LABEL) as $code) {
            $forbidden = BitrixFieldGuards::isForbiddenTarget($code);
            $actualMargin[] = ['code' => $code, 'do_not_write' => $forbidden];
            $this->line(sprintf('%s%s', $code, $forbidden ? '  DO-NOT-WRITE (read by the dashboard)' : ''));
        }
        if ($actualMargin === []) {
            $this->warn('No field labelled "Actual Margin" found.');
        }

        // Required fields.
        $this->newLine();
        $this->info('== Required deal fields (D-09) ==');
        $required = [];
        foreach ($index as $code => $meta) {
            if ($meta['required']) {
                $required[] = ['code' => $code, 'label' => $meta['labels'][0] ?? '', 'type' => $meta['type']];
                $this->line(sprintf('%s  (%s)  type=%s', $code, $meta['labels'][0] ?? '-', $meta['type']));
            }
        }
        if ($required === []) {
            $this->line('(none)');
        }

        $conflict = [];
        foreach ($resolved as $name => $info) {
            if ($info !== null && $info['required']) {
                $conflict[] = $name.' ('.$info['code'].')';
            }
        }
        if ($conflict !== []) {
            $this->newLine();
            $this->line('+------------------------------------------------------------------+');
            $this->line('| D-09 CONFLICT: required field(s): '.implode(', ', $conflict));
            $this->line('| D-06 may leave these blank, but Bitrix would then reject         |');
            $this->line('| crm.deal.add. Resolution is an OPERATOR DECISION in 12.1-05.     |');
            $this->line('| NEVER write 0 as a placeholder.                                  |');
            $this->line('+------------------------------------------------------------------+');
        }

        // Categories + stages.
        $this->newLine();
        $this->info('== Deal categories + stages ==');
        $categoriesOut = [];
        $stagesOut = [];

        try {
            $categories = $this->client->dealCategoryList();
            $categoryIds = [0 => 'Default pipeline'];
            foreach ($categories as $cat) {
                $categoryIds[(int) ($cat['ID'] ?? 0)] = (string) ($cat['NAME'] ?? '');
            }
            foreach ($categoryIds as $id => $name) {
                $categoriesOut[] = ['id' => (string) $id, 'name' => $name];
                $stages = $this->client->dealStageList($id);
                $stagesOut[(string) $id] = $stages;
                $this->line(sprintf('Category %d (%s)', $id, $name));
                $this->table(
                    ['STATUS_ID', 'NAME', 'SORT', 'SEMANTICS'],
                    array_map(static fn (array $s): array => [$s['STATUS_ID'], $s['NAME'], $s['SORT'], $s['SEMANTICS'] ?: '-'], $stages),
                );
            }
        } catch (Throwable $e) {
            $this->error('Failed to read categories/stages: '.$e::class.': '.$e->getMessage());
            $failed = true;
        }

        $path = (string) ($this->option('json') ?? '');
        if ($path !== '') {
            $report = [
                'cost' => $resolved['cost'] ?? null,
                'predicted_margin' => $resolved['predicted_margin'] ?? null,
                'actual_margin_fields' => $actualMargin,
                'required_fields' => $required,
                'categories' => $categoriesOut,
                'stages' => $stagesOut,
                'generated_at' => now()->toIso8601String(),
            ];
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Report written to '.$path);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, mixed>  $userfields
     * @return array<string, array{labels: list<string>, type: string, required: bool, mandatory: string}>
     */
    private function buildIndex(array $fields, array $userfields): array
    {
        $index = [];

        foreach ($fields as $code => $descriptor) {
            if (! is_array($descriptor)) {
                continue;
            }
            $index[(string) $code] = [
                'labels' => $this->collectLabels([
                    $descriptor['title'] ?? null,
                    $descriptor['formLabel'] ?? null,
                    $descriptor['listLabel'] ?? null,
                    $descriptor['filterLabel'] ?? null,
                ]),
                'type' => (string) ($descriptor['type'] ?? ''),
                'required' => ! empty($descriptor['isRequired']),
                'mandatory' => '',
            ];
        }

        foreach ($userfields as $row) {
            if (! is_array($row) || empty($row['FIELD_NAME'])) {
                continue;
            }
            $code = (string) $row['FIELD_NAME'];
            $labels = $this->collectLabels([
                $row['EDIT_FORM_LABEL'] ?? null,
                $row['LIST_COLUMN_LABEL'] ?? null,
                $row['LIST_FILTER_LABEL'] ?? null,
            ]);
            $mandatory = (string) ($row['MANDATORY'] ?? '');

            if (! isset($index[$code])) {
                $index[$code] = ['labels' => [], 'type' => (string) ($row['USER_TYPE_ID'] ?? ''), 'required' => false, 'mandatory' => ''];
            }
            $index[$code]['labels'] = array_values(array_unique(array_merge($index[$code]['labels'], $labels)));
            $index[$code]['mandatory'] = $mandatory;
            if ($mandatory === 'Y') {
                $index[$code]['required'] = true;
            }
            if ($index[$code]['type'] === '' && ! empty($row['USER_TYPE_ID'])) {
                $index[$code]['type'] = (string) $row['USER_TYPE_ID'];
            }
        }

        return $index;
    }

    /**
     * @param  array<int, mixed>  $values  strings or per-language label maps
     * @return list<string>
     */
    private function collectLabels(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            foreach ((array) $value as $v) {
                if (is_string($v) && trim($v) !== '') {
                    $out[] = trim($v);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, array{labels: list<string>, type: string, required: bool, mandatory: string}>  $index
     * @return list<string>
     */
    private function codesForLabel(array $index, string $label): array
    {
        $needle = mb_strtolower(trim($label));
        $codes = [];
        foreach ($index as $code => $meta) {
            foreach ($meta['labels'] as $l) {
                if (mb_strtolower($l) === $needle) {
                    $codes[] = (string) $code;
                    break;
                }
            }
        }

        return $codes;
    }
}
