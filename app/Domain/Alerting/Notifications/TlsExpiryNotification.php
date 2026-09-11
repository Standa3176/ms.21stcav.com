<?php

declare(strict_types=1);

namespace App\Domain\Alerting\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Quick task 260911-t80 — a certificate has expired, is about to, or the host
 * cannot be reached.
 *
 * Written to be actionable at a glance on a phone, because the window between
 * "expiring" and "customers see a security warning" is measured in days and
 * the fix lives in a control panel somebody has to open.
 */
final class TlsExpiryNotification extends Notification
{
    /**
     * @param  array<int, array{host:string, days:int, expires_at:string}>  $expired
     * @param  array<int, array{host:string, days:int, expires_at:string}>  $expiring
     * @param  array<int, array{host:string, error:string}>  $unreachable
     */
    public function __construct(
        public readonly array $expired,
        public readonly array $expiring,
        public readonly array $unreachable,
        public readonly int $warnDays,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage;

        // The subject carries the severity, because that is all most people
        // read before deciding whether to open it now or after lunch.
        $mail->subject($this->expired !== []
            ? 'URGENT: TLS certificate EXPIRED — '.$this->expired[0]['host']
            : 'TLS certificate expiring soon');

        if ($this->expired !== []) {
            $mail->error();
            $mail->line('**A certificate has expired. Visitors are seeing a browser security warning.**');
            foreach ($this->expired as $r) {
                $mail->line(sprintf('- %s — expired %d day(s) ago (%s)', $r['host'], abs($r['days']), $r['expires_at']));
            }
        }

        if ($this->expiring !== []) {
            $mail->line(sprintf('Expiring within %d days:', $this->warnDays));
            foreach ($this->expiring as $r) {
                $mail->line(sprintf('- %s — %d day(s) left (%s)', $r['host'], $r['days'], $r['expires_at']));
            }
        }

        if ($this->unreachable !== []) {
            $mail->line('Could not be checked:');
            foreach ($this->unreachable as $r) {
                $mail->line(sprintf('- %s — %s', $r['host'], $r['error']));
            }
        }

        $mail->line('');
        $mail->line('**Renewing:** certbot-issued domains renew on their own systemd timer. '
            .'The storefront is managed by CWP AutoSSL instead — renew it from '
            .'WebServer Settings → SSL Certificates → Let\'s Encrypt Manager.');
        $mail->line('If issuance fails, check that /.well-known/acme-challenge/ is reachable — '
            .'a WordPress rewrite swallowing it blocks validation.');

        return $mail;
    }
}
