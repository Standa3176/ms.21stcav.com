<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 7 Plan 04 Task 2 — WeeklyDigestMail (D-08, D-09).
 *
 * Delivered by `reports:weekly-digest` to every AlertRecipient where
 * `receives_weekly_digest=true`. Sibling pair of Blade views:
 *   HTML:  resources/views/emails/weekly-digest.blade.php
 *   Text:  resources/views/emails/weekly-digest-text.blade.php
 *
 * `Mail::markdown()` is intentionally NOT used (D-09) — brittle styling; the
 * ops-read digest needs locked-in HTML tables that render the same in
 * gmail / outlook / thunderbird without surprises.
 *
 * Subject convention (D-09): `MeetingStore Ops Weekly Digest — {YYYY-MM-DD}`
 * so ops clients can filter-rule the emails into a Digest folder.
 */
final class WeeklyDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MeetingStore Ops Weekly Digest — '.now()->format('Y-m-d'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly-digest',
            text: 'emails.weekly-digest-text',
            with: ['payload' => $this->payload],
        );
    }
}
