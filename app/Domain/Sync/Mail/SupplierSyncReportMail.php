<?php

declare(strict_types=1);

namespace App\Domain\Sync\Mail;

use App\Domain\Sync\Models\SyncRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 2 Plan 02-04 — D-08/D-10 supplier sync report email.
 *
 * Envelope subject distinguishes aborted vs completed runs so ops can filter
 * in Gmail/Outlook rules without opening the mail. Body carries the 6 aggregate
 * counts from SyncRun; attached CSV has the per-SKU breakdown (D-10).
 *
 * Pitfall P2-A: the CSV at $csvPath MUST be fully flushed before this Mailable
 * is handed to Mail::to()->send(). SyncReportCsvGenerator::generate() guarantees
 * this by unset()'ing its writer before returning the path.
 */
final class SupplierSyncReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly SyncRun $run,
        public readonly string $csvPath,
        public readonly bool $aborted = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->aborted
            ? "[ABORTED] Supplier sync {$this->run->id} — {$this->run->abort_reason}"
            : "Supplier sync {$this->run->id} — {$this->run->updated_count} updated";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier-sync-report',
            with: [
                'run' => $this->run,
                'aborted' => $this->aborted,
                'stats' => $this->run->only([
                    'total_skus', 'updated_count', 'skipped_count',
                    'failed_count', 'missing_count', 'unknown_sku_count',
                ]),
                'abortReason' => $this->run->abort_reason,
                'abortMessage' => $this->run->abort_message,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->csvPath)
                ->as("supplier-sync-run-{$this->run->id}.csv")
                ->withMime('text/csv'),
        ];
    }
}
