<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Supplier Sync Digest — {{ $payload['window_end']->format('Y-m-d') }}</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Helvetica, Arial, sans-serif; color:#1f2937; line-height:1.45; max-width:760px; margin:0 auto; padding:20px; }
        h1 { font-size:20px; margin:0 0 4px; }
        h2 { font-size:15px; margin:24px 0 8px; padding-bottom:4px; border-bottom:1px solid #e5e7eb; }
        .meta { color:#6b7280; font-size:13px; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { text-align:left; padding:6px 8px; border-bottom:1px solid #f3f4f6; }
        th { background:#f9fafb; font-weight:600; }
        td.num { text-align:right; font-variant-numeric:tabular-nums; }
        .totals { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
        .tile { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:10px 14px; min-width:140px; }
        .tile .label { font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:#6b7280; }
        .tile .value { font-size:20px; font-weight:600; color:#111827; }
        .empty { color:#9ca3af; font-style:italic; padding:8px 0; }
        .delta-up { color:#dc2626; }
        .delta-down { color:#059669; }
    </style>
</head>
<body>

<h1>Supplier Sync Digest</h1>
<div class="meta">
    Window: {{ $payload['window_start']->format('Y-m-d H:i') }} – {{ $payload['window_end']->format('Y-m-d H:i') }} (Europe/London)
</div>

<div class="totals">
    <div class="tile"><div class="label">Total products</div><div class="value">{{ number_format($payload['totals']['products']) }}</div></div>
    <div class="tile"><div class="label">With buy price</div><div class="value">{{ number_format($payload['totals']['with_buy_price']) }}</div></div>
    <div class="tile"><div class="label">Pending</div><div class="value">{{ number_format($payload['totals']['pending']) }}</div></div>
    <div class="tile"><div class="label">No supplier offer</div><div class="value">{{ number_format($payload['totals']['missing_supplier_offer']) }}</div></div>
</div>

<h2>Top buy-price changes (last 24h)</h2>
@if (count($payload['price_changes']) === 0)
    <div class="empty">No price changes detected.</div>
@else
    <table>
        <thead><tr><th>SKU</th><th>Name</th><th class="num">Old</th><th class="num">New</th><th class="num">Δ</th></tr></thead>
        <tbody>
        @foreach ($payload['price_changes'] as $row)
            <tr>
                <td>{{ $row['sku'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">£{{ number_format($row['old'], 2) }}</td>
                <td class="num">£{{ number_format($row['new'], 2) }}</td>
                <td class="num {{ $row['delta_pct'] !== null && $row['delta_pct'] > 0 ? 'delta-up' : 'delta-down' }}">
                    {{ $row['delta_pct'] !== null ? ($row['delta_pct'] > 0 ? '+' : '').$row['delta_pct'].'%' : '—' }}
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Top stock changes (last 24h)</h2>
@if (count($payload['stock_changes']) === 0)
    <div class="empty">No stock changes detected.</div>
@else
    <table>
        <thead><tr><th>SKU</th><th>Name</th><th class="num">Old</th><th class="num">New</th></tr></thead>
        <tbody>
        @foreach ($payload['stock_changes'] as $row)
            <tr>
                <td>{{ $row['sku'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['old'] }}</td>
                <td class="num">{{ $row['new'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Flipped to pending (missing buy_price)</h2>
@if (count($payload['flipped_pending']) === 0)
    <div class="empty">None flipped in this window.</div>
@else
    <table>
        <thead><tr><th>SKU</th><th>Name</th><th class="num">Buy price</th></tr></thead>
        <tbody>
        @foreach ($payload['flipped_pending'] as $row)
            <tr>
                <td>{{ $row['sku'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['buy_price'] === null ? '—' : '£'.number_format((float) $row['buy_price'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Published SKUs missing today's supplier offer</h2>
@if (count($payload['missing_supplier_offer']) === 0)
    <div class="empty">Every published SKU was offered by at least one supplier today.</div>
@else
    <table>
        <thead><tr><th>SKU</th><th>Name</th><th>Status</th></tr></thead>
        <tbody>
        @foreach ($payload['missing_supplier_offer'] as $row)
            <tr>
                <td>{{ $row['sku'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['status'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
