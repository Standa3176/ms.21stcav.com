<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MeetingStore Ops Weekly Digest</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; max-width: 720px; margin: 20px auto; padding: 10px; color: #111827; background: #ffffff;">
    <h1 style="color: #1a56db; margin-bottom: 4px;">MeetingStore Ops — Weekly Digest</h1>
    <p style="color: #6b7280; margin-top: 0;">Window: {{ $payload['window_start'] }} &nbsp;→&nbsp; {{ $payload['window_end'] }}</p>

    {{-- ────────────── Supplier Sync ────────────── --}}
    <h2 style="border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; color: #111827;">Supplier Sync</h2>
    <ul style="line-height: 1.6;">
        <li><strong>{{ $payload['sync']['runs_completed'] }}</strong> run(s) completed</li>
        <li>Average duration: {{ $payload['sync']['average_duration_seconds'] ?? '—' }}s</li>
        <li>Updated SKUs: <strong>{{ $payload['sync']['updated_skus'] }}</strong> &middot; Failed: <strong>{{ $payload['sync']['failed_skus'] }}</strong></li>
    </ul>
    @if (count($payload['sync']['top_5_failing_skus']) > 0)
        <table role="presentation" style="border-collapse: collapse; width: 100%; margin-top: 8px; font-size: 14px;">
            <tr style="background: #f3f4f6;">
                <th align="left" style="padding: 6px; border: 1px solid #e5e7eb;">SKU</th>
                <th align="right" style="padding: 6px; border: 1px solid #e5e7eb;">Failures</th>
            </tr>
            @foreach ($payload['sync']['top_5_failing_skus'] as $row)
                <tr>
                    <td style="padding: 6px; border: 1px solid #e5e7eb;">{{ $row->sku ?? '' }}</td>
                    <td align="right" style="padding: 6px; border: 1px solid #e5e7eb;">{{ $row->c ?? 0 }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- ────────────── Margin Analysis ────────────── --}}
    <h2 style="border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; color: #111827;">Margin Analysis</h2>
    <ul style="line-height: 1.6;">
        <li><strong>{{ $payload['margin']['created_count'] }}</strong> margin-change suggestion(s) created</li>
        <li><strong>{{ $payload['margin']['approved_count'] }}</strong> approved</li>
        <li>Largest delta: <strong>{{ $payload['margin']['largest_delta_bps'] }}</strong> bps</li>
    </ul>

    {{-- ────────────── CRM Pushes ────────────── --}}
    <h2 style="border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; color: #111827;">CRM Pushes</h2>
    <ul style="line-height: 1.6;">
        <li><strong>{{ $payload['crm']['deals_pushed'] }}</strong> deals pushed to Bitrix</li>
        <li>Retries: <strong>{{ $payload['crm']['retries'] }}</strong> &middot; DLQ (suggestions): <strong>{{ $payload['crm']['failed_to_suggestions'] }}</strong></li>
    </ul>

    {{-- ────────────── Product Auto-Create ────────────── --}}
    <h2 style="border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; color: #111827;">Product Auto-Create</h2>
    <ul style="line-height: 1.6;">
        <li><strong>{{ $payload['auto_create']['drafts_created'] }}</strong> draft(s) created &middot;
            <strong>{{ $payload['auto_create']['approved_count'] }}</strong> approved &middot;
            <strong>{{ $payload['auto_create']['rejected_count'] }}</strong> rejected</li>
    </ul>
    @if (count($payload['auto_create']['rejections_by_reason']) > 0)
        <p style="margin-bottom: 4px;"><strong>Rejections by reason:</strong></p>
        <ul style="line-height: 1.6;">
            @foreach ($payload['auto_create']['rejections_by_reason'] as $reason => $count)
                <li>{{ $reason }}: {{ $count }}</li>
            @endforeach
        </ul>
    @endif

    {{-- ────────────── Competitor Analysis ────────────── --}}
    <h2 style="border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; color: #111827;">Competitor Analysis</h2>
    <ul style="line-height: 1.6;">
        <li><strong>{{ $payload['competitor']['ingested_runs'] }}</strong> CSV file(s) ingested &middot;
            <strong>{{ $payload['competitor']['parse_errors'] }}</strong> parse error(s)</li>
    </ul>
    @if (count($payload['competitor']['top_3_movers']) > 0)
        <p style="margin-bottom: 4px;"><strong>Top 3 margin-movers by spread:</strong></p>
        <ol style="line-height: 1.6;">
            @foreach ($payload['competitor']['top_3_movers'] as $mover)
                <li>{{ $mover->sku ?? '' }} — spread £{{ number_format((float) ($mover->spread ?? 0) / 100, 2) }}</li>
            @endforeach
        </ol>
    @endif

    <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
    <p style="color: #6b7280; font-size: 12px; line-height: 1.5;">
        You receive this digest because your AlertRecipient profile has
        <code>receives_weekly_digest=true</code>. Update your preferences in
        <a href="{{ rtrim(config('app.url', ''), '/') }}/admin/alert-recipients" style="color: #1a56db;">Alert Recipients</a>.
    </p>
</body>
</html>
