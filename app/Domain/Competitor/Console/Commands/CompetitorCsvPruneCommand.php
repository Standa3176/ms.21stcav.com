<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Foundation\Audit\Services\Auditor;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Phase 5 Plan 05 Task 1 — competitor:csv-prune (COMP-12).
 *
 * SCOPE: deletes files UNDER storage/app/competitors/archive/** whose mtime is
 * older than --days. Three resolution paths for the retention threshold:
 *
 *   1. `--days=N` (N >= 1)          → use N days
 *   2. `--days=0`                   → no-op safety guard (warn + exit 0)
 *   3. no flag                      → fall back to config('competitor.csv_retention_days', 90)
 *
 * The signature default is `null` so we can distinguish "flag not passed" from
 * "flag passed as 0". This matches the plan's `must_haves` truth list:
 *   • "Default retention days comes from `config('competitor.csv_retention_days', 90)`
 *      when --days=0 is passed; explicit --days=N overrides"
 *   • "--days=0 is a no-op safety guard" (per <behavior> block)
 *
 * Phase 2 precedent (PruneSyncErrorsCommand) uses a hard default of `90` and
 * treats `--days=0` as the safety guard; we deviate here because COMP-12 is
 * explicitly env-configurable via COMPETITOR_CSV_RETENTION_DAYS.
 *
 * HARD SCOPE LIMITS (COMP-07 mandate):
 *   - NEVER deletes rows from competitor_prices
 *   - NEVER deletes rows from competitor_ingest_runs
 *   - NEVER deletes rows from csv_parse_errors
 *   - NEVER touches files under competitors/incoming/, processing/, or quarantine/
 *   - Leaves `.gitkeep` sentinel files intact regardless of mtime
 *
 * AUDIT: every (non-no-op) invocation writes a `competitor.csv_pruned` meta-audit
 * entry via Auditor with {deleted_count, cutoff_date, days, archive_path}.
 * Retention enforcement is itself auditable (D-09).
 *
 * Scheduled daily at 03:40 Europe/London via routes/console.php
 * (onOneServer + withoutOverlapping(30)); the 03:40 slot continues the
 * 03:00/03:10/03:20/03:30 prune cascade established by Phases 1 + 2.
 *
 * Extends Illuminate\Console\Command (NOT BaseCommand) — consistent with the
 * other 4 prune commands (activitylog:prune, integration-events:prune,
 * sync-errors:prune, sync-diffs:prune). File-system only — no correlation_id
 * threading needed.
 */
class CompetitorCsvPruneCommand extends Command
{
    protected $signature = 'competitor:csv-prune {--days= : Retention in days; omit to use config default (90); 0 = no-op safety guard}';

    protected $description = 'Prune competitor CSV archive files older than retention threshold. NEVER touches competitor_prices rows (COMP-07).';

    public function handle(Auditor $auditor): int
    {
        $daysRaw = $this->option('days');

        // Flag explicitly passed as --days=0 → no-op safety guard.
        if ($daysRaw !== null && (int) $daysRaw === 0) {
            $this->warn('--days=0 is a no-op safety guard; pass a positive integer or omit the flag to use COMPETITOR_CSV_RETENTION_DAYS.');

            return self::SUCCESS;
        }

        // Flag explicitly passed as --days=N (N >= 1) OR no flag → config fallback.
        $days = $daysRaw !== null
            ? (int) $daysRaw
            : (int) config('competitor.csv_retention_days', 90);

        if ($days < 1) {
            // Config resolved to 0 or negative — treat as safety guard too.
            $this->warn(sprintf('Retention resolved to %d days; refusing to prune (set COMPETITOR_CSV_RETENTION_DAYS to a positive integer).', $days));

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $archivePath = storage_path('app/competitors/archive');

        if (! is_dir($archivePath)) {
            $this->info(sprintf('Archive directory does not exist: %s', $archivePath));
            $auditor->record('competitor.csv_pruned', [
                'deleted_count' => 0,
                'cutoff_date' => $cutoff->toIso8601String(),
                'days' => $days,
                'archive_path' => $archivePath,
            ]);

            return self::SUCCESS;
        }

        $deleted = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archivePath, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            if ($file->getFilename() === '.gitkeep') {
                continue; // sentinel; never prune
            }
            if ($file->getMTime() < $cutoff->timestamp) {
                if (@unlink($file->getPathname())) {
                    $deleted++;
                }
            }
        }

        $auditor->record('competitor.csv_pruned', [
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toIso8601String(),
            'days' => $days,
            'archive_path' => $archivePath,
        ]);

        $this->info(sprintf(
            'Pruned %d CSV file(s) older than %d days (cutoff %s).',
            $deleted,
            $days,
            $cutoff->toDateString()
        ));

        return self::SUCCESS;
    }
}
