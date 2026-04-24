<?php

declare(strict_types=1);

namespace App\Console\Commands\Reports;

use App\Console\Commands\BaseCommand;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Dashboard\Models\DashboardSnapshot;
use App\Domain\Dashboard\Services\WeeklyDigestComposer;
use App\Mail\WeeklyDigestMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Phase 7 Plan 04 Task 2 — `reports:weekly-digest` (DASH-05 / D-08).
 *
 * Scheduled Monday 07:00 Europe/London (routes/console.php).
 *
 * Flow:
 *   1. Load AlertRecipients where `receives_weekly_digest=true AND is_active=true`.
 *   2. Compose the 5-section payload via WeeklyDigestComposer.
 *   3. Send WeeklyDigestMail to each recipient (Mail facade so tests can ::fake).
 *   4. Write `dashboard_snapshots.weekly_report_status` metric_key with
 *      {last_sent_at, recipient_count, next_run_iso} so Plan 07-02's
 *      WeeklyReportStatusWidget reflects the real last-sent timestamp.
 *
 * Empty-recipient guard: if no recipient opted in, log a warning and exit 0.
 * Raising an exception would trip Horizon's failed-job alerting on what is
 * actually a configuration state (admins may intentionally unsubscribe every
 * recipient during a maintenance window).
 *
 * Correlation-id threading comes from BaseCommand — every send + snapshot
 * write lands in the same spatie/activitylog batch so an ops trace shows
 * the full digest pipeline under one correlation_id.
 */
final class WeeklyDigestCommand extends BaseCommand
{
    /** @var string */
    protected $signature = 'reports:weekly-digest';

    /** @var string */
    protected $description = 'Compose + send the Monday weekly ops digest to opted-in AlertRecipients (DASH-05).';

    protected function perform(): int
    {
        /** @var WeeklyDigestComposer $composer */
        $composer = app(WeeklyDigestComposer::class);

        $recipients = AlertRecipient::query()
            ->where('receives_weekly_digest', true)
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            Log::warning('WeeklyDigestCommand: no AlertRecipient has receives_weekly_digest=true; skipping send.');
            $this->warn('No recipients opted in to receives_weekly_digest — digest not sent.');

            return self::SUCCESS;
        }

        $payload = $composer->compose();

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email, $recipient->name)
                ->send(new WeeklyDigestMail($payload));
        }

        DashboardSnapshot::upsertByKey('weekly_report_status', [
            'last_sent_at' => now()->toIso8601String(),
            'recipient_count' => $recipients->count(),
            'next_run_iso' => $this->nextMondaySeven(),
        ]);

        $this->info(sprintf(
            'Weekly digest sent to %d recipient(s).',
            $recipients->count(),
        ));

        return self::SUCCESS;
    }

    private function nextMondaySeven(): string
    {
        return \Carbon\CarbonImmutable::now('Europe/London')
            ->next(\Carbon\CarbonImmutable::MONDAY)
            ->setTime(7, 0)
            ->toIso8601String();
    }
}
