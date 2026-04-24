MEETINGSTORE OPS — WEEKLY DIGEST
Window: {{ $payload['window_start'] }} to {{ $payload['window_end'] }}

== Supplier Sync ==
Runs completed: {{ $payload['sync']['runs_completed'] }}
Average duration: {{ $payload['sync']['average_duration_seconds'] ?? '—' }}s
Updated SKUs: {{ $payload['sync']['updated_skus'] }}
Failed SKUs: {{ $payload['sync']['failed_skus'] }}
@if (count($payload['sync']['top_5_failing_skus']) > 0)

Top 5 failing SKUs:
@foreach ($payload['sync']['top_5_failing_skus'] as $row)
  - {{ $row->sku ?? '' }} ({{ $row->c ?? 0 }} failure(s))
@endforeach
@endif

== Margin Analysis ==
Suggestions created: {{ $payload['margin']['created_count'] }}
Approved: {{ $payload['margin']['approved_count'] }}
Largest delta: {{ $payload['margin']['largest_delta_bps'] }} bps

== CRM Pushes ==
Deals pushed: {{ $payload['crm']['deals_pushed'] }}
Retries: {{ $payload['crm']['retries'] }}
DLQ (suggestions): {{ $payload['crm']['failed_to_suggestions'] }}

== Product Auto-Create ==
Drafts: {{ $payload['auto_create']['drafts_created'] }}
Approved: {{ $payload['auto_create']['approved_count'] }}
Rejected: {{ $payload['auto_create']['rejected_count'] }}
@if (count($payload['auto_create']['rejections_by_reason']) > 0)

Rejections by reason:
@foreach ($payload['auto_create']['rejections_by_reason'] as $reason => $count)
  - {{ $reason }}: {{ $count }}
@endforeach
@endif

== Competitor Analysis ==
CSVs ingested: {{ $payload['competitor']['ingested_runs'] }}
Parse errors: {{ $payload['competitor']['parse_errors'] }}
@if (count($payload['competitor']['top_3_movers']) > 0)

Top 3 margin-movers by spread:
@foreach ($payload['competitor']['top_3_movers'] as $mover)
  - {{ $mover->sku ?? '' }}: £{{ number_format((float) ($mover->spread ?? 0) / 100, 2) }}
@endforeach
@endif

---
Manage preferences at: {{ rtrim(config('app.url', ''), '/') }}/admin/alert-recipients
