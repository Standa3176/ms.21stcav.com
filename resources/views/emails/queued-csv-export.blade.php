<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>CSV Export Ready</title>
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color: #333; max-width: 640px; margin: 0 auto; padding: 24px;">
<h2 style="color: #1a1a1a; margin-bottom: 16px;">Your CSV export is ready</h2>

<p style="font-size: 15px; line-height: 1.5;">
    The CSV export you queued from the MeetingStore Ops admin panel has finished.
</p>

<table border="0" cellpadding="8" cellspacing="0" style="margin: 16px 0; border-collapse: collapse; font-size: 14px;">
    <tr>
        <td style="color: #666; padding-right: 16px;"><strong>File</strong></td>
        <td><code style="background: #f4f4f4; padding: 2px 6px; border-radius: 3px;">{{ $filename }}</code></td>
    </tr>
    <tr>
        <td style="color: #666; padding-right: 16px;"><strong>Rows</strong></td>
        <td>~{{ number_format($rowCountApprox) }}</td>
    </tr>
</table>

<p style="margin: 24px 0;">
    <a href="{{ $signedUrl }}"
       style="display: inline-block; background: #f59e0b; color: #fff; padding: 12px 24px;
              text-decoration: none; border-radius: 6px; font-weight: 600;">
        Download CSV
    </a>
</p>

<p style="font-size: 13px; color: #666; line-height: 1.5;">
    This download link expires in <strong>7 days</strong>. Only you (the user who queued this export) can use it
    because access is enforced by Filament's <code>auth</code> middleware.
</p>

<p style="font-size: 12px; color: #999; margin-top: 32px; border-top: 1px solid #eee; padding-top: 16px;">
    Queued exports run on the <code>sync-bulk</code> queue (Horizon supervisor). For bulk exports above the UI cap,
    use the artisan CLI <code>exports:*</code> commands or narrow your filter before retrying.
</p>
</body>
</html>
