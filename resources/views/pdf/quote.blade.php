{{-- Phase 11 Plan 04 — Quote PDF (UK B2B ex-VAT itemised, D-11). --}}
{{-- $quote = App\Domain\Quotes\Models\Quote (with lines preloaded) --}}
{{-- $calc  = App\Domain\Pricing\Services\PriceCalculator (injected — NOT facade) --}}
{{-- DOMPDF supports CSS 2.1 — no flexbox/grid; use table layout. --}}
@php
    /** @var \App\Domain\Quotes\Models\Quote $quote */
    /** @var \App\Domain\Pricing\Services\PriceCalculator $calc */
    $companyName = config('quote.company_name', 'MeetingStore Limited');
    $companyAddress = config('quote.company_address', '');
    $companyVatNumber = config('quote.company_vat_number', '');
    $companyRegistrationNumber = config('quote.company_registration_number', '');
    $ulidShort = $quote->ulidShort();
    $totalIncVat = (int) $quote->total_pence_at_quote; // VAT-INCLUSIVE pence (A1)
    $subtotalExVat = $calc->stripVat($totalIncVat); // ex-VAT pence
    $vatPence = $totalIncVat - $subtotalExVat;
    $billing = is_array($quote->billing_address) ? $quote->billing_address : [];
    $logoPath = public_path('images/meetingstore-logo.png');
    $logoExists = file_exists($logoPath);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quote #{{ $ulidShort }} — {{ $companyName }}</title>
    <style>
        @page { margin: 18mm 14mm 22mm 14mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #222;
            margin: 0;
            padding: 0;
        }
        h1, h2, h3 { margin: 0 0 6pt 0; padding: 0; font-weight: bold; }
        h1 { font-size: 18pt; color: #111; }
        h2 { font-size: 13pt; color: #333; margin-top: 10pt; }
        h3 { font-size: 10pt; color: #555; }
        p, td, th { line-height: 1.35; }
        .muted { color: #666; }
        .small { font-size: 8.5pt; }
        .right { text-align: right; }
        .center { text-align: center; }
        .strong { font-weight: bold; }

        table.layout { width: 100%; border-collapse: collapse; }
        table.layout td { vertical-align: top; padding: 0; }

        /* Header block ─────────────────────── */
        .header {
            border-bottom: 1.5pt solid #444;
            padding-bottom: 8pt;
            margin-bottom: 12pt;
        }
        .header .company-block { width: 60%; }
        .header .quote-meta { width: 40%; text-align: right; }
        .header .logo { max-height: 50pt; max-width: 180pt; }

        /* Customer + Quote ref split ───────── */
        .meta-block {
            margin-bottom: 12pt;
            padding: 6pt 0;
        }
        .meta-block .label {
            font-size: 8pt;
            text-transform: uppercase;
            color: #888;
            letter-spacing: 0.5pt;
            margin-bottom: 2pt;
        }

        /* Line items table ─────────────────── */
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin: 12pt 0;
        }
        table.lines th {
            background: #f0f0f0;
            border-bottom: 1pt solid #888;
            text-align: left;
            padding: 5pt 6pt;
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
        }
        table.lines th.right, table.lines td.right { text-align: right; }
        table.lines td {
            border-bottom: 0.5pt solid #ddd;
            padding: 6pt;
            font-size: 9.5pt;
            vertical-align: top;
        }
        table.lines tr:nth-child(even) td { background: #fafafa; }

        /* Totals block (right-aligned) ─────── */
        table.totals {
            width: 50%;
            margin-left: 50%;
            margin-top: 8pt;
            border-collapse: collapse;
        }
        table.totals td {
            padding: 4pt 6pt;
            font-size: 10pt;
        }
        table.totals td.label { text-align: right; color: #555; }
        table.totals td.amount { text-align: right; width: 28%; }
        table.totals tr.grand-total td {
            border-top: 1.5pt solid #444;
            font-size: 11.5pt;
            font-weight: bold;
            color: #111;
            padding-top: 6pt;
        }

        /* Signature block (D-11 optional) ──── */
        .signature {
            margin-top: 24pt;
            padding-top: 12pt;
            border-top: 0.5pt dashed #999;
        }
        .signature .sigline {
            border-bottom: 0.5pt solid #333;
            display: block;
            width: 200pt;
            height: 22pt;
        }

        /* Footer ───────────────────────────── */
        .footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #888;
            text-align: center;
            padding-top: 4pt;
            border-top: 0.3pt solid #ccc;
        }
    </style>
</head>
<body>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Header — company identity + quote reference                  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <table class="layout header">
        <tr>
            <td class="company-block">
                @if($logoExists)
                    <img src="{{ $logoPath }}" alt="{{ $companyName }}" class="logo">
                @else
                    <h1>{{ $companyName }}</h1>
                @endif
                <div class="small muted" style="margin-top: 6pt;">
                    {!! nl2br(e($companyAddress)) !!}
                    @if($companyVatNumber)
                        <br>VAT: {{ $companyVatNumber }}
                    @endif
                    @if($companyRegistrationNumber)
                        &nbsp;&middot;&nbsp;Reg: {{ $companyRegistrationNumber }}
                    @endif
                </div>
            </td>
            <td class="quote-meta">
                <h1>QUOTE</h1>
                <div class="small">
                    <span class="muted">Ref:</span> <span class="strong">#{{ $ulidShort }}</span>
                </div>
                <div class="small">
                    <span class="muted">Issued:</span> {{ optional($quote->created_at)->format('d M Y') ?? now()->format('d M Y') }}
                </div>
                @if($quote->expires_at)
                    <div class="small">
                        <span class="muted">Expires:</span> {{ $quote->expires_at->format('d M Y') }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Customer block                                                --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="meta-block">
        <div class="label">Quote prepared for</div>
        @if($quote->customer_name)
            <div class="strong">{{ $quote->customer_name }}</div>
        @endif
        <div>{{ $quote->customer_email }}</div>
        @if(! empty($billing))
            <div class="small muted" style="margin-top: 3pt;">
                @foreach(['address_1', 'address_2', 'city', 'postcode', 'country'] as $bk)
                    @if(! empty($billing[$bk]))
                        {{ $billing[$bk] }}@if(! $loop->last), @endif
                    @endif
                @endforeach
            </div>
        @endif
        @if($quote->customer_group_name_at_quote)
            <div class="small muted" style="margin-top: 3pt;">
                Customer group: {{ $quote->customer_group_name_at_quote }}
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Line items (UK B2B convention — D-11)                         --}}
    {{-- Per-line: ex-VAT unit price + ex-VAT line total               --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th style="width: 16%;">SKU</th>
                <th>Description</th>
                <th class="right" style="width: 8%;">Qty</th>
                <th class="right" style="width: 16%;">Unit Price (ex VAT)</th>
                <th class="right" style="width: 16%;">Line Total (ex VAT)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($quote->lines as $i => $line)
                @php
                    $unitExVat = $calc->stripVat((int) $line->unit_price_pence_at_quote);
                    $lineTotalExVat = $calc->stripVat((int) $line->line_total_pence_at_quote);
                    $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
                    $productName = $snapshot['name'] ?? $line->sku;
                @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td class="small">{{ $line->sku }}</td>
                    <td>{{ $productName }}</td>
                    <td class="right">{{ (int) $line->quantity_int }}</td>
                    <td class="right">£{{ number_format($unitExVat / 100, 2, '.', ',') }}</td>
                    <td class="right">£{{ number_format($lineTotalExVat / 100, 2, '.', ',') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="center muted small">No line items on this quote.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Totals block — Subtotal ex VAT / VAT 20% / Total inc VAT      --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <table class="totals">
        <tr>
            <td class="label">Subtotal (ex VAT)</td>
            <td class="amount">£{{ number_format($subtotalExVat / 100, 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td class="label">VAT 20%</td>
            <td class="amount">£{{ number_format($vatPence / 100, 2, '.', ',') }}</td>
        </tr>
        <tr class="grand-total">
            <td class="label">Total (inc VAT)</td>
            <td class="amount">£{{ number_format($totalIncVat / 100, 2, '.', ',') }}</td>
        </tr>
    </table>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Optional signature block (config-gated, default OFF)          --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    @if(config('quote.pdf_signature_block', false) === true)
        <div class="signature">
            <h3>Customer acceptance</h3>
            <p class="small muted">Sign below to indicate acceptance of this quote.</p>
            <table class="layout" style="margin-top: 12pt;">
                <tr>
                    <td style="width: 60%;">
                        <span class="sigline">&nbsp;</span>
                        <div class="small muted">Signature</div>
                    </td>
                    <td>
                        <span class="sigline">&nbsp;</span>
                        <div class="small muted">Date</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- Footer                                                        --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <div class="footer">
        Quote #{{ $ulidShort }} &middot; Generated {{ now()->format('Y-m-d H:i') }} &middot; {{ $companyName }}
    </div>

</body>
</html>
