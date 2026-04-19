<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Notifications\StaleFeedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 5 Plan 04b — hourly stale-feed detector (COMP-11).
 *
 * Query scope:
 *   status = active AND is_active = true AND (
 *      last_ingest_at IS NULL OR last_ingest_at < NOW() - INTERVAL {cfg}h
 *   )
 * where {cfg} is `config('competitor.stale_feed_hours', 48)`.
 *
 * Dedup: Cache::add('competitor.stale_alert.{id}.{YYYY-MM-DD}', true, 24h).
 * A same-day re-run for the same competitor returns false from Cache::add
 * and the notification is skipped. This tolerates the hourly schedule without
 * alert-fatiguing ops.
 *
 * Recipients: AlertRecipient rows where receives_competitor_alerts = true
 * AND is_active = true (05-01 + 05-04a scope). Routes via Laravel's
 * Notification::send which walks the Notifiable trait on AlertRecipient.
 *
 * Scheduled hourly in routes/console.php with ->onOneServer() so multi-worker
 * deployments only fire once per hour regardless of replica count.
 */
final class CompetitorCheckStaleCommand extends BaseCommand
{
    protected $signature = 'competitor:check-stale';

    protected $description = 'Check for stale competitor feeds and notify AlertRecipient subscribers (hourly, 24h dedup).';

    protected function perform(): int
    {
        $thresholdHours = (int) config('competitor.stale_feed_hours', 48);

        $stale = Competitor::query()
            ->where('status', Competitor::STATUS_ACTIVE)
            ->where('is_active', true)
            ->where(function ($q) use ($thresholdHours): void {
                $q->whereNull('last_ingest_at')
                    ->orWhere('last_ingest_at', '<', now()->subHours($thresholdHours));
            })
            ->get();

        $today = now()->format('Y-m-d');
        $notified = 0;

        foreach ($stale as $competitor) {
            $dedupKey = sprintf('competitor.stale_alert.%d.%s', $competitor->id, $today);

            // Cache::add is atomic: returns true ONLY if the key was absent.
            if (! Cache::add($dedupKey, true, now()->addHours(24))) {
                continue;
            }

            $hoursStale = $competitor->last_ingest_at !== null
                ? (int) $competitor->last_ingest_at->diffInHours(now())
                : null;

            $recipients = AlertRecipient::query()
                ->where('is_active', true)
                ->where('receives_competitor_alerts', true)
                ->get();

            if ($recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new StaleFeedNotification($competitor, $hoursStale));
            $notified++;
        }

        $this->info(sprintf(
            'Checked %d stale competitor(s); dispatched %d notification batch(es).',
            $stale->count(),
            $notified,
        ));

        return 0;
    }
}
