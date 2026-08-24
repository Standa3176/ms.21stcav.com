<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Listeners;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Events\CompetitorCsvIngested;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Competitor\Notifications\EmptyFeedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Quick task 260823-gua — an EMPTY competitor feed is a failure, not a
 * completion.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT WENT UNNOTICED FOR FIVE WEEKS
 *
 * Prod 2026-08-23: screenmoove had been publishing a 23-byte CSV (BOM +
 * header + one blank `,,` row) since 2026-07-22. Ten consecutive pulls, every
 * one `last_pull_status=success` with `consecutive_failures=0`. Ten ingests,
 * every one `status=completed`, `rows_total=1`, `rows_written=0`. Nothing
 * alerted, because every individual signal was genuinely healthy:
 *
 *   - the FTP pull DID succeed — the file arrived, it was simply empty
 *   - competitor:check-stale watches ingest RECENCY, and ingests were on time
 *   - an empty file is legitimate for a NEW competitor, so the ingest job
 *     treats header-only as an ordinary completion
 *
 * screenmoove held 176,132 of ~268,000 competitor price rows — 66% of all
 * competitor data. So roughly 3,400 published products silently priced against
 * five-week-old data, or fell through to the margin rules. Measured that day:
 * 92.2% of published products were matched to a competitor, but only 19.0% had
 * data under a fortnight old.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY A LISTENER AND NOT THE JOB
 *
 * IngestCompetitorCsvJob is FROZEN by Phase 11.2 D-07 (see
 * tests/Architecture/Phase5IngestUntouchedTest.php) — format normalisation
 * must stay upstream of it so Phase 5 only ever sees clean CSVs. The job
 * already emits CompetitorCsvIngested carrying rowsTotal and rowsWritten,
 * which is precisely what this needs, so the guard hangs off the event and the
 * frozen file stays untouched.
 *
 * REGRESSION ONLY: fires only when the competitor already holds price rows. A
 * brand-new source whose first file is empty stays quiet — the condition that
 * keeps this quiet enough to leave switched on.
 *
 * Best-effort throughout: a failure here must never disturb the ingest, which
 * has already completed successfully by the time the event fires.
 */
final class FlagEmptyFeedRegression
{
    public function handle(CompetitorCsvIngested $event): void
    {
        if ($event->rowsWritten > 0) {
            return;
        }

        try {
            $competitor = Competitor::find($event->competitorId);
            $run = CompetitorIngestRun::find($event->ingestRunId);

            if ($competitor === null || $run === null) {
                return;
            }

            $existing = CompetitorPrice::where('competitor_id', $competitor->id)->count();
            if ($existing === 0) {
                return; // new source — nothing to regress from
            }

            $run->update([
                'status' => CompetitorIngestRun::STATUS_FAILED,
                'error_message' => sprintf(
                    'Empty feed: %d row(s) read, 0 written, but this competitor already holds %d price rows. '
                    .'The pull succeeded - the source file itself carries no usable data.',
                    $event->rowsTotal,
                    $existing,
                ),
            ]);

            Log::warning('competitor.empty_feed_regression', [
                'competitor_id' => $competitor->id,
                'competitor' => $competitor->name,
                'ingest_run_id' => $run->id,
                'filename' => $event->filename,
                'rows_total' => $event->rowsTotal,
                'existing_price_rows' => $existing,
            ]);

            $recipients = AlertRecipient::query()
                ->where('is_active', true)
                ->where('receives_competitor_alerts', true)
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new EmptyFeedNotification($competitor, $run, $existing));
            }
        } catch (Throwable $e) {
            Log::warning('competitor.empty_feed_guard_failed', [
                'ingest_run_id' => $event->ingestRunId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
