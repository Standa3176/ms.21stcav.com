<?php

declare(strict_types=1);

namespace App\Console\Commands\Cutover;

use App\Console\Commands\BaseCommand;
use App\Domain\Cutover\Services\OverridePopulator;

/**
 * Phase 7 Plan 05 Task 1 — CUT-02 ProductOverride populator artisan entry.
 *
 * Dry-run by default:
 *   php artisan cutover:populate-overrides
 *
 * --live persists ProductOverride rows (D-15 never-clear-pins merge):
 *   php artisan cutover:populate-overrides --live
 *
 * Reads the latest divergence-scan run (grouped by correlation_id) and
 * sets pin_* columns on ProductOverride for every field that diverged.
 */
class PopulateOverridesCommand extends BaseCommand
{
    protected $signature = 'cutover:populate-overrides
        {--live : Persist ProductOverride rows with pin_* set (default is dry-run)}';

    protected $description = 'Read latest divergence scan + populate ProductOverride pin_* columns (CUT-02)';

    public function perform(): int
    {
        $populator = app(OverridePopulator::class);
        $persist = (bool) $this->option('live');

        if (! $persist) {
            $this->warn('DRY-RUN — no ProductOverride rows will be written. Use --live to persist.');
        }

        $stats = $populator->populateFromScan(persist: $persist);

        $this->info(sprintf(
            'products_affected=%d overrides_created=%d overrides_merged=%d pins_set=%d',
            $stats['products_affected'],
            $stats['overrides_created'],
            $stats['overrides_merged'],
            $stats['pins_set'],
        ));

        return self::SUCCESS;
    }
}
