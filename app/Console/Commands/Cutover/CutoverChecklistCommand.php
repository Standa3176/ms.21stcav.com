<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Cutover\Services\CutoverChecklistReporter;

/**
 * Phase 7 Plan 05 Task 3 — D-21 cutover readiness checklist.
 *
 * Usage:
 *   php artisan cutover:checklist
 *     Prints a markdown table of every gate (Phase 6 D-20 + D-19 runbook)
 *     with PASS / PENDING / FAIL / MANUAL status. Exit code is 1 when any
 *     item is NOT PASS/MANUAL — green exit is the go/no-go flag-flip signal.
 *
 *   php artisan cutover:checklist --update-status=woo-sandbox:pass
 *     Persists a manual override to storage/app/cutover/checklist-state.json
 *     so subsequent runs reflect ops tick-off.
 */
class CutoverChecklistCommand extends BaseCommand
{
    protected $signature = 'cutover:checklist
        {--update-status= : Format "item-id:pass|fail|pending|manual" — persists ops tick-off}';

    protected $description = 'Cutover readiness checklist (D-21) — integrates Phase 6 D-20 gates + D-19 runbook';

    public function perform(): int
    {
        $reporter = app(CutoverChecklistReporter::class);
        $update = $this->option('update-status');

        if (! empty($update)) {
            if (! str_contains((string) $update, ':')) {
                $this->error('--update-status must be in the form "item-id:pass|fail|pending|manual".');

                return self::FAILURE;
            }
            [$id, $status] = explode(':', (string) $update, 2);
            try {
                $reporter->updateStatus($id, $status);
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("Updated {$id} to {$status}.");

            return self::SUCCESS;
        }

        $gates = $reporter->gates();
        $this->line('# Cutover Readiness Checklist');
        $this->line('');
        $this->line('| Item | Status | Action |');
        $this->line('|------|--------|--------|');

        $nonPass = 0;
        foreach ($gates as $gate) {
            $this->line(sprintf(
                '| %s | **%s** | %s |',
                $gate['title'],
                $gate['status'],
                $gate['action'],
            ));
            if (! in_array($gate['status'], [
                CutoverChecklistReporter::STATUS_PASS,
                CutoverChecklistReporter::STATUS_MANUAL,
            ], true)) {
                $nonPass++;
            }
        }

        $this->line('');
        if ($nonPass === 0) {
            $this->info('ALL CHECKS PASSED — safe to flip WOO_WRITE_ENABLED=true.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d item(s) PENDING or FAIL — DO NOT flip WOO_WRITE_ENABLED yet.',
            $nonPass,
        ));

        return self::FAILURE;
    }
}
