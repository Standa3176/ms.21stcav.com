<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Ftp\Notifications;

use App\Domain\Competitor\Models\CompetitorFtpFeed;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 11.2 Plan 01 — feed-level 3-strike auto-disable email.
 *
 * Refactored from Phase 11.1's source-level shape — now takes a
 * CompetitorFtpFeed (one row per remote file) instead of a CompetitorFtpSource.
 *
 * Dispatched by CompetitorFtpPullCommand::handleFailure() once a feed's
 * `consecutive_failures` reaches the configured threshold (default 3 —
 * `config('competitor.ftp.consecutive_failures_threshold')`).
 *
 * Recipients are resolved at dispatch time via:
 *   AlertRecipient::query()->active()->receivesCompetitorFtpAlerts()->get()
 *
 * The seeded fallback row (ops@meetingstore.co.uk) is force-promoted TRUE
 * by Phase 11.1's migration so Pitfall M "no active recipient" outage
 * cannot strand FTP failure alerts.
 */
class CompetitorFtpPullFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CompetitorFtpFeed $feed,
        public string $error,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $supplier = $this->feed->competitor?->name ?? 'unknown';
        $remote = $this->feed->remote_filename;
        $heading = "{$supplier} / {$remote}";

        return (new MailMessage)
            ->subject('[FTP Pull Failed] '.$heading)
            ->line(
                "Competitor FTP feed \"{$heading}\" has been auto-disabled after "
                .$this->feed->consecutive_failures.' consecutive pull failures.'
            )
            ->line("Feed ID: {$this->feed->id}")
            ->line("Local filename: {$this->feed->local_filename}")
            ->line('Last error: '.$this->error)
            ->line(
                'Re-enable in Filament once the upstream issue is resolved. '
                .'Use the "Test connection" Action on the credential to verify reachability '
                .'before flipping is_active back on.'
            )
            ->action(
                'Review in Filament',
                url('/admin/competitor-ftp-feeds/'.$this->feed->id.'/edit')
            );
    }
}
