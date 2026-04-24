<?php

declare(strict_types=1);

namespace App\Console\Commands\Dashboard;

use App\Console\Commands\BaseCommand;
use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Dashboard\Services\SnapshotAggregator;

/**
 * Phase 7 Plan 02 — `dashboard:refresh` (D-02).
 *
 * Scheduled every 5 minutes (see routes/console.php). Calls
 * SnapshotAggregator::computeAll and upserts each metric into
 * dashboard_snapshots by metric_key. After the run, the 9 home-dashboard
 * widgets read their fixed rowset on the next poll — no live aggregation
 * on page load.
 *
 * Extends BaseCommand so every run has a correlation_id thread and the
 * spatie/activitylog batch mirrors the Sync + CRM + Competitor pipelines.
 */
final class DashboardRefreshCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'dashboard:refresh';

    /** @var string */
    protected $description = 'Aggregate the 9 home-dashboard metrics into dashboard_snapshots (D-02)';

    protected function perform(): int
    {
        /** @var SnapshotAggregator $aggregator */
        $aggregator = app(SnapshotAggregator::class);

        $computed = $aggregator->computeAll();
        foreach ($computed as $metricKey => $payload) {
            DashboardSnapshot::upsertByKey($metricKey, $payload);
            $this->line(sprintf('snapshot:%s updated', $metricKey));
        }

        $this->info(sprintf(
            'Dashboard refresh complete: %d metrics upserted.',
            count($computed),
        ));

        return self::SUCCESS;
    }
}
