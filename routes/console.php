<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Retention prune schedule (D-09)
|--------------------------------------------------------------------------
|
| All prunes run at 03:00 window staggered by 10-minute intervals to spread
| DB load. `withoutOverlapping(30)` prevents a slow prune colliding with the
| next day's cron fire. `onOneServer()` ensures multi-worker deployments
| only run each prune once.
|
| Phase 2 will add: sync-errors:prune (D-07)
| Phase 5 will add: competitor-csv:prune (D-06)
*/

// D-04: audit_log — 365 days
Schedule::command('activitylog:prune --days=365')
    ->dailyAt('03:00')
    ->withoutOverlapping(30)
    ->onOneServer();

// D-05: integration_events — 90 days
Schedule::command('integration-events:prune --days=90')
    ->dailyAt('03:10')
    ->withoutOverlapping(30)
    ->onOneServer();

// D-07: sync_errors — 90 days (Phase 2 Plan 05 — replaces the Phase 1 TODO marker)
Schedule::command('sync-errors:prune', ['--days' => 90])
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Prune sync_errors older than 90 days (D-07 retention)');

// D-08: sync_diffs — conditional (no-op while WOO_WRITE_ENABLED=false per Pitfall L)
Schedule::command('sync-diffs:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer();

// TODO: Phase 5 adds `competitor-csv:prune --days=90` (D-06) once Phase 5 ships the csv_parse_errors table.

// Phase 5 Plan 02 — 5-minute competitor CSV watcher (COMP-01 + COMP-04).
// Picks up aged files from storage/app/competitors/incoming/ and dispatches
// IngestCompetitorCsvJob on the competitor-csv queue. withoutOverlapping(10)
// prevents a slow cycle (e.g. 50k-row CSV buffering) colliding with the next
// tick. onOneServer() ensures multi-worker deployments only process files once.
Schedule::command('competitor:watch')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Watch storage/app/competitors/incoming/ for aged CSVs (Phase 5 Plan 02)');

// Phase 5 Plan 03 — nightly 02:00 sales-counter recache (COMP-08 / COMP-09
// hybrid strategy). Chunks Product by 100 SKUs per RecacheSalesCountsJob on
// the sync-bulk queue. A3 fallback: job body is currently a stub (WooClient
// lacks /orders) — real-time IncrementSkuSalesCount listener is authoritative
// until WooClient gains a getOrders method in a post-Phase-5 plan.
Schedule::command('competitor:sales-recache')
    ->dailyAt('02:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Recompute last_sales_count_90d for every Product (Phase 5 Plan 03; A3 fallback stub)');

// Phase 5 Plan 04b — hourly stale-feed detector (COMP-11). Notifies every
// AlertRecipient where receives_competitor_alerts=true when an active
// competitor hasn't ingested in >stale_feed_hours (default 48h). 24h per-
// competitor dedup via Cache::add keyed on YYYY-MM-DD so the hourly cadence
// cannot alert-fatigue ops — first miss of the day wins.
Schedule::command('competitor:check-stale')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->timezone('Europe/London')
    ->description('Check for stale competitor feeds (>48h) and notify subscribers (Phase 5 Plan 04b, 24h dedup)');

// Phase 2 (D-05) — Daily supplier sync. COMMENTED OUT; Phase 7 cutover runbook
// enables this entry once parity with the legacy Stock Updater plugin is proven.
// The commented entry itself is the kill-switch — no separate SYNC_CRON_LIVE flag.
// Schedule::command('sync:supplier --live')
//     ->dailyAt('02:00')
//     ->onOneServer()
//     ->withoutOverlapping(60)
//     ->onQueue('sync-bulk')
//     ->timezone('Europe/London')
//     ->description('Daily 21stcav.com supplier sync (D-05 — enable post-Phase-7-cutover)');
