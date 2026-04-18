<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Foundation\Audit\Services\Auditor;
use App\Foundation\Integration\Models\IntegrationEvent;
use Illuminate\Console\Command;

/**
 * D-05: prune integration_events rows older than N days (default 90).
 *
 * Volume: outbound API call log — the highest-volume table in the app.
 * Can hit tens of millions of rows in production.  Sentry captures failure
 * signals independently, so the 90-day window balances forensics vs disk.
 *
 * D-09: writes a meta-audit entry naming the deleted count + cutoff date.
 */
class PruneIntegrationEventsCommand extends Command
{
    protected $signature = 'integration-events:prune {--days=90}';

    protected $description = 'Prune integration_events rows older than --days (default 90 per D-05)';

    public function handle(Auditor $auditor): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = IntegrationEvent::where('created_at', '<', $cutoff)->delete();

        $auditor->record('integration-events.pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
            'days' => $days,
        ]);

        $this->info("Pruned {$deleted} integration_events rows older than {$days} days.");

        return self::SUCCESS;
    }
}
