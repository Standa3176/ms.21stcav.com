{{--
    Quick task 260930-eo3 — price-position report.

    Rendered by PricePositionReportCommand through DOMPDF, NOT Browsershot, so
    this template must stay within what DOMPDF supports: tables, inline-block,
    simple borders. No flexbox, no grid, no web fonts, no external assets.
    DejaVu Sans is set by the command because it is the DOMPDF built-in that
    carries the pound sign and the en dash.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8px; color: #1a1a1a; }
        h1 { font-size: 15px; margin: 0 0 2px 0; }
        .sub { font-size: 8px; color: #555; margin: 0 0 10px 0; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #222; color: #fff; font-size: 7.5px; text-align: left;
             padding: 4px 3px; text-transform: uppercase; letter-spacing: .3px; }
        td { padding: 4px 3px; border-bottom: 1px solid #e2e2e2; vertical-align: top; }
        .num { text-align: right; }
        .muted-text { color: #777; }
        tr.alt td { background: #fafafa; }

        /* Verdict colours. Kept as a left border plus a tinted pill so the
           report still reads correctly printed in greyscale, where a pure
           background-colour scheme collapses into identical greys. */
        .pill { display: inline-block; padding: 1px 4px; border-radius: 6px;
                font-size: 7px; font-weight: bold; white-space: nowrap; }
        .good      { background: #d8f0dd; color: #14532d; }
        .opportunity { background: #dbeafe; color: #1e3a8a; }
        .warn      { background: #fdf0d5; color: #92400e; }
        .bad       { background: #fadbdb; color: #7f1d1d; }
        .muted     { background: #ececec; color: #4b5563; }

        td.flag-good { border-left: 3px solid #16a34a; }
        td.flag-opportunity { border-left: 3px solid #2563eb; }
        td.flag-warn { border-left: 3px solid #d97706; }
        td.flag-bad { border-left: 3px solid #dc2626; }
        td.flag-muted { border-left: 3px solid #cbd5e1; }

        .key { margin: 10px 0 0 0; font-size: 7.5px; color: #333; }
        .key td { border: none; padding: 2px 6px 2px 0; }
        .note { margin-top: 8px; font-size: 7px; color: #666; line-height: 1.45; }
    </style>
</head>
<body>

@php
    /** Format integer pennies as pounds, or an em dash when we have no figure. */
    $money = static function (?int $pence): string {
        if ($pence === null) { return '—'; }
        return ($pence < 0 ? '-£' : '£') . number_format(abs($pence) / 100, 2);
    };

    $counts = [];
    foreach ($rows as $r) { $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1; }

    $order = ['best_price', 'could_be_best', 'below_cost', 'below_floor', 'cannot_win', 'no_competitor', 'no_cost', 'not_in_catalogue'];
@endphp

<h1>{{ $title }}</h1>
<p class="sub">
    {{ count($rows) }} part numbers ·
    competitor window {{ $windowDays }} days ·
    margin floor cost + {{ number_format($floorBps / 100, 1) }}% ·
    generated {{ $generatedAt->format('Y-m-d H:i') }}
</p>

<table class="key">
    <tr>
        @foreach ($order as $status)
            @continue(! isset($counts[$status]))
            @php
                $d = $status === 'not_in_catalogue'
                    ? ['label' => 'Not in catalogue', 'tone' => 'muted']
                    : \App\Domain\Pricing\Services\PricePositionClassifier::describe($status);
            @endphp
            <td><span class="pill {{ $d['tone'] }}">{{ $d['label'] }}</span> <strong>{{ $counts[$status] }}</strong></td>
        @endforeach
    </tr>
</table>

<table style="margin-top:8px">
    <thead>
        <tr>
            <th style="width:19%">Product</th>
            <th style="width:11%">Part number</th>
            <th style="width:12%">Verdict</th>
            <th style="width:7%" class="num">Our price</th>
            <th style="width:7%" class="num">Lowest comp</th>
            <th style="width:5%" class="num">Comps</th>
            <th style="width:7%" class="num">vs lowest</th>
            <th style="width:7%" class="num">Floor</th>
            <th style="width:7%" class="num">Cost</th>
            <th style="width:7%" class="num">True margin</th>
            <th style="width:11%">Notes</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($rows as $i => $r)
        @php
            $delta = $r['delta_vs_lowest_pence'];
            $notes = [];
            if (($r['match'] ?? null) === 'normalised') { $notes[] = 'matched on ' . $r['sku']; }
            if (($r['match'] ?? null) === 'NOT FOUND' && ($r['competitors'] ?? 0) > 0) {
                $notes[] = $r['competitors'] . ' competitor(s) sell it';
            }
            if ($r['sku'] !== null && ! $r['published']) { $notes[] = 'NOT published'; }
            if (($r['stock_status'] ?? '') === 'outofstock') { $notes[] = 'out of stock'; }
            if ($r['status'] === 'could_be_best' && $r['undercut_target_pence'] !== null) {
                $notes[] = 'price to ' . $money($r['undercut_target_pence']);
            }
            if ($r['status'] === 'cannot_win') { $notes[] = 'leader is under our floor'; }
            if ($r['status'] === 'below_floor' && $r['headroom_pence'] !== null) {
                $notes[] = $money(abs((int) $r['headroom_pence'])) . ' under floor';
            }
        @endphp
        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
            <td class="flag-{{ $r['tone'] }}">{{ $r['name'] }}</td>
            <td>{{ $r['part_number'] }}</td>
            <td><span class="pill {{ $r['tone'] }}">{{ $r['label'] }}</span></td>
            <td class="num">{{ $money($r['sell_gross_pence']) }}</td>
            <td class="num">{{ $money($r['lowest_comp_pence']) }}</td>
            <td class="num">{{ $r['competitors'] ?: '—' }}</td>
            <td class="num">{{ $delta === null ? '—' : ($delta > 0 ? '+' : '') . $money($delta) }}</td>
            <td class="num">{{ $money($r['floor_gross_pence']) }}</td>
            <td class="num">{{ $money($r['buy_pence']) }}</td>
            <td class="num">{{ $money($r['true_margin_pence']) }}</td>
            <td class="muted-text">{{ implode('; ', $notes) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<p class="note">
    <strong>How to read this.</strong>
    <em>Our price</em> and <em>Lowest comp</em> are VAT-inclusive, because that is what a buyer compares.
    <em>Cost</em> is the supplier price ex-VAT, and <em>True margin</em> is our price with VAT stripped, minus that cost —
    so it is cash we actually keep, not the sell-minus-cost figure that counts VAT we owe HMRC as profit.
    <em>Floor</em> is cost plus {{ number_format($floorBps / 100, 1) }}% markup: the lowest price the automated
    undercut job will ever set, and this report uses that job's own calculation so the two cannot disagree.
    <br>
    <strong>Best price now</strong> = we are already cheapest of the tracked resellers.
    <strong>Could be best</strong> = we are not cheapest, but undercutting the leader by {{ $beatBy }}p still clears the floor.
    <strong>Cannot win at floor</strong> = the leader is selling below where we can profitably go.
    <strong>Below margin floor</strong> / <strong>BELOW COST</strong> = our live price is already too low; these are losing money on every unit and are listed first in the key for that reason.
    <br>
    Competitor prices come from the tracked reseller feeds only. A product no competitor lists shows
    <em>No competitor data</em> — that is an absence of evidence, not evidence we are cheapest.
</p>

</body>
</html>
