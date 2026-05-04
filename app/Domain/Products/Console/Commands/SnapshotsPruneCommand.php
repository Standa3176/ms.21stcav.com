<?php

declare(strict_types=1);

namespace App\Domain\Products\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Products\Models\ProductPriceSnapshot;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * Quick task 260504-muq — 90-day rolling retention prune.
 *
 * Deletes product_price_snapshots + supplier_offer_snapshots rows older than
 * config('history.retention_days', 90). Scheduled daily at 04:00 Europe/London
 * via routes/console.php (continues the 03:00..03:50 retention cascade with
 * a 10-min gap so the new history prune doesn't collide with the
 * dashboard_snapshots prune at 03:50).
 *
 * Command name `history:prune` (NOT `snapshots:prune`) because that name is
 * already owned by Phase 7 Plan 02's PruneDashboardSnapshotsCommand. Using
 * the same name would silently shadow one of the two commands depending on
 * registration order in AppServiceProvider — explicit naming avoids that
 * footgun (deviation from plan brief; Rule 3).
 */
final class SnapshotsPruneCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'history:prune {--days= : Override retention days (default: config history.retention_days)}';

    /** @var string */
    protected $description = 'Prune product_price_snapshots + supplier_offer_snapshots older than N days';

    protected function perform(): int
    {
        $flag = $this->option('days');

        // Mirror the Phase 7 Plan 02 / Phase 5 competitor-csv-prune precedent:
        // --days=0 is an explicit no-op safety guard so a typo can't wipe the
        // entire history table.
        if ($flag !== null && (int) $flag === 0) {
            $this->warn('--days=0 is a no-op safety guard. Exiting.');

            return SymfonyCommand::SUCCESS;
        }

        $days = $flag === null
            ? (int) config('history.retention_days', 90)
            : (int) $flag;

        if ($days < 1) {
            $this->error('--days must be >= 1.');

            return SymfonyCommand::FAILURE;
        }

        $threshold = now()->subDays($days)->startOfDay();

        $productDeleted = ProductPriceSnapshot::where('recorded_at', '<', $threshold)->delete();
        $offerDeleted = SupplierOfferSnapshot::where('recorded_at', '<', $threshold)->delete();

        $this->info(sprintf(
            'Pruned %d product_price_snapshots + %d supplier_offer_snapshots older than %d days.',
            $productDeleted,
            $offerDeleted,
            $days,
        ));

        return SymfonyCommand::SUCCESS;
    }
}
