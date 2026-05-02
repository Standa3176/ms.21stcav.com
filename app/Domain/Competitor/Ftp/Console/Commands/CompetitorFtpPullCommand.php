<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Ftp\Notifications\CompetitorFtpPullFailedNotification;
use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\CompetitorFtpSource;
use App\Domain\Competitor\Models\CsvParseError;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 11.1 Plan 01 — D-05 + D-06 + D-12 — competitor:ftp-pull command.
 *
 * Iterates active CompetitorFtpSource rows, downloads matching CSV files
 * into storage/app/competitors/incoming/, and exits — Phase 5's existing
 * competitor:watch (every 5 min) picks them up via the >30s mtime gate
 * and runs the unchanged parse → DB → margin analyser pipeline (D-11).
 *
 * --- Modes (D-06) ---
 *   - Default: dry-run. Lists what would be downloaded, never writes.
 *   - --live: actually downloads.
 *   - --source={ulid}: scope to one source (admin "Pull now" Action).
 *
 * --- Per-source pull algorithm (D-05) ---
 *   1. Build dynamic Flysystem disk via FtpSourceConnector::connect.
 *   2. List files in $source->base_path (deep:false).
 *   3. Skip files NOT matching $source->filename_pattern regex.
 *   4. Skip files with mtime <= $source->last_pulled_at (idempotency).
 *   5. Skip files larger than config('competitor.ftp.max_file_mb', 50) (DoS guard).
 *   6. Stream-download to .tmp file, then atomic rename to final filename.
 *   7. On success: update last_pulled_at + last_pull_status + reset consecutive_failures.
 *
 * --- Failure handling (D-12) ---
 *   - Atomic increment of `consecutive_failures` via $source->increment().
 *   - csv_parse_errors row written with issue_type='ftp_pull_failed' (D-10).
 *   - At threshold (default 3): is_active=false + dispatch notification.
 *   - Auditor::record('competitor_ftp_pull_disabled', ...).
 */
final class CompetitorFtpPullCommand extends BaseCommand
{
    protected $signature = 'competitor:ftp-pull '
        .'{--source= : ULID of a single source to pull (default: all active)} '
        .'{--live : Perform downloads (default: dry-run)}';

    protected $description = 'Pull competitor CSVs from configured FTP/SFTP/FTPS sources into storage/app/competitors/incoming/ (Phase 11.1).';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly FtpSourceConnector $connector,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $sourceId = $this->option('source');
        $dryRun = ! $this->option('live');

        $query = CompetitorFtpSource::query()->where('is_active', true);
        if ($sourceId !== null && $sourceId !== '') {
            $query = CompetitorFtpSource::query()->where('id', $sourceId);
        }

        $sources = $query->get();

        if ($sourceId !== null && $sourceId !== '' && $sources->isEmpty()) {
            $this->error("competitor:ftp-pull — no source found with id={$sourceId}.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'competitor:ftp-pull — %s — %d source(s) to process',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $sources->count()
        ));

        foreach ($sources as $source) {
            try {
                $this->processSource($source, $dryRun);
            } catch (Throwable $e) {
                $this->handleSourceFailure($source, $e);
            }
        }

        $this->auditor->record('competitor_ftp_pull', [
            'mode' => $dryRun ? 'dry-run' : 'live',
            'sources_processed' => $sources->count(),
        ]);

        return self::SUCCESS;
    }

    private function processSource(CompetitorFtpSource $source, bool $dryRun): void
    {
        $flysystem = $this->connector->connect($source);
        $pattern = $source->filename_pattern;
        $maxBytes = (int) config('competitor.ftp.max_file_mb', 50) * 1024 * 1024;
        $lastPulledAt = $source->last_pulled_at?->getTimestamp() ?? 0;

        $filesFetched = 0;
        $filesSkipped = 0;

        $this->line("  Source: {$source->name} ({$source->protocol}://{$source->host}:{$source->port}{$source->base_path})");

        foreach ($flysystem->listContents('', deep: false) as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $remoteFilename = basename($item->path());

            // Filename regex (D-05 step 3) + traversal guard (basename above).
            if (@preg_match($pattern, $remoteFilename) !== 1) {
                $filesSkipped++;
                continue;
            }

            // mtime gate (D-05 step 3 — skip already-processed files).
            $mtime = $item->lastModified() ?? 0;
            if ($mtime <= $lastPulledAt) {
                $filesSkipped++;
                continue;
            }

            // Size guard (D-05 step 4 + DoS).
            $size = $item->fileSize() ?? 0;
            if ($size > $maxBytes) {
                $filesSkipped++;
                Log::warning('competitor.ftp.file_too_large', [
                    'source_id' => $source->id,
                    'filename' => $remoteFilename,
                    'size_bytes' => $size,
                    'max_bytes' => $maxBytes,
                ]);
                continue;
            }

            if ($dryRun) {
                $this->line("    [DRY-RUN] would fetch {$remoteFilename} ({$size} bytes)");
                $filesFetched++;
                continue;
            }

            // --- Live path: stream-download with .tmp atomic rename (D-05 step 6) ---
            $localFilename = $this->normaliseFilename($source->competitor->slug, $remoteFilename);
            $incomingDir = storage_path('app'.DIRECTORY_SEPARATOR.'competitors'.DIRECTORY_SEPARATOR.'incoming');

            if (! is_dir($incomingDir)) {
                @mkdir($incomingDir, 0o775, true);
            }

            $tempPath = $incomingDir.DIRECTORY_SEPARATOR.$localFilename.'.tmp';
            $finalPath = $incomingDir.DIRECTORY_SEPARATOR.$localFilename;

            $remoteStream = $flysystem->readStream($item->path());
            $localFh = fopen($tempPath, 'wb');
            if ($localFh === false) {
                throw new \RuntimeException("Could not open local temp file: {$tempPath}");
            }
            stream_copy_to_stream($remoteStream, $localFh);
            fclose($localFh);
            if (is_resource($remoteStream)) {
                fclose($remoteStream);
            }

            if (! @rename($tempPath, $finalPath)) {
                @unlink($tempPath);
                throw new \RuntimeException("Atomic rename failed: {$tempPath} → {$finalPath}");
            }

            $this->line("    [OK] {$remoteFilename} → {$localFilename}");
            $filesFetched++;
        }

        if (! $dryRun) {
            $source->update([
                'last_pulled_at' => now(),
                'last_pull_status' => $filesFetched > 0
                    ? CompetitorFtpSource::STATUS_SUCCESS
                    : CompetitorFtpSource::STATUS_PARTIAL,
                'last_pull_files_fetched' => $filesFetched,
                'last_pull_error' => null,
                'consecutive_failures' => 0, // D-12 — reset on success
            ]);
        }

        $this->info("  → fetched={$filesFetched}, skipped={$filesSkipped}");
    }

    /**
     * D-05 step 5 — normalise remote filename to Phase 5 watcher convention.
     *
     * If the remote filename already matches Phase 5's `{slug}_{YYYY-MM-DD}.csv`
     * regex, copy as-is. Otherwise, fallback to `{competitor.slug}_{today}.csv`.
     */
    private function normaliseFilename(string $slug, string $remoteName): string
    {
        $phase5Pattern = '/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/';
        if (preg_match($phase5Pattern, $remoteName) === 1) {
            return $remoteName;
        }

        return sprintf('%s_%s.csv', $slug, now()->toDateString());
    }

    private function handleSourceFailure(CompetitorFtpSource $source, Throwable $e): void
    {
        $threshold = (int) config('competitor.ftp.consecutive_failures_threshold', 3);
        $message = substr($e->getMessage(), 0, 1000);

        // Atomic increment — single UPDATE; refresh to read the new value.
        $source->increment('consecutive_failures');
        $source->refresh();

        $source->update([
            'last_pulled_at' => now(),
            'last_pull_status' => CompetitorFtpSource::STATUS_FAILED,
            'last_pull_error' => $message,
            'last_pull_files_fetched' => 0,
        ]);

        // D-10 — write csv_parse_errors row so failures surface in the
        // existing Phase 5 CsvIngestIssuesPage tab without new UI.
        CsvParseError::create([
            'filename' => $source->name.' (FTP source)',
            'issue_type' => CsvParseError::TYPE_FTP_PULL_FAILED,
            'context' => [
                'source_id' => $source->id,
                'host' => $source->host,
                'protocol' => $source->protocol,
                'error' => $message,
                'consecutive_failures' => $source->consecutive_failures,
            ],
        ]);

        Log::error('competitor.ftp.pull_failed', [
            'source_id' => $source->id,
            'host' => $source->host,
            'error' => $message,
            'consecutive_failures' => $source->consecutive_failures,
        ]);

        $this->error("  [FAIL] {$source->name} ({$source->host}): {$message}");

        // D-12 — 3-strike auto-disable + notification.
        if ($source->consecutive_failures >= $threshold) {
            $source->update(['is_active' => false]);

            $recipients = AlertRecipient::query()
                ->where('is_active', true)
                ->where('receives_competitor_ftp_alerts', true)
                ->get();

            foreach ($recipients as $recipient) {
                $recipient->notify(new CompetitorFtpPullFailedNotification($source, $message));
            }

            $this->auditor->record('competitor_ftp_pull_disabled', [
                'source_id' => $source->id,
                'consecutive_failures' => $source->consecutive_failures,
                'recipients_notified' => $recipients->count(),
            ]);

            $this->warn("  → AUTO-DISABLED after {$source->consecutive_failures} consecutive failures; {$recipients->count()} recipient(s) notified.");
        }
    }
}
