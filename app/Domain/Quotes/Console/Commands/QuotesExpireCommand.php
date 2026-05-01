<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Notifications\QuoteExpiredNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 11 Plan 05 Task 1 — quotes:expire (QUOT-08).
 *
 * Scheduled command that flips Quote.status from `sent` → `expired` for any
 * quote whose `expires_at` has passed. Runs daily at 00:30 (Europe/London) per
 * the schedule registered in routes/console.php.
 *
 * ── Cross-cutting invariant 3: dry-run-default CLI ──────────────────────────
 *
 * `--dry-run` is the DEFAULT (no flag = no DB writes). `--live` is the opt-in
 * flag that actually performs the status flip. The scheduler invokes
 * `quotes:expire --live` so the cron run mutates rows; ad-hoc operator runs
 * default to dry-run so a copy-paste doesn't accidentally expire a batch.
 *
 * ── Status filter ──────────────────────────────────────────────────────────
 *
 * Only `status = sent` quotes are touched. `draft` (still being edited),
 * `accepted` / `rejected` (sales bookkeeping locked), and `expired` (already
 * processed) are NEVER mutated — protects T-11-05-01 (wrong-status-flip
 * tampering threat).
 *
 * ── Customer email opt-in ───────────────────────────────────────────────────
 *
 * `config('quote.email_on_expiry', false)` is the gate. When TRUE, every
 * --live expiry sends a `QuoteExpiredNotification` to `customer_email`. Off by
 * default per CONTEXT.md Claude's Discretion — operator opts in post-cutover
 * after observing v1 expiry volume.
 *
 * ── Audit trail ─────────────────────────────────────────────────────────────
 *
 * Quote model's LogsActivity trait captures the status transition + the
 * expired_at timestamp into activity_log. BaseCommand threads correlation_id
 * via Context::add so the entire run is traceable end-to-end.
 *
 * ── Limit ───────────────────────────────────────────────────────────────────
 *
 * `--limit=1000` caps the per-run batch (defensive — protects against an
 * unbounded backlog scan if expiry gets accidentally turned off for weeks).
 * The (status, expires_at) composite index from Plan 11-01 makes the query
 * index-covered.
 */
final class QuotesExpireCommand extends BaseCommand
{
    protected $signature = 'quotes:expire
        {--dry-run : Show what would be expired (DEFAULT — no DB writes)}
        {--live : Actually flip status=sent → status=expired}
        {--limit=1000 : Maximum number of quotes processed per run}';

    protected $description = 'QUOT-08 — flip status=sent → status=expired for quotes past expires_at (dry-run by default; --live to mutate)';

    protected function perform(): int
    {
        $live = (bool) $this->option('live');
        $limit = max(1, (int) $this->option('limit'));
        $emailOnExpiry = (bool) config('quote.email_on_expiry', false);

        $candidates = Quote::query()
            ->where('status', Quote::STATUS_SENT)
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $count = $candidates->count();
        $mode = $live ? 'LIVE' : 'DRY-RUN';
        $this->info("Mode: {$mode} | Candidates: {$count} | Limit: {$limit} | Email on expiry: " . ($emailOnExpiry ? 'YES' : 'no'));

        if ($count === 0) {
            $this->line('No quotes to expire — nothing to do.');

            return self::SUCCESS;
        }

        $rows = [];
        $expired = 0;

        foreach ($candidates as $quote) {
            $statusBefore = (string) $quote->status;

            if ($live) {
                DB::transaction(function () use ($quote): void {
                    $quote->update([
                        'status' => Quote::STATUS_EXPIRED,
                        'expired_at' => now(),
                    ]);
                });

                if ($emailOnExpiry && filter_var($quote->customer_email, FILTER_VALIDATE_EMAIL)) {
                    try {
                        Notification::route('mail', $quote->customer_email)
                            ->notify(new QuoteExpiredNotification($quote->fresh()));
                    } catch (\Throwable $e) {
                        // Email failure should not abort the expiry flip — Quote.status is the
                        // canonical state; the email is best-effort. Log + continue.
                        Log::warning('QuotesExpireCommand: notification failed', [
                            'quote_id' => $quote->id,
                            'customer_email' => $quote->customer_email,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }

                $expired++;
                $rows[] = [
                    'ulid_short' => $quote->ulidShort(),
                    'customer_email' => $quote->customer_email,
                    'status_before' => $statusBefore,
                    'status_after' => Quote::STATUS_EXPIRED,
                    'expired_at' => (string) now(),
                ];
            } else {
                $rows[] = [
                    'ulid_short' => $quote->ulidShort(),
                    'customer_email' => $quote->customer_email,
                    'status_before' => $statusBefore,
                    'status_after' => 'WOULD BE expired',
                    'expired_at' => '(dry-run)',
                ];
            }
        }

        $this->table(
            ['ulid_short_8', 'customer_email', 'status_before', 'status_after', 'expired_at'],
            $rows,
        );

        $this->line('');
        if ($live) {
            $this->info("✓ {$expired} quote(s) expired (live=true).");
        } else {
            $this->warn("DRY-RUN — {$count} quote(s) would be expired. Re-run with --live to apply.");
        }

        return self::SUCCESS;
    }
}
