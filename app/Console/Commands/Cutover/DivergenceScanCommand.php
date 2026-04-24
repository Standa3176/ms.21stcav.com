<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Cutover\Services\DivergenceScanner;

/**
 * Phase 7 Plan 05 Task 1 — CUT-01 divergence scan artisan entry.
 *
 * Dry-run by default (D-04 convention from Phase 2 Plan 03):
 *   php artisan cutover:divergence-scan
 *
 * --live persists SyncDiff rows (provider='divergence-scan') + upserts
 * dashboard_snapshots.sync_diffs_parity so the /admin widget flips:
 *   php artisan cutover:divergence-scan --live
 *
 * Schedulable daily at 01:00 Europe/London during the parallel-run window
 * (opt-in via env — see routes/console.php).
 */
class DivergenceScanCommand extends BaseCommand
{
    protected $signature = 'cutover:divergence-scan
        {--live : Persist sync_diffs rows + dashboard_snapshots.sync_diffs_parity (default is dry-run)}';

    protected $description = 'Compare Laravel product state against live Woo; report or persist divergence (CUT-01)';

    public function perform(): int
    {
        $scanner = app(DivergenceScanner::class);
        $persist = (bool) $this->option('live');

        if (! $persist) {
            $this->warn('DRY-RUN — no sync_diffs rows will be written. Use --live to persist.');
        }

        $result = $scanner->scan(
            writePersistent: $persist,
            progress: function (int $n, string $sku, string $status): void {
                if ($n % 100 === 0) {
                    $this->line("  scanned={$n} sku={$sku} status={$status}");
                }
            },
        );

        $this->info(sprintf(
            'Scanned %d products. %d diverged (%d field diffs). parity=%s correlation_id=%s',
            $result['scanned'],
            $result['divergedProducts'],
            $result['totalFieldDiffs'],
            $result['parityPercent'] ?? 'n/a',
            $result['correlationId'],
        ));

        return self::SUCCESS;
    }
}
