<?php

declare(strict_types=1);

namespace App\Domain\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 4 Plan 03 D-12 — email alert fired when a CRM push exhausts its retry
 * chain (3 attempts / 30s / 5m / 30m per D-11) OR fails permanently on a 4xx.
 *
 * Routes through AlertDistribution::routeNotificationForCrmAlerts() — only
 * AlertRecipients with `receives_crm_alerts=true` receive this. The seeded
 * ops@meetingstore.co.uk fallback is always opted in (migration backfill).
 *
 * Body includes a link to the Filament Suggestions inbox so ops can review
 * the evidence payload + replay after fixing the underlying issue.
 */
final class CrmPushFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $wooOrderId,
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
            ->subject('[MeetingStore] CRM push failed for Woo Order #'.$this->wooOrderId)
            ->greeting('A CRM push has exhausted its retry chain.')
            ->line('Woo Order ID: #'.$this->wooOrderId)
            ->line('Correlation ID: '.($this->correlationId ?? 'unknown'))
            ->line('Error: '.$this->errorMessage)
            ->line('A `crm_push_failed` suggestion has been written. Review and replay via the admin Suggestions inbox.')
            ->action('Open Suggestions', url('/admin/suggestions'))
            ->line('This alert is de-duplicated for 5 minutes per Woo order (D-13 pattern).');
    }
}
