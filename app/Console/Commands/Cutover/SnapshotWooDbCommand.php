<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Cutover\Services\WooDbSnapshotter;

/**
 * Phase 7 Plan 05 Task 2 — CUT-04 Woo-DB snapshot artisan entry.
 *
 * Usage:
 *   php artisan cutover:snapshot-woo-db --label=pre-cutover
 *
 * Writes woo-db-backup-{YYYY-MM-DD-HHMMSS}-{label}.sql.gz to
 * config('cutover.backup_path') (default storage/app/cutover/backups).
 * Audit-logs the file metadata (filename + size_bytes).
 *
 * --label is REQUIRED so ops can't accidentally create a backup with no
 * reason attached. Fails fast with a clear error message if missing.
 */
class SnapshotWooDbCommand extends BaseCommand
{
    protected $signature = 'cutover:snapshot-woo-db
        {--label= : Reason for snapshot, REQUIRED (ops-supplied; e.g. pre-cutover, drill-run-2)}';

    protected $description = 'mysqldump + gzip the Woo WordPress DB (CUT-04, pre-cutover safety net)';

    public function perform(): int
    {
        $snapshotter = app(WooDbSnapshotter::class);
        $label = (string) $this->option('label');
        if ($label === '') {
            $this->error('--label is required. Provide an ops-meaningful reason '
                .'(e.g. --label=pre-cutover or --label=drill-run-2).');

            return self::FAILURE;
        }

        try {
            $result = $snapshotter->snapshot($label);
        } catch (\RuntimeException $e) {
            $this->error('Snapshot failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Snapshot written: %s (%d bytes)',
            $result['filename'],
            $result['size_bytes'],
        ));

        return self::SUCCESS;
    }
}
