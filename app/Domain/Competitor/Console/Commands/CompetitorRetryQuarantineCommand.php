<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Console\Commands\BaseCommand;
use Symfony\Component\Finder\Finder;

/**
 * Quick task 260504-e0q — operator command to replay quarantined competitor CSVs.
 *
 * CompetitorWatchCommand quarantines a file when it fails the filename regex
 * OR when its IngestCompetitorCsvJob fails (chunk-batch deadlock, parse error,
 * etc.). Quarantined files end up at storage/app/competitors/quarantine/<Y-m-d>/
 * with a sidecar <filename>.csv.error.json describing the failure.
 *
 * Pre-260504-e0q operators had to `cp` the file back to incoming/ manually.
 * This command does it cleanly:
 *
 *   php artisan competitor:retry-quarantine                      # list only
 *   php artisan competitor:retry-quarantine --all                # retry every file
 *   php artisan competitor:retry-quarantine --file=screenmoove.csv  # retry one
 *
 * After moving, operator runs `php artisan competitor:watch` (which observes
 * the 30s mtime gate before dispatching IngestCompetitorCsvJob).
 */
final class CompetitorRetryQuarantineCommand extends BaseCommand
{
    protected $signature = 'competitor:retry-quarantine
        {--all : Retry every quarantined file}
        {--file= : Retry only the matching filename (e.g. screenmoove_2026-01-01.csv)}';

    protected $description = 'List or replay competitor CSVs that landed in quarantine/ after ingest failure.';

    protected function perform(): int
    {
        $all = (bool) $this->option('all');
        $file = $this->option('file');

        if ($all && $file !== null) {
            $this->error('--all and --file are mutually exclusive.');

            return self::FAILURE;
        }

        $quarantineDir = storage_path('app/competitors/quarantine');
        $incomingDir = storage_path('app/competitors/incoming');

        if (! is_dir($quarantineDir)) {
            $this->info('No quarantine directory yet (storage/app/competitors/quarantine).');

            return self::SUCCESS;
        }

        if (! is_dir($incomingDir)) {
            @mkdir($incomingDir, 0o775, true);
        }

        // Note: Symfony Finder's name() is cumulative (OR) — call exactly once.
        $finder = (new Finder())
            ->files()
            ->in($quarantineDir)
            ->name($file !== null ? (string) $file : '*.csv');

        $count = $finder->count();

        if ($count === 0) {
            $this->info('0 quarantined files'.($file !== null ? " matching {$file}" : '').'.');

            return self::SUCCESS;
        }

        if (! $all && $file === null) {
            $this->info("{$count} quarantined file(s):");
            foreach ($finder as $f) {
                $errorPath = $f->getRealPath().'.error.json';
                $error = '';
                if (is_file($errorPath)) {
                    $payload = json_decode((string) @file_get_contents($errorPath), true);
                    $error = is_array($payload) ? (string) ($payload['error'] ?? $payload['message'] ?? '') : '';
                }
                $this->line(sprintf(
                    '  %s%s',
                    $f->getRelativePathname(),
                    $error !== '' ? ' — '.\Illuminate\Support\Str::limit($error, 100) : '',
                ));
            }
            $this->line('');
            $this->line('Use --all to retry every file, or --file=NAME to retry one.');

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;
        foreach ($finder as $f) {
            // Snapshot the source path BEFORE rename — getRealPath() returns
            // false / stale value once the underlying file has moved.
            $sourcePath = (string) $f->getRealPath();
            $sidecarPath = $sourcePath.'.error.json';
            $target = $incomingDir.DIRECTORY_SEPARATOR.$f->getFilename();

            if (file_exists($target)) {
                $this->warn("  skip {$f->getFilename()} — already exists in incoming/");
                $skipped++;
                continue;
            }
            if (! @rename($sourcePath, $target)) {
                $this->error("  failed to move {$f->getFilename()}");
                continue;
            }
            // Best-effort sidecar cleanup — not fatal if it fails.
            if (file_exists($sidecarPath)) {
                @unlink($sidecarPath);
            }
            $this->info("  moved {$f->getFilename()} → incoming/");
            $moved++;
        }

        $this->line('');
        $this->info("Done. moved={$moved} skipped={$skipped}");
        $this->line('Now run: php artisan competitor:watch (after 30s mtime gate).');

        return self::SUCCESS;
    }
}
