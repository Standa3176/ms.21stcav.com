<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 7 Plan 03 — queued CSV export completion mail (D-06).
 *
 * Sent by QueuedCsvExportJob after the file has been fully written to
 * storage/app/exports and a temporarySignedRoute has been generated.
 *
 * Body carries:
 *   - the filename (also in subject for gmail/outlook rules)
 *   - the signed URL (7-day expiry per threat T-07-03-05 mitigation)
 *   - an approximate row count (operator sanity check)
 *
 * Plain-text fallback lives at emails/queued-csv-export-text.blade.php so
 * clients that render text-only still get the download link + filename.
 */
final class QueuedCsvExportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $filename,
        public readonly string $signedUrl,
        public readonly int $rowCountApprox,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "MeetingStore Ops — CSV export ready: {$this->filename}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.queued-csv-export',
            text: 'emails.queued-csv-export-text',
            with: [
                'filename' => $this->filename,
                'signedUrl' => $this->signedUrl,
                'rowCountApprox' => $this->rowCountApprox,
            ],
        );
    }
}
