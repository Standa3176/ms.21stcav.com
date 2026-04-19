<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Notifications;

use App\Domain\Competitor\Models\Competitor;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 5 Plan 04b — stale competitor-feed mailable (COMP-11).
 *
 * Dispatched by CompetitorCheckStaleCommand once per (competitor, day) to
 * AlertRecipient rows where receives_competitor_alerts = true AND is_active =
 * true. The subject + body carry the competitor name + hours-since-last-ingest
 * (or "No ingest recorded") + an action URL that deep-links to the
 * CompetitorIngestRunResource filtered to that competitor.
 *
 * Uses the mail channel only — Slack is deferred per Phase 1 D-10 (Slack
 * routing disabled at the Notifiable layer).
 */
final class StaleFeedNotification extends Notification
{
    public function __construct(
        public readonly Competitor $competitor,
        public readonly ?int $hoursStale,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $staleMsg = $this->hoursStale !== null
            ? sprintf('%d hours since last ingest', $this->hoursStale)
            : 'No ingest recorded';

        $lastIngest = $this->competitor->last_ingest_at?->toDateTimeString() ?? 'never';

        $actionUrl = url(sprintf(
            '/admin/competitor-ingest-runs?tableFilters[competitor_id][value]=%d',
            (int) $this->competitor->id,
        ));

        return (new MailMessage)
            ->subject(sprintf('[MS Ops] Stale competitor feed: %s', $this->competitor->name))
            ->line(sprintf('Competitor "%s" has not reported new prices: %s.', $this->competitor->name, $staleMsg))
            ->line(sprintf('Last ingest: %s', $lastIngest))
            ->action('View Ingest Runs', $actionUrl)
            ->line('Check the n8n workflow if this is unexpected.');
    }
}
