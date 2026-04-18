<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Supplier Sync Report</title>
</head>
<body style="font-family: system-ui, sans-serif; color: #333;">
<h2>Supplier Sync Report — Run #{{ $run->id }}</h2>

@if ($aborted)
    <p style="color: #c00;"><strong>Status: ABORTED</strong> — reason: {{ $abortReason }}</p>
    <p><strong>Message:</strong> {{ $abortMessage }}</p>
@else
    <p style="color: #080;"><strong>Status: Completed</strong></p>
@endif

<p><strong>Mode:</strong> {{ $run->dry_run ? 'DRY-RUN (no Woo writes)' : 'LIVE' }}</p>
<p><strong>Correlation ID:</strong> <code>{{ $run->correlation_id }}</code></p>
<p><strong>Started:</strong> {{ optional($run->started_at)->toIso8601String() }}</p>
<p><strong>Completed:</strong> {{ optional($run->completed_at)->toIso8601String() ?? '—' }}</p>

<h3>Counts</h3>
<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse;">
    <tr><th>Metric</th><th>Count</th></tr>
    <tr><td>Total SKUs</td><td>{{ $stats['total_skus'] ?? 0 }}</td></tr>
    <tr><td>Updated</td><td>{{ $stats['updated_count'] ?? 0 }}</td></tr>
    <tr><td>Skipped</td><td>{{ $stats['skipped_count'] ?? 0 }}</td></tr>
    <tr><td>Failed</td><td>{{ $stats['failed_count'] ?? 0 }}</td></tr>
    <tr><td>Missing at supplier</td><td>{{ $stats['missing_count'] ?? 0 }}</td></tr>
    <tr><td>Unknown SKUs</td><td>{{ $stats['unknown_sku_count'] ?? 0 }}</td></tr>
</table>

<p>Attached: per-SKU CSV report (11 columns per D-10).</p>

<p style="font-size: 0.9em; color: #666;">
    This run can be drilled-down at <code>/admin/sync-runs/{{ $run->id }}</code>.
    Aborted runs are resumable via <code>php artisan sync:supplier --resume={{ $run->id }} --live</code>.
</p>
</body>
</html>
