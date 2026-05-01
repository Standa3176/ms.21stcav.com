<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Mail;

use App\Domain\Quotes\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 11 Plan 03 STUB — QuoteSentMail Mailable (QUOT-05).
 *
 * Plan 11-03 ships this as a queueable Mailable shell so ApproveQuoteAction
 * can call Mail::to(...)->queue(new QuoteSentMail($quote)) cleanly today.
 *
 * Plan 11-04 fills in:
 *   - PDF rendering via spatie/laravel-pdf + DOMPDF + resources/views/pdf/quote.blade.php
 *   - PDF attachment via attachData($pdf->toString(), 'quote-{ulid}.pdf')
 *   - HTML body referencing the rendered PDF
 *
 * v1 default queue = 'default' (config('queue.default')); separate from
 * crm-bitrix supervisor (Phase 1 FOUND-09) which carries PushQuoteToBitrix.
 */
final class QuoteSentMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Quote $quote,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your quote #'.$this->quote->ulidShort().' from '.config('quote.company_name', 'MeetingStore'),
        );
    }

    public function content(): Content
    {
        // Plan 11-04 swaps this stub for a Blade view rendering the quote
        // detail + attachment. Stub HTML keeps Mailable shape valid for
        // Plan 11-03 dispatch tests (Mail::fake assertion path).
        return new Content(
            htmlString: '<p>Your quote #'.$this->quote->ulidShort().' is attached. Total: £'
                .number_format($this->quote->total_pence_at_quote / 100, 2).' inc VAT.</p>'
                .'<p>(Plan 11-04 will render the PDF attachment + branded body.)</p>',
        );
    }
}
