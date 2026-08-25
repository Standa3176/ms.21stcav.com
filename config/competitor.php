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
| max_margin_ceiling_bps — 50% safety guard (2026-08-09 incident response;
|   upside counterpart to min_margin_floor_bps). SKU 9C941AA was repriced
|   £1297.30 -> £4652.02 (280% markup) because competitor_id=3's feed price
|   jumped overnight (almost certainly a bad feed row) and
|   pricing:undercut-competitors faithfully undercut it by 1p. When a
|   competitor-driven price would produce a margin above this ceiling, the
|   command refuses to write it and instead files a
|   'competitor_price_ceiling_blocked' Suggestion for human review — the same
|   asymmetric-trust posture as min_margin_floor_bps but guarding the OTHER
|   direction (a feed price that is implausibly HIGH, not implausibly low).
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
|
| max_row_move_pct — 50% (2026-08-09 incident response; Guard 2). A single
|   competitor_prices row that moves more than this vs. its own immediately
|   prior row (same competitor_id + sku) is quarantined: is_price_anomaly=true.
|   The row still persists (this codebase's "persist everything, gate on
|   consumption" convention — see the existing orphan-row precedent in
|   CompetitorCsvRowWriter), but CompetitorUndercutPricingCommand's
|   "lowest current competitor" query excludes flagged rows, so a single bad
|   feed row can never drive a live sell-price change.
*/

return [
    'margin_delta_threshold_bps' => (int) env('COMPETITOR_MARGIN_DELTA_BPS', 800),       // 8% in bps
    'consecutive_scrapes_required' => (int) env('COMPETITOR_SCRAPES_REQUIRED', 3),
    'sales_threshold_90d' => (int) env('COMPETITOR_SALES_THRESHOLD_90D', 10),
    'min_margin_floor_bps' => (int) env('COMPETITOR_MIN_MARGIN_FLOOR_BPS', 600),   // 6% safety floor (operator decision 2026-05-24; was 5% P5-E)
    'max_margin_ceiling_bps' => (int) env('COMPETITOR_MAX_MARGIN_CEILING_BPS', 5000), // 50% ceiling (2026-08-09 incident response)
    // ── 260825-t4m — triage INSIDE the ceiling block ──────────────────────
    // The 50% ceiling above is a single line that cannot tell three very
    // different things apart. Measured on prod 2026-08-25, 48 published blocks:
    //
    //   4  at 432%-5,737% — broken cost/identity, NOT market signal
    //   9  at 50%-85% with real cash — worth an operator's time
    //   20 under GBP 5 cash/unit, contributing GBP 0.00 in total
    //
    // Percentage screams on cables; pounds tell you where to care. These two
    // knobs classify a block WITHOUT changing whether it blocks: every price
    // that was withheld before is still withheld.
    //
    // ceiling_data_fault_bps — at or above this, treat as a data fault whatever
    //   the cash. A 400%+ margin against a supplier cost is a wrong cost or a
    //   wrong competitor row; releasing it would be the 2026-08-09 incident
    //   again. Never suppressed, never filtered out of review.
    'ceiling_data_fault_bps' => (int) env('COMPETITOR_CEILING_DATA_FAULT_BPS', 20000), // 200%
    //
    // ceiling_min_cash_pence — below this cash uplift per unit (EX VAT; VAT is
    //   not margin) a block is queue noise: a high percentage on a small
    //   absolute number, usually because we already sit at the competitor's
    //   price. Classified, still recorded, hidden from review by default.
    'ceiling_min_cash_pence' => (int) env('COMPETITOR_CEILING_MIN_CASH_PENCE', 500), // GBP 5.00

    'max_row_move_pct' => (int) env('COMPETITOR_MAX_ROW_MOVE_PCT', 50), // feed-jump quarantine threshold (2026-08-09 incident response)
    'beat_by_pennies' => (int) env('COMPETITOR_BEAT_BY_PENNIES', 1),
    'csv_retention_days' => (int) env('COMPETITOR_CSV_RETENTION_DAYS', 90),
    'stale_feed_hours' => (int) env('COMPETITOR_STALE_FEED_HOURS', 48),

    // 260705-pw3 — Competitor Feeds list red-colouring tolerance. A competitor's
    // Last Ingest is shown RED when it lags the NEWEST active competitor's last
    // ingest by more than this many hours (i.e. it missed the latest feed run).
    // Feeds refresh ~daily, so 24h tolerates same-run timing skew while flagging a
    // feed that's a day+ behind. Distinct from stale_feed_hours (48h alert cadence).
    'last_run_lag_hours' => (int) env('COMPETITOR_LAST_RUN_LAG_HOURS', 24),
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
