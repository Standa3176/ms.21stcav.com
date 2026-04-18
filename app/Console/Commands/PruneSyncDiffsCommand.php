<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sync\Models\SyncDiff;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Console\Command;

/**
 * D-08 conditional prune + Pitfall L regression guard.
 *
 * Behaviour:
 *   - WOO_WRITE_ENABLED=false (pre-Phase-7 cutover): NEVER PRUNE.
 *     sync_diffs is the parity evidence for the cutover gate — destroying
 *     it before the flag flips would eliminate the ability to verify that
 *     shadow writes match what the old plugins would have written.
 *   - WOO_WRITE_ENABLED=true (post-cutover): prune applied rows older than
 *     30 days. Pending (un-applied) rows stay indefinitely for investigation.
 *
 * The flag is read at runtime (not at container boot) so toggling the env
 * var mid-run takes effect on the next scheduled prune.
 *
 * D-09: meta-audit row on both the skipped and executed paths.
 */
class PruneSyncDiffsCommand extends Command
{
    protected $signature = 'sync-diffs:prune';

    protected $description = 'Prune sync_diffs (no-op while WOO_WRITE_ENABLED=false; 30-day applied retention after cutover per D-08)';

    public function handle(Auditor $auditor): int
    {
        // Pitfall L: never prune while shadow mode is active
        if (! (bool) config('services.woo.write_enabled', false)) {
            $auditor->record('sync-diffs.prune.skipped', [
                'reason' => 'WOO_WRITE_ENABLED is false; diffs are parity evidence for Phase 7 cutover.',
            ]);

            $this->info('Skipped — WOO_WRITE_ENABLED=false, preserving parity evidence.');

            return self::SUCCESS;
        }

        // Post-cutover: 30-day retention for APPLIED diffs; un-applied stay
        $cutoff = now()->subDays(30);
        $deleted = SyncDiff::where('created_at', '<', $cutoff)
            ->whereNotNull('applied_at')
            ->delete();

        $auditor->record('sync-diffs.pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
        ]);

        $this->info("Pruned {$deleted} applied sync_diffs rows older than 30 days.");

        return self::SUCCESS;
    }
}
