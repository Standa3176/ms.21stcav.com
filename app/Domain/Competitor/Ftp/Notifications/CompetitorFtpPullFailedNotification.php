<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Notifications;

use App\Domain\Competitor\Models\CompetitorFtpSource;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 11.1 Plan 01 — D-12 3-strike auto-disable email.
 *
 * Dispatched by CompetitorFtpPullCommand::handleSourceFailure() once a
 * source's `consecutive_failures` reaches the configured threshold
 * (default 3 — `config('competitor.ftp.consecutive_failures_threshold')`).
 *
 * Recipients are resolved at dispatch time via:
 *   AlertRecipient::query()->active()->receivesCompetitorFtpAlerts()->get()
 *
 * The seeded fallback row (ops@meetingstore.co.uk) is force-promoted TRUE
 * by the Phase 11.1 migration so Pitfall M "no active recipient" outage
 * cannot strand FTP failure alerts.
 */
class CompetitorFtpPullFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CompetitorFtpSource $source,
        public string $error,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[FTP Pull Failed] '.$this->source->name)
            ->line(
                'Competitor FTP source "'.$this->source->name.'" ('.$this->source->host.') has been '
                .'auto-disabled after '.$this->source->consecutive_failures.' consecutive pull failures.'
            )
            ->line('Last error: '.$this->error)
            ->line(
                'Re-enable in Filament once the upstream issue is resolved. '
                .'Use the "Test connection" Action to verify credentials before flipping is_active back on.'
            )
            ->action(
                'Review in Filament',
                url('/admin/competitor-ftp-sources/'.$this->source->id.'/edit')
            );
    }
}
