<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Notifications;

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorIngestRun;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Quick task 260823-gua — a competitor feed that arrived EMPTY.
 *
 * Born from a five-week silent failure found on prod 2026-08-23. screenmoove
 * published a 23-byte CSV (BOM + header + one blank row) from 2026-07-22
 * onward. Every pull succeeded, every ingest reported `completed`, and every
 * one wrote zero rows. Nothing alerted, because:
 *
 *   - the FTP pull WAS successful (last_pull_status=success, 0 failures)
 *   - the ingest WAS recent, so competitor:check-stale saw a healthy feed —
 *     it watches ingest RECENCY, and the ingests were bang on time
 *   - an empty file is a legitimate state for a brand-new competitor, so the
 *     ingest job treated header-only as an ordinary completion
 *
 * Meanwhile screenmoove was 66% of all competitor price data, so roughly 3,400
 * published products quietly fell back to pricing against five-week-old
 * competitor data. The undercut logic never knew.
 *
 * This fires only on a REGRESSION — a competitor that previously had prices
 * and has now sent nothing — so onboarding a new source stays quiet.
 */
final class EmptyFeedNotification extends Notification
{
    public function __construct(
        public readonly Competitor $competitor,
        public readonly CompetitorIngestRun $run,
        public readonly int $existingPriceRows,
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
        $actionUrl = url(sprintf(
            '/admin/competitor-ingest-runs?tableFilters[competitor_id][value]=%d',
            (int) $this->competitor->id,
        ));

        return (new MailMessage)
            ->subject(sprintf('[MS Ops] EMPTY competitor feed: %s', $this->competitor->name))
            ->line(sprintf(
                'Competitor "%s" delivered a file with no usable rows — the ingest completed but wrote nothing.',
                $this->competitor->name,
            ))
            ->line(sprintf('File: %s', (string) $this->run->filename))
            ->line(sprintf('Rows read: %d, rows written: 0', (int) $this->run->rows_total))
            ->line(sprintf(
                'This competitor already holds %s price rows, so an empty file is a REGRESSION, not a new feed.',
                number_format($this->existingPriceRows),
            ))
            ->line('Until it is fixed, products priced against this competitor fall back to stale data or the margin rules.')
            ->action('View Ingest Runs', $actionUrl)
            ->line('Check the source file at the supplier end — the pull itself succeeded.');
    }
}
