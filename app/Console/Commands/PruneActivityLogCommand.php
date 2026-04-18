<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\Audit\Services\Auditor;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * D-04: prune activity_log rows older than N days (default 365).
 * D-09: the prune action itself writes a meta-audit entry so retention
 *       enforcement is auditable.
 *
 * Volume: ~1M rows/year at current catalogue size. 365-day window covers
 * annual compliance audits + year-long forensic lookups ("who changed
 * this price last year?").
 */
class PruneActivityLogCommand extends Command
{
    protected $signature = 'activitylog:prune {--days=365}';

    protected $description = 'Prune activity_log rows older than --days (default 365 per D-04)';

    public function handle(Auditor $auditor): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = Activity::where('created_at', '<', $cutoff)->delete();

        $auditor->record('activitylog.pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
            'days' => $days,
        ]);

        $this->info("Pruned {$deleted} activity_log rows older than {$days} days.");

        return self::SUCCESS;
    }
}
