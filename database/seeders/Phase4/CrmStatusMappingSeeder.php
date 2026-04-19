<?php

declare(strict_types=1);

namespace Database\Seeders\Phase4;

use App\Domain\CRM\Models\CrmStatusMapping;
use Illuminate\Database\Seeder;

/**
 * Phase 4 Plan 01 — CrmStatusMappingSeeder (D-06, CRM-07).
 *
 * Ported from legacy plugin `functions/itglx-wcbx24-update-deal-stage.php`.
 * Seven standard Woo statuses map to Bitrix stage LABELS (pipeline-agnostic);
 * Plan 04-04's Filament UI lets admins replace the labels with real
 * pipeline-scoped STAGE_IDs.
 *
 * is_terminal guides D-09's stage-change protection:
 *   - completed → WON  (terminal)
 *   - cancelled / refunded / failed → LOSE (terminal)
 *   - pending / processing / on-hold → non-terminal (in-flight)
 *
 * Idempotent via firstOrCreate — safe to run on every deploy.
 */
final class CrmStatusMappingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['woo_status' => 'pending',    'bitrix_stage_label' => 'NEW',                'is_terminal' => false],
            ['woo_status' => 'processing', 'bitrix_stage_label' => 'PREPAYMENT_INVOICE', 'is_terminal' => false],
            ['woo_status' => 'on-hold',    'bitrix_stage_label' => 'EXECUTING',          'is_terminal' => false],
            ['woo_status' => 'completed',  'bitrix_stage_label' => 'WON',                'is_terminal' => true],
            ['woo_status' => 'cancelled',  'bitrix_stage_label' => 'LOSE',               'is_terminal' => true],
            ['woo_status' => 'refunded',   'bitrix_stage_label' => 'LOSE',               'is_terminal' => true],
            ['woo_status' => 'failed',     'bitrix_stage_label' => 'LOSE',               'is_terminal' => true],
        ];

        foreach ($rows as $row) {
            CrmStatusMapping::firstOrCreate(
                ['woo_status' => $row['woo_status']],
                $row,
            );
        }
    }
}
