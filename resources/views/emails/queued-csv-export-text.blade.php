Your CSV export is ready.

File:  {{ $filename }}
Rows:  ~{{ number_format($rowCountApprox) }}

Download link (expires in 7 days):
{{ $signedUrl }}

This link is tied to your authenticated session — only you can use it while you
remain signed into the MeetingStore Ops admin panel.

Queued exports run on the sync-bulk queue (Horizon). For bulk exports above the
UI cap, use the artisan CLI exports:* commands or narrow your filter and retry.
