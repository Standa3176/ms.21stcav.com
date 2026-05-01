<?php

declare(strict_types=1);

namespace App\Domain\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 11 Plan 04 — email alert fired when a quote-push exhausts its retry
 * chain (3 attempts / 30s / 5m / 30m per Phase 4 D-11 inherited shape) OR
 * fails permanently on a 4xx (BitrixPermanentException).
 *
 * Routes through AlertDistribution(onlyReceiving: 'receives_quote_alerts').
 * Plan 11-01 force-updated the seeded ops@meetingstore.co.uk fallback to
 * receives_quote_alerts=true so the Pitfall M "no active recipient" outage
 * cannot strand quote alerts. 5-minute Cache::add dedup mirrors Phase 4
 * CrmPushFailedNotification per quote_id.
 */
final class QuotePushFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $quoteId,
        public readonly string $quoteUlidShort,
        public readonly string $errorMessage,
        public readonly ?string $correlationId = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('[MeetingStore] Quote push failed for #'.$this->quoteUlidShort)
            ->greeting('A Phase 11 quote → Bitrix push has exhausted its retry chain.')
            ->line('Quote: #'.$this->quoteUlidShort.' (ULID: '.$this->quoteId.')')
            ->line('Correlation ID: '.($this->correlationId ?? 'unknown'))
            ->line('Error: '.$this->errorMessage)
            ->line('A `quote_push_failed` suggestion has been written. Review and replay via the admin Suggestions inbox.')
            ->action('Open Suggestions', url('/admin/suggestions'))
            ->line('This alert is de-duplicated for 5 minutes per quote (Phase 4 D-13 pattern).');
    }
}
