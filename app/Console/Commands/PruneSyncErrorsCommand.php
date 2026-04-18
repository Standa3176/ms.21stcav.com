<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Models\SyncError;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Console\Command;

/**
 * D-07: retention prune for sync_errors — 90-day convention (matches Phase 1 D-05).
 *
 * Scheduled at 03:20 daily in routes/console.php (between 03:10 integration_events
 * and 03:30 sync_diffs). Replaces the Phase 1 Plan 05 TODO marker for this slot.
 *
 * D-09 meta-audit: every run writes an `sync-errors.pruned` activity log row
 * via Auditor with the deleted row count and cutoff date — retention enforcement
 * is itself auditable.
 *
 * Safety: --days=0 is treated as a no-op (guards against accidental wipe by
 * an operator who typos `--days=0` when they meant `--days=30`).
 *
 * Inherits correlation_id threading when run via artisan from an HTTP context
 * or another command that seeded Context; standalone cron invocation gets a
 * fresh UUID via the scheduler's correlation-id seeder (Phase 1 P05 pattern).
 */
class PruneSyncErrorsCommand extends Command
{
    protected $signature = 'sync-errors:prune {--days=90}';

    protected $description = 'Prune sync_errors rows older than --days (default 90 per D-07)';

    public function handle(Auditor $auditor): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $auditor->record('sync-errors.prune.skipped', [
                'reason' => 'days_below_minimum',
                'days' => $days,
            ]);
            $this->warn("sync-errors:prune aborted: --days must be >= 1 (got {$days}).");

            return self::SUCCESS;  // graceful no-op — operator error, not a failure
        }

        $cutoff = now()->subDays($days);

        $deleted = SyncError::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $auditor->record('sync-errors.pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
            'days' => $days,
        ]);

        $this->info("Pruned {$deleted} sync_errors rows older than {$days} days.");

        return self::SUCCESS;
    }
}
