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

// D-08: sync_diffs — conditional (no-op while WOO_WRITE_ENABLED=false per Pitfall L)
Schedule::command('sync-diffs:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer();

// TODO: Phase 2 adds `sync-errors:prune --days=90` (D-07) once Phase 2 ships the sync_errors table.
// TODO: Phase 5 adds `competitor-csv:prune --days=90` (D-06) once Phase 5 ships the csv_parse_errors table.

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
