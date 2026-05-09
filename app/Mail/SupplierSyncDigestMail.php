<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily post-supplier-sync digest. Delivered by `reports:supplier-sync-digest`
 * to every AlertRecipient where receives_sync_reports=true.
 *
 * Replaces the legacy WP plugin's send_results_and_cleanup() flow — same intent
 * (yesterday's price/stock/pending churn at a glance) but the 4 CSV attachments
 * are inlined as HTML tables so ops doesn't need to download to read.
 */
final class SupplierSyncDigestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  shape from SupplierSyncDigestComposer::compose()
     */
    public function __construct(public array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MeetingStore Ops Supplier Sync Digest — '.now()->format('Y-m-d'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier-sync-digest',
            with: ['payload' => $this->payload],
        );
    }
}
