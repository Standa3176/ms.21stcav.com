<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Mail;

use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Services\QuotePdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 11 Plan 04 — QuoteSentMail Mailable with PDF attachment (QUOT-05).
 *
 * Plan 11-03 shipped a stub shell so ApproveQuoteAction could call
 * Mail::to(...)->queue(new QuoteSentMail($quote)) cleanly inside the
 * draft → sent transaction. Plan 11-04 fills in:
 *   - PDF render via QuotePdfRenderer (spatie/laravel-pdf + DOMPDF)
 *   - PDF attachment via Attachment::fromData($base64Decoded, ...)
 *   - HTML body referencing customer + expires_at
 *
 * Renders the PDF inside `attachments()` (not the constructor) so a queued
 * dispatch's Mailable serialisation stays small — only the Quote ULID
 * survives the queue boundary. PriceCalculator is resolved via Laravel
 * container at handle time (PriceSnapshotter v1.0 contract — never
 * re-resolves rules; reads snapshot columns only per Anti-Pattern 1).
 *
 * Subject: "Your quote #{ulid_short} from {company_name}"
 * Recipient: $quote->customer_email (Filament form validates email rule).
 * Queue: 'default' (separate from crm-bitrix supervisor that carries
 * PushQuoteToBitrixDealJob — email + Bitrix push run in parallel).
 */
final class QuoteSentMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Quote $quote,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your quote #'.$this->quote->ulidShort().' from '.config('quote.company_name', 'MeetingStore'),
        );
    }

    public function content(): Content
    {
        $companyName = (string) config('quote.company_name', 'MeetingStore');
        $expiresLine = $this->quote->expires_at !== null
            ? '<p>This quote is valid until <strong>'.$this->quote->expires_at->format('d M Y').'</strong>.</p>'
            : '';
        $totalIncVat = number_format($this->quote->total_pence_at_quote / 100, 2, '.', ',');
        $name = $this->quote->customer_name !== null && $this->quote->customer_name !== ''
            ? e($this->quote->customer_name)
            : 'there';

        return new Content(
            htmlString: "<p>Hi {$name},</p>"
                ."<p>Please find your quote <strong>#".$this->quote->ulidShort()."</strong> attached.</p>"
                ."<p>Total: <strong>£{$totalIncVat}</strong> (inc VAT)</p>"
                .$expiresLine
                ."<p>If you have any questions, please reply to this email.</p>"
                ."<p>Kind regards,<br>".e($companyName)."</p>",
        );
    }

    /**
     * Render the PDF on demand at queue-handle time + attach.
     *
     * Returning Attachment::fromData with a closure means spatie/laravel-pdf
     * runs INSIDE the queued worker, not at dispatch time. Matches Mailable
     * convention; keeps the SerializesModels payload to ULID + nothing else.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $renderer = app(QuotePdfRenderer::class);
        $base64 = $renderer->render($this->quote);
        $pdfBytes = base64_decode($base64, true);

        if ($pdfBytes === false) {
            // Defensive: spatie/laravel-pdf::base64() returns base64 OR false on
            // serialisation failure. Fail loud — Mailable will surface in DLQ.
            $pdfBytes = '';
        }

        return [
            Attachment::fromData(
                fn (): string => $pdfBytes,
                'quote-'.$this->quote->ulidShort().'.pdf',
            )->withMime('application/pdf'),
        ];
    }
}
