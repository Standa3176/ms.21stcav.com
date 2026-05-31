<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Competitor Analysis Configuration (Phase 5 Plan 01)
|--------------------------------------------------------------------------
|
| Centralised thresholds + retention for the competitor-CSV ingest pipeline
| and MarginAnalyser noise-suppression gates (D-05..D-07).
|
| margin_delta_threshold_bps — 8% in basis points (REQUIREMENTS.md default).
|   A competitor-vs-our-margin delta must exceed this before a margin_change
|   suggestion fires.
|
| min_margin_floor_bps — 6% safety guard (operator decision 2026-05-24; was 5%
|   Pitfall P5-E). Never price below cost + 6% margin (the undercut command's
|   loss guard) and never recommend a rule change below it — that's a money-loser
|   regardless of what competitors charge.
|
| consecutive_scrapes_required — 3 (REQUIREMENTS.md default). Prevents
|   knee-jerk suggestions from a single anomalous scrape.
|
| sales_threshold_90d — 10 orders. The "≥N sales" gate in the analyser.
|   Slow-movers don't justify margin changes no matter how aggressive the
|   competitor pricing — 10 / 90d ≈ real demand.
|
| beat_by_pennies — 1p. How much lower than the competitor we aim to land.
|   Margin-change suggestion payloads reverse-engineer the new margin from
|   (competitor_ex_vat - beat_by_pennies).
|
| csv_retention_days — 90. Applies ONLY to raw CSV source files under
|   storage/app/competitors/archive/. competitor_prices rows are NEVER pruned
|   per COMP-07 mandate.
|
| stale_feed_hours — 48. Active competitors with no data in >48h trigger
|   stale-feed alerts (COMP-11).
|
| csv_chunk_size — 100. Rows per CompetitorCsvChunkJob dispatch.
|
| filename_regex — {slug}_{YYYY-MM-DD}.csv (D-01). Anchors prevent traversal.
|
| ftp.* — Phase 11.1 D-05 step 4 + D-12 + D-11. DoS guard + circuit-breaker
|   threshold + connection timeout for the every-15-min FTP pull command.
|   Files larger than `ftp.max_file_mb` are skipped (DoS guard). After
|   `ftp.consecutive_failures_threshold` consecutive failures a source is
|   auto-disabled and recipients are notified (D-12).
*/

return [
    'margin_delta_threshold_bps' => (int) env('COMPETITOR_MARGIN_DELTA_BPS', 800),       // 8% in bps
    'consecutive_scrapes_required' => (int) env('COMPETITOR_SCRAPES_REQUIRED', 3),
    'sales_threshold_90d' => (int) env('COMPETITOR_SALES_THRESHOLD_90D', 10),
    'min_margin_floor_bps' => (int) env('COMPETITOR_MIN_MARGIN_FLOOR_BPS', 600),   // 6% safety floor (operator decision 2026-05-24; was 5% P5-E)
    'beat_by_pennies' => (int) env('COMPETITOR_BEAT_BY_PENNIES', 1),
    'csv_retention_days' => (int) env('COMPETITOR_CSV_RETENTION_DAYS', 90),
    'stale_feed_hours' => (int) env('COMPETITOR_STALE_FEED_HOURS', 48),
    'csv_chunk_size' => (int) env('COMPETITOR_CSV_CHUNK_SIZE', 100),
    'filename_regex' => '/^[a-z0-9_-]{1,64}_\d{4}-\d{2}-\d{2}\.csv$/',

    // Phase 11.1 Plan 01 — D-05 step 4 + D-12 circuit breaker + D-01 timeout.
    // Phase 11.2 Plan 01 — D-10 stale-feed red-text threshold for the Filament
    //   CompetitorFtpFeedResource remote_file_date column.
    'ftp' => [
        'max_file_mb' => (int) env('COMPETITOR_FTP_MAX_FILE_MB', 50),
        'consecutive_failures_threshold' => (int) env('COMPETITOR_FTP_FAILURE_THRESHOLD', 3),
        'connection_timeout_seconds' => (int) env('COMPETITOR_FTP_TIMEOUT_SECONDS', 30),
        // Threshold for the CompetitorFtpFeedResource "Remote File Date" column
        // to render in red — and (Phase 11.2 D-10) for the StaleFeedTrafficLight
        // widget. Lowered to 4 days 2026-05-31 per operator: most competitor
        // feeds refresh daily or every-other-day, so anything >4 days is a
        // real concern (not just a fortnight-old default).
        'stale_days' => (int) env('COMPETITOR_FTP_STALE_DAYS', 4),
    ],
];
