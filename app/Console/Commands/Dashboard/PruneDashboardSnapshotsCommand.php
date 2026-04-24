<?php

declare(strict_types=1);

namespace App\Console\Commands\Dashboard;

use App\Console\Commands\BaseCommand;
use App\Domain\Dashboard\Models\DashboardSnapshot;

/**
 * Phase 7 Plan 02 — `snapshots:prune` (retention).
 *
 * Daily 03:50 window (routes/console.php cascade continues after
 * competitor:csv-prune at 03:40). Deletes dashboard_snapshots rows older
 * than `config('dashboard.snapshot_retention_days', 30)`.
 *
 * In practice, today's dashboard keeps <=20 rows (upsert-by-metric_key), so
 * retention-prune is a safety net + forward-compat for the deferred
 * sparkline-history table split (CONTEXT.md §deferred).
 *
 * Mirrors the Phase 1 retention prune pattern (01-05-b):
 *   - --days override supported (operators can age rows out early)
 *   - --days=0 is a no-op safety guard (explicit zero — Phase 5 competitor
 *     prune precedent; prevents accidental full-table wipe from a typo)
 */
final class PruneDashboardSnapshotsCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'snapshots:prune {--days= : Override retention days (default: config dashboard.snapshot_retention_days)}';

    /** @var string */
    protected $description = 'Delete dashboard_snapshots rows older than N days (default from config)';

    protected function perform(): int
    {
        $flag = $this->option('days');

        if ($flag !== null && (int) $flag === 0) {
            $this->warn('--days=0 is a no-op safety guard. Exiting.');

            return self::SUCCESS;
        }

        $days = $flag === null
            ? (int) config('dashboard.snapshot_retention_days', 30)
            : (int) $flag;

        $deleted = DashboardSnapshot::where('computed_at', '<', now()->subDays($days))->delete();

        $this->info(sprintf(
            'Pruned %d dashboard_snapshots older than %d days.',
            $deleted,
            $days,
        ));

        return self::SUCCESS;
    }
}
