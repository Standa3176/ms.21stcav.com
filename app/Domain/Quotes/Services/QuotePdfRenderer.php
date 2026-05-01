<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Services;

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Quotes\Models\Quote;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Phase 11 Plan 04 — Quote PDF renderer (QUOT-04).
 *
 * Wraps spatie/laravel-pdf (DOMPDF driver per config('laravel-pdf.driver') →
 * env('LARAVEL_PDF_DRIVER', 'browsershot')) around the quote.blade.php
 * template. Reads ONLY snapshot columns from the QuoteLine model
 * (`unit_price_pence_at_quote`, `line_total_pence_at_quote`,
 * `product_snapshot`) — never re-resolves prices via TradeRuleResolver
 * (Anti-Pattern 1; QuotePdfRouteSnapshotTest enforces byte-identical output).
 *
 * PriceCalculator is INJECTED (not facade-accessed) so the PriceSnapshotter
 * v1.0 contract stays single-call-site within the Quotes domain (RESEARCH §W3
 * snapshot integrity guarantees the unit_price stored at write time always
 * matches what the renderer needs to display, modulo VAT-strip at render time
 * per D-11 UK B2B ex-VAT itemised convention).
 *
 * Used by:
 *   - QuoteSentMail (Plan 11-04 — attaches base64 PDF to outbound email)
 *   - Filament admin "Download PDF" action (Plan 11-03 stub → wired later)
 *   - Plan 11-04 PDF tests (this plan ships the test ship gate)
 *
 * NEVER persists PDFs to disk by default — generated on-demand. OQ-3 RESOLVED:
 * re-render on every push attempt (deterministic — snapshot integrity
 * guarantees byte-identical output across re-renders).
 */
final class QuotePdfRenderer
{
    public function __construct(
        private readonly PriceCalculator $calc,
    ) {
    }

    /**
     * Render a Quote to PDF and return base64-encoded bytes.
     *
     * Used by QuoteSentMail (attach via base64_decode) and the
     * QuotePdfRouteSnapshotTest regression test.
     */
    public function render(Quote $quote): string
    {
        return Pdf::view('pdf.quote', [
            'quote' => $quote->load('lines'),
            'calc' => $this->calc,
        ])
            ->name(sprintf('quote-%s.pdf', $quote->ulidShort()))
            ->base64();
    }

    /**
     * Render a Quote PDF to an absolute filesystem path. Reserved for the
     * Plan 11-05 archival path (config-gated; v1 default OFF).
     */
    public function renderToFile(Quote $quote, string $absolutePath): void
    {
        Pdf::view('pdf.quote', [
            'quote' => $quote->load('lines'),
            'calc' => $this->calc,
        ])->save($absolutePath);
    }
}
