<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Notifications;

use App\Domain\Quotes\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 11 Plan 05 Task 1 — quote-has-expired customer email (QUOT-08 optional).
 *
 * Dispatched by QuotesExpireCommand when `config('quote.email_on_expiry')` is
 * TRUE. Default config flag is FALSE — operator opts in post-cutover after
 * observing v1 expiry volume (CONTEXT.md Claude's Discretion).
 *
 * Channel: mail-only — no Slack / SMS surface in v1.
 * Recipient: routed via `Notification::route('mail', $quote->customer_email)`
 * (the Quote model is not Notifiable in v1; customer_email is the canonical
 * destination since dual-mode customer (D-01) means user_id may be NULL for
 * anonymous-lead quotes).
 *
 * Threat model T-11-05-04 (PII to wrong recipient): the email captured at
 * Quote creation is the ONLY destination — the Mailable does not look up
 * any other contact channel. LogsActivity records the dispatch via the
 * Quote.expired_at + status transition (Plan 11-01 LogsActivity contract).
 */
final class QuoteExpiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Quote $quote,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $support = (string) config('mail.from.address', 'sales@meetingstore.co.uk');
        $companyName = (string) config('quote.company_name', 'MeetingStore');

        $name = $this->quote->customer_name ?: 'there';
        $ulidShort = $this->quote->ulidShort();
        $issuedAt = optional($this->quote->created_at)->toFormattedDateString() ?? '—';
        $expiredAt = optional($this->quote->expired_at)->toFormattedDateString()
            ?? optional(now())->toFormattedDateString();

        return (new MailMessage())
            ->subject("Your quote #{$ulidShort} from {$companyName} has expired")
            ->greeting("Hello {$name},")
            ->line("Your quote #{$ulidShort} (issued {$issuedAt}) expired on {$expiredAt}.")
            ->line('If you would still like to proceed, please reply to this email and the team will be happy to issue a fresh quote at current pricing.')
            ->line("Reach us any time at {$support}.")
            ->salutation("Kind regards,\n{$companyName} Sales Team");
    }
}
