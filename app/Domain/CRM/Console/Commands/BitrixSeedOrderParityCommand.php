<?php

declare(strict_types=1);

namespace App\Domain\CRM\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\CRM\Models\CrmFieldMapping;
use App\Domain\CRM\Models\CrmPipelineSetting;
use App\Domain\CRM\Models\CrmStatusMapping;
use App\Domain\CRM\Support\BitrixFieldGuards;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Phase 12.1 Plan 02 — reviewable, idempotent plugin-parity seeder (D-08).
 *
 * Reads database/seeders/Phase12_1/order_parity_map.php, validates it, prints a
 * per-section diff against the ops-app DB and (only with --apply) writes it.
 * Writes ONLY to the ops-app database — never to Bitrix or Woo.
 */
final class BitrixSeedOrderParityCommand extends BaseCommand
{
    protected $signature = 'bitrix:seed-order-parity
        {--apply : Write the map to the database (default is a dry-run diff)}
        {--map= : Override the data file path (tests)}';

    protected $description = 'Dry-run-first: diff and apply the plugin-parity order-push config (pipeline, status stages, deal field mappings) from order_parity_map.php (Phase 12.1 D-08).';

    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'refunded', 'failed'];

    private const REQUIRED_STAGE_STATUSES = ['processing', 'on-hold', 'ywraq-new', 'ywraq-pending'];

    public function __construct(private readonly Auditor $auditor)
    {
        parent::__construct();
    }

    protected function perform(): int
    {
        $path = (string) ($this->option('map') ?: database_path('seeders/Phase12_1/order_parity_map.php'));
        if (! is_file($path)) {
            $this->error('Map file not found: '.$path);

            return self::FAILURE;
        }

        /** @var array<string, mixed> $map */
        $map = require $path;
        $errors = $this->validate($map);

        $diff = $this->computeDiff($map);
        $this->printDiff($diff);

        if ($errors !== []) {
            $this->newLine();
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error('Validation failed — nothing written.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('DRY-RUN — nothing written. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->apply($map));

        $counts = [
            'pipeline_changes' => count($diff['pipeline']),
            'status_changes' => count($diff['status_stages']),
            'mapping_adds' => count($diff['mappings_add']),
            'mapping_updates' => count($diff['mappings_update']),
            'mapping_deletes' => count($diff['mappings_delete']),
        ];
        $this->auditor->record('crm.order_parity.seeded', $counts);
        $this->info('Applied: '.json_encode($counts));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return list<string>
     */
    private function validate(array $map): array
    {
        $errors = [];
        $pipeline = (array) ($map['pipeline'] ?? []);

        foreach (['deal_cost_field', 'deal_predicted_margin_field'] as $key) {
            if (($pipeline[$key] ?? null) === null || $pipeline[$key] === '') {
                $errors[] = "UNRESOLVED: pipeline.{$key} is null (resolve via bitrix:discover-order-fields in 12.1-05).";
            }
        }
        foreach (['bitrix_pipeline_id', 'assigned_user_id', 'deal_title_template'] as $key) {
            if (($pipeline[$key] ?? null) === null || $pipeline[$key] === '') {
                $errors[] = "pipeline.{$key} must not be null.";
            }
        }

        $stages = (array) ($map['status_stages'] ?? []);
        foreach (self::REQUIRED_STAGE_STATUSES as $status) {
            if (($stages[$status] ?? null) === null || $stages[$status] === '') {
                $errors[] = "status_stages.{$status} must be set (deal-creating status, D-01a).";
            }
        }

        $guarded = array_filter([
            strtoupper((string) ($pipeline['deal_cost_field'] ?? '')),
            strtoupper((string) ($pipeline['deal_predicted_margin_field'] ?? '')),
        ], static fn (string $c): bool => $c !== '');

        foreach ($guarded as $code) {
            if (BitrixFieldGuards::isForbiddenTarget($code)) {
                $errors[] = "FORBIDDEN: {$code} is the Actual Margin field.";
            }
        }

        foreach ((array) ($pipeline['deal_static_fields'] ?? []) as $key => $_value) {
            if (BitrixFieldGuards::isForbiddenTarget((string) $key)) {
                $errors[] = 'FORBIDDEN: static field targets '.BitrixFieldGuards::ACTUAL_MARGIN_FIELD.' (Actual Margin).';
            }
            if (in_array(strtoupper((string) $key), $guarded, true)) {
                $errors[] = "Cost/Predicted Margin code {$key} must not be a static field.";
            }
        }

        $seen = [];
        foreach ((array) ($map['deal_field_mappings'] ?? []) as $row) {
            $woo = (string) ($row['woo_field'] ?? '');
            $bitrix = (string) ($row['bitrix_field'] ?? '');
            if ($woo === '' || $bitrix === '') {
                $errors[] = 'Mapping rows need woo_field and bitrix_field.';

                continue;
            }
            if (isset($seen[$woo])) {
                $errors[] = "Duplicate woo_field in deal_field_mappings: {$woo}";
            }
            $seen[$woo] = true;
            if (BitrixFieldGuards::isForbiddenTarget($bitrix)) {
                $errors[] = "FORBIDDEN: mapping {$woo} targets ".BitrixFieldGuards::ACTUAL_MARGIN_FIELD.' (Actual Margin).';
            }
            if (in_array(strtoupper($bitrix), $guarded, true)) {
                $errors[] = "Cost/Predicted Margin code {$bitrix} must not be a mapping target (mapping {$woo}).";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array{pipeline: array<string, array{from: mixed, to: mixed}>, status_stages: array<string, array{from: mixed, to: mixed}>, mappings_add: list<string>, mappings_update: list<string>, mappings_delete: list<string>}
     */
    private function computeDiff(array $map): array
    {
        $diff = ['pipeline' => [], 'status_stages' => [], 'mappings_add' => [], 'mappings_update' => [], 'mappings_delete' => []];

        $current = CrmPipelineSetting::query()->first();
        foreach ((array) ($map['pipeline'] ?? []) as $key => $to) {
            $from = $current?->{$key};
            if (json_encode($from) !== json_encode($to)) {
                $diff['pipeline'][$key] = ['from' => $from, 'to' => $to];
            }
        }

        foreach ((array) ($map['status_stages'] ?? []) as $status => $stage) {
            $row = CrmStatusMapping::query()->where('woo_status', $status)->first();
            if ($row === null || $row->bitrix_stage_id !== $stage) {
                $diff['status_stages'][(string) $status] = ['from' => $row === null ? '(no row)' : $row->bitrix_stage_id, 'to' => $stage];
            }
        }

        $listed = [];
        foreach ((array) ($map['deal_field_mappings'] ?? []) as $row) {
            $woo = (string) ($row['woo_field'] ?? '');
            $listed[] = $woo;
            $existing = CrmFieldMapping::query()->where('entity_type', CrmFieldMapping::ENTITY_DEAL)->where('woo_field', $woo)->first();
            if ($existing === null) {
                $diff['mappings_add'][] = $woo.' -> '.($row['bitrix_field'] ?? '');
            } elseif ($existing->bitrix_field !== ($row['bitrix_field'] ?? null)
                || (string) $existing->transformer !== (string) ($row['transformer'] ?? '')
                || $existing->is_custom !== (bool) ($row['is_custom'] ?? false)) {
                $diff['mappings_update'][] = $woo.' -> '.($row['bitrix_field'] ?? '');
            }
        }

        if (! empty($map['prune_unlisted_deal_mappings'])) {
            $diff['mappings_delete'] = CrmFieldMapping::query()
                ->where('entity_type', CrmFieldMapping::ENTITY_DEAL)
                ->whereNotIn('woo_field', $listed)
                ->pluck('woo_field')
                ->map(fn ($w): string => (string) $w)
                ->all();
        }

        return $diff;
    }

    /** @param  array<string, mixed>  $diff */
    private function printDiff(array $diff): void
    {
        $this->info('== pipeline ==');
        if ($diff['pipeline'] === []) {
            $this->line('(no change)');
        }
        foreach ($diff['pipeline'] as $key => $change) {
            $this->line(sprintf('%s: %s => %s', $key, json_encode($change['from']), json_encode($change['to'])));
        }

        $this->info('== status_stages ==');
        if ($diff['status_stages'] === []) {
            $this->line('(no change)');
        }
        foreach ($diff['status_stages'] as $status => $change) {
            $this->line(sprintf('%s: %s => %s', $status, json_encode($change['from']), json_encode($change['to'])));
        }

        $this->info('== deal_field_mappings ==');
        if ($diff['mappings_add'] === [] && $diff['mappings_update'] === [] && $diff['mappings_delete'] === []) {
            $this->line('(no change)');
        }
        foreach ($diff['mappings_add'] as $line) {
            $this->line('+ '.$line);
        }
        foreach ($diff['mappings_update'] as $line) {
            $this->line('~ '.$line);
        }
        foreach ($diff['mappings_delete'] as $line) {
            $this->line('- '.$line);
        }
    }

    /** @param  array<string, mixed>  $map */
    private function apply(array $map): void
    {
        CrmPipelineSetting::current()->update((array) $map['pipeline']);

        foreach ((array) ($map['status_stages'] ?? []) as $status => $stage) {
            $row = CrmStatusMapping::query()->where('woo_status', $status)->first();
            if ($row === null) {
                CrmStatusMapping::create([
                    'woo_status' => (string) $status,
                    'bitrix_stage_id' => $stage,
                    'bitrix_stage_label' => $stage ?? 'NONE',
                    'is_terminal' => in_array($status, self::TERMINAL_STATUSES, true),
                ]);
            } elseif ($row->bitrix_stage_id !== $stage) {
                $row->update(['bitrix_stage_id' => $stage]);
            }
        }

        $listed = [];
        foreach (array_values((array) ($map['deal_field_mappings'] ?? [])) as $i => $row) {
            $listed[] = (string) $row['woo_field'];
            CrmFieldMapping::updateOrCreate(
                ['entity_type' => CrmFieldMapping::ENTITY_DEAL, 'woo_field' => (string) $row['woo_field']],
                [
                    'bitrix_field' => (string) $row['bitrix_field'],
                    'is_custom' => (bool) ($row['is_custom'] ?? false),
                    'transformer' => $row['transformer'] ?? null,
                    'sort_order' => $i,
                ],
            );
        }

        if (! empty($map['prune_unlisted_deal_mappings'])) {
            CrmFieldMapping::query()
                ->where('entity_type', CrmFieldMapping::ENTITY_DEAL)
                ->whereNotIn('woo_field', $listed)
                ->get()
                ->each(fn (CrmFieldMapping $m) => $m->delete());
        }
    }
}
