<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dashboard Configuration (Phase 7 Plan 01)
|--------------------------------------------------------------------------
|
| Tunable knobs for the Phase 7 home dashboard widgets (DASH-01..03), saved
| filter + CSV export affordances (DASH-04), and notification centre
| (DASH-06). Every widget / export / search path reads through this config
| — no values hard-coded inline.
|
| snapshot_ttl_minutes — D-02 freshness ceiling. Widgets whose underlying
|   dashboard_snapshots row is older than this show a stale-data amber
|   border + trigger a background refresh. Dashboard refresh command runs
|   every 5 minutes so 15 is a safety ceiling, not the common path.
|
| widget_poll_seconds — Livewire wire:poll frequency on the home page.
|   60s matches the CONTEXT.md Claude's-Discretion default; enough to keep
|   widgets live, not so aggressive that it hammers the snapshots table.
|
| refresh_interval_minutes — scheduled frequency for the dashboard:refresh
|   command (Plan 07-02). 5 minutes = 12 runs/hour; cheap given pre-
|   aggregation.
|
| snapshot_retention_days — prune cut-off for dashboard_snapshots; future-
|   proofing for sparkline history (current v1 keeps ≤20 rows upsert-by-key
|   so retention is nominal).
|
| csv_export_hard_cap — server-protection ceiling per CONTEXT.md §Claude's
|   Discretion. Above this, admins see "Use the artisan command or narrow
|   your filter". 100k rows is the Filament bulk-action UX limit.
|
| csv_export_queue_threshold — soft ceiling above which the UI prompts
|   "Queue this export to email?" (D-06). Streams in-browser below the
|   threshold; queues above.
|
| global_search_debounce_ms — Filament 3 global-search debounce (D-04).
|   300ms is Filament's default; exposed here for ops tuning later.
*/

return [

    // D-02 — snapshot freshness ceiling.
    'snapshot_ttl_minutes' => (int) env('DASHBOARD_SNAPSHOT_TTL_MINUTES', 15),

    // CONTEXT.md Claude's-Discretion — Livewire poll interval for widgets.
    'widget_poll_seconds' => (int) env('DASHBOARD_WIDGET_POLL_SECONDS', 60),

    // Plan 07-02 scheduled refresh frequency.
    'refresh_interval_minutes' => (int) env('DASHBOARD_REFRESH_INTERVAL_MINUTES', 5),

    // Retention for dashboard_snapshots (future-proof for sparkline history).
    'snapshot_retention_days' => (int) env('DASHBOARD_SNAPSHOT_RETENTION_DAYS', 30),

    // CSV export UX gates (D-06).
    'csv_export_hard_cap' => (int) env('DASHBOARD_CSV_EXPORT_HARD_CAP', 100000),
    'csv_export_queue_threshold' => (int) env('DASHBOARD_CSV_EXPORT_QUEUE_THRESHOLD', 10000),

    // D-04 — global search debounce (ms).
    'global_search_debounce_ms' => (int) env('DASHBOARD_GLOBAL_SEARCH_DEBOUNCE_MS', 300),

];
