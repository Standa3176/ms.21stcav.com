<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Ftp\Notifications\CompetitorFtpPullFailedNotification;
use App\Domain\Competitor\Ftp\Services\FeedFormatNormaliser;
use App\Domain\Competitor\Ftp\Services\FtpSourceConnector;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use App\Domain\Competitor\Models\CsvParseError;
use App\Foundation\Audit\Services\Auditor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 11.2 Plan 01 — D-12 + D-13 multi-feed FTP pull command.
 *
 * Refactored from Phase 11.1's per-source iteration. Now iterates
 * `competitor_ftp_feeds` rows (auto-increment integer PK matching the
 * operator screenshot's IDs) and pulls one file per active feed.
 *
 * --- Modes ---
 *   - Default: dry-run. Lists what would be pulled, never writes.
 *   - --live: actually downloads via FtpSourceConnector + FeedFormatNormaliser.
 *   - --feed={id}: scope to one feed by integer PK.
 *   - --credential={ulid}: scope to all feeds sharing one credential.
 *   - --feed and --credential are MUTUALLY EXCLUSIVE — error if both passed.
 *
 * --- Per-feed pull algorithm (D-13) ---
 *   1. Build dynamic Flysystem disk via FtpSourceConnector::connect($feed->credential).
 *   2. Check remote mtime via $disk->lastModified($feed->remote_filename).
 *      If equal to $feed->remote_file_date, set last_pull_status='no_change' + skip.
 *   3. Stream-download to temp file.
 *   4. Run FeedFormatNormaliser::normalise() → returns canonical CSV path.
 *   5. Atomic move into storage/app/competitors/incoming/{local_filename} via
 *      .tmp + rename (Phase 5 watcher's >30s mtime gate filters partial pulls).
 *   6. Update last_pulled_at, remote_file_date, last_pull_status='success',
 *      reset consecutive_failures=0.
 *   7. On failure: increment consecutive_failures; if ≥3 → is_active=false +
 *      dispatch CompetitorFtpPullFailedNotification.
 *   8. Auditor::record('competitor_ftp_feed_pull', ...).
 *   9. Failures write csv_parse_errors row with issue_type='ftp_pull_failed'.
 */
final class CompetitorFtpPullCommand extends BaseCommand
{
    protected $signature = 'competitor:ftp-pull '
        .'{--feed= : Integer ID of a single feed to pull (default: all active)} '
        .'{--credential= : ULID of a credential — pulls all feeds sharing it} '
        .'{--live : Perform downloads (default: dry-run)}';

    protected $description = 'Pull competitor CSVs from configured FTP/SFTP/FTPS feeds (Phase 11.2 multi-feed).';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly FtpSourceConnector $connector,
        private readonly FeedFormatNormaliser $normaliser,
    ) {
        parent::__construct();
    }

    protected function perform(): int
    {
        $feedId = $this->option('feed');
        $credentialId = $this->option('credential');
        $dryRun = ! $this->option('live');

        if (filled($feedId) && filled($credentialId)) {
            $this->error('competitor:ftp-pull — pass either --feed or --credential, not both.');

            return self::FAILURE;
        }

        $query = CompetitorFtpFeed::query()
            ->with(['credential', 'competitor'])
            ->where('is_active', true);

        if (filled($feedId)) {
            // Reset to allow scoping to a single feed regardless of is_active.
            $query = CompetitorFtpFeed::query()
                ->with(['credential', 'competitor'])
                ->where('id', (int) $feedId);
        } elseif (filled($credentialId)) {
            $query->where('credential_id', $credentialId);
        }

        $feeds = $query->orderBy('id')->get();

        $this->info(sprintf(
            'competitor:ftp-pull — %s — %d feed(s) to process',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $feeds->count()
        ));

        foreach ($feeds as $feed) {
            try {
                $this->pullOne($feed, $dryRun);
            } catch (Throwable $e) {
                $this->handleFailure($feed, $e);
            }
        }

        $this->auditor->record('competitor_ftp_pull', [
            'mode' => $dryRun ? 'dry-run' : 'live',
            'feeds_processed' => $feeds->count(),
        ]);

        return self::SUCCESS;
    }

    private function pullOne(CompetitorFtpFeed $feed, bool $dryRun): void
    {
        $disk = $this->connector->connect($feed->credential);

        // D-13 step 2 — skip-on-no-change gate.
        // Many older FTP daemons (ProFTPd / Pure-FTPd in default config) implement
        // LIST but reject MDTM. Flysystem's lastModified() uses MDTM and throws
        // UnableToRetrieveMetadata on those servers. Treat that as "mtime unknown,
        // always download" — costs one redundant fetch per cycle (typically <50KB
        // CSV, twice-weekly cadence) and unblocks otherwise-correct configurations.
        try {
            $remoteMtime = $disk->lastModified($feed->remote_filename);
        } catch (\League\Flysystem\UnableToRetrieveMetadata $e) {
            $remoteMtime = null;
        }
        if ($remoteMtime !== null
            && $feed->remote_file_date !== null
            && $remoteMtime === $feed->remote_file_date->timestamp) {
            $feed->update([
                'last_pull_status' => CompetitorFtpFeed::STATUS_NO_CHANGE,
                'last_pulled_at' => now(),
                'last_pull_error' => null,
            ]);
            $this->line("  no_change feed_id={$feed->id} {$feed->local_filename}");

            return;
        }

        if ($dryRun) {
            $this->line(sprintf(
                '    [DRY-RUN] feed_id=%d would pull %s → %s',
                $feed->id,
                $feed->remote_filename,
                $feed->local_filename
            ));

            return;
        }

        // D-13 step 3 — stream-download to temp file.
        $tempPath = tempnam(sys_get_temp_dir(), 'feed_pull_');
        $remoteStream = $disk->readStream($feed->remote_filename);
        $localFh = fopen($tempPath, 'wb');
        if ($localFh === false) {
            throw new \RuntimeException("Could not open local temp file: {$tempPath}");
        }
        stream_copy_to_stream($remoteStream, $localFh);
        fclose($localFh);
        if (is_resource($remoteStream)) {
            fclose($remoteStream);
        }

        // D-13 step 4 — normalise to canonical CSV.
        $normalisedPath = $this->normaliser->normalise(
            $tempPath,
            $feed->format,
            $feed->local_filename
        );

        // D-13 step 5 — atomic move into incoming/.
        $incomingDir = storage_path('app'.DIRECTORY_SEPARATOR.'competitors'.DIRECTORY_SEPARATOR.'incoming');
        if (! is_dir($incomingDir)) {
            @mkdir($incomingDir, 0o775, true);
        }

        $finalPath = $incomingDir.DIRECTORY_SEPARATOR.$feed->local_filename;
        $tmpIncoming = $finalPath.'.tmp';

        if (! @copy($normalisedPath, $tmpIncoming)) {
            throw new \RuntimeException("Failed to copy normalised CSV → {$tmpIncoming}");
        }
        if (! @rename($tmpIncoming, $finalPath)) {
            @unlink($tmpIncoming);
            throw new \RuntimeException("Atomic rename failed: {$tmpIncoming} → {$finalPath}");
        }

        // D-13 step 6 — success.
        // remote_file_date falls back to now() when MDTM was unavailable; the
        // column is informational only (the watcher uses local file mtime).
        $feed->update([
            'last_pulled_at' => now(),
            'remote_file_date' => $remoteMtime !== null
                ? Carbon::createFromTimestamp($remoteMtime)
                : now(),
            'last_pull_status' => CompetitorFtpFeed::STATUS_SUCCESS,
            'last_pull_error' => null,
            'consecutive_failures' => 0,
        ]);

        $this->auditor->record('competitor_ftp_feed_pull', [
            'feed_id' => $feed->id,
            'competitor_id' => $feed->competitor_id,
            'remote_filename' => $feed->remote_filename,
            'local_filename' => $feed->local_filename,
        ]);

        $this->line("    [OK] feed_id={$feed->id} {$feed->remote_filename} → {$feed->local_filename}");
    }

    private function handleFailure(CompetitorFtpFeed $feed, Throwable $e): void
    {
        $threshold = (int) config('competitor.ftp.consecutive_failures_threshold', 3);
        $message = substr($e->getMessage(), 0, 1000);

        // Atomic increment — single UPDATE; refresh to read the new value.
        $feed->increment('consecutive_failures');
        $feed->refresh();

        $feed->update([
            'last_pulled_at' => now(),
            'last_pull_status' => CompetitorFtpFeed::STATUS_FAILED,
            'last_pull_error' => $message,
        ]);

        // D-13 step 9 — write csv_parse_errors row so failures surface in the
        // existing Phase 5 CsvIngestIssuesPage tab without new UI.
        CsvParseError::create([
            'filename' => $feed->local_filename,
            'competitor_id' => $feed->competitor_id,
            'issue_type' => CsvParseError::TYPE_FTP_PULL_FAILED,
            'context' => [
                'feed_id' => $feed->id,
                'competitor_id' => $feed->competitor_id,
                'credential_id' => $feed->credential_id,
                'remote_filename' => $feed->remote_filename,
                'local_filename' => $feed->local_filename,
                'error' => $message,
                'consecutive_failures' => $feed->consecutive_failures,
            ],
        ]);

        Log::error('competitor.ftp.feed_pull_failed', [
            'feed_id' => $feed->id,
            'remote_filename' => $feed->remote_filename,
            'error' => $message,
            'consecutive_failures' => $feed->consecutive_failures,
        ]);

        $this->error("  [FAIL] feed_id={$feed->id} {$feed->local_filename}: {$message}");

        // D-13 step 7 — 3-strike auto-disable + notification.
        if ($feed->consecutive_failures >= $threshold) {
            $feed->update(['is_active' => false]);

            $recipients = AlertRecipient::query()
                ->where('is_active', true)
                ->where('receives_competitor_ftp_alerts', true)
                ->get();

            foreach ($recipients as $recipient) {
                $recipient->notify(new CompetitorFtpPullFailedNotification($feed, $message));
            }

            $this->auditor->record('competitor_ftp_feed_disabled', [
                'feed_id' => $feed->id,
                'consecutive_failures' => $feed->consecutive_failures,
                'recipients_notified' => $recipients->count(),
                'reason' => 'consecutive_failures_threshold',
            ]);

            $this->warn("  → AUTO-DISABLED feed_id={$feed->id} after {$feed->consecutive_failures} consecutive failures; {$recipients->count()} recipient(s) notified.");
        }
    }
}
