<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Competitor\Jobs\IngestCompetitorCsvJob;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CsvParseError;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 Plan 02 Task 2 — scheduled CSV watcher (COMP-01 + COMP-04).
 *
 * Runs every 5 minutes via routes/console.php. For each *.csv file in
 * storage/app/competitors/incoming/:
 *
 *   1. mtime gate: skip if filemtime() > now - 30s (Pitfall P5-C — uses
 *      filemtime NOT filectime to work on Windows dev where ctime isn't
 *      POSIX ctime).
 *   2. Filename regex: `^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$`.
 *      Non-match → move to quarantine/ + write invalid_filename CsvParseError.
 *   3. Resolve Competitor via firstOrCreate(slug=prefix) — unknown slug
 *      creates a status=pending row (D-01 first-sighting auto-discovery).
 *   4. Atomic rename incoming/ → processing/ BEFORE dispatch — double-
 *      processing safe (a second worker's rename will fail silently and
 *      skip the file).
 *   5. Dispatch IngestCompetitorCsvJob on the competitor-csv queue.
 *
 * BaseCommand wraps perform() with Context::add('correlation_id', ...) so
 * the UUID threads through the run + every chunk job + every downstream
 * Suggestion.
 */
final class CompetitorWatchCommand extends BaseCommand
{
    protected $signature = 'competitor:watch';

    protected $description = 'Watch storage/app/competitors/incoming/ and dispatch IngestCompetitorCsvJob per aged + valid CSV';

    protected function perform(): int
    {
        $incomingDir = storage_path('app/competitors/incoming');
        if (! is_dir($incomingDir)) {
            @mkdir($incomingDir, 0o775, true);
            $this->line('incoming/ directory created; nothing to process.');

            return self::SUCCESS;
        }

        $files = glob($incomingDir.DIRECTORY_SEPARATOR.'*.csv') ?: [];
        $filenameRegex = (string) config('competitor.filename_regex', '/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/');

        $dispatched = 0;
        foreach ($files as $path) {
            $filename = basename($path);

            // ── Defensive: skip any .tmp file that slipped past the glob ──
            if (str_ends_with($filename, '.tmp')) {
                continue;
            }

            // ── mtime gate (Pitfall P5-C — 30s inbound-write debounce) ──
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > time() - 30) {
                continue;
            }

            // ── Filename regex + traversal guard ──
            if (preg_match($filenameRegex, $filename) !== 1) {
                $this->handleInvalidFilename($path, $filename);
                continue;
            }

            // Parse slug prefix; the regex ensures we have one underscore + a date part.
            if (preg_match('/^([a-z0-9_-]+?)_(\d{4}-\d{2}-\d{2})\.csv$/', $filename, $m) !== 1) {
                $this->handleInvalidFilename($path, $filename);
                continue;
            }
            $slug = $m[1];

            $competitor = Competitor::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'status' => Competitor::STATUS_PENDING],
            );

            // ── Atomic rename → processing/ ──
            $processingDir = storage_path('app/competitors/processing');
            if (! is_dir($processingDir)) {
                @mkdir($processingDir, 0o775, true);
            }
            $destPath = $processingDir.DIRECTORY_SEPARATOR.$filename;
            if (! @rename($path, $destPath)) {
                // Another worker grabbed it OR rename failed — log at info and move on.
                Log::info('competitor.watch_rename_skipped', [
                    'path' => $path,
                    'dest' => $destPath,
                ]);
                continue;
            }

            IngestCompetitorCsvJob::dispatch($destPath, $competitor->id)->onQueue('competitor-csv');
            $this->line("Dispatched {$filename} for competitor={$slug} (id={$competitor->id})");
            $dispatched++;
        }

        $this->info("competitor:watch — dispatched {$dispatched} file(s).");

        return self::SUCCESS;
    }

    private function handleInvalidFilename(string $path, string $filename): void
    {
        CsvParseError::create([
            'filename' => $filename,
            'issue_type' => CsvParseError::TYPE_INVALID_FILENAME,
            'context' => ['path' => $path],
        ]);

        $destDir = storage_path('app/competitors/quarantine/'.now()->format('Y-m-d'));
        if (! is_dir($destDir)) {
            @mkdir($destDir, 0o775, true);
        }
        @rename($path, $destDir.DIRECTORY_SEPARATOR.$filename);
        Log::warning('competitor.watch_invalid_filename', ['filename' => $filename, 'path' => $path]);
    }
}
