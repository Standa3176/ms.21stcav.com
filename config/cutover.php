<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cutover Configuration (Phase 7 Plan 01)
|--------------------------------------------------------------------------
|
| Centralised tunables for the Phase 7 cutover commands + shadow-mode
| parity monitoring (D-12..D-19). Every command under Plan 07-05 reads
| through this config — no values are hard-coded inline.
|
| parity_threshold_percent — D-12 locked default 99. Ops can widen / tighten
|   without a redeploy via the env var. Ops SHOULD NOT flip
|   `WOO_WRITE_ENABLED=true` until divergence scan reports ≥ 99% parity
|   for 7 consecutive days (D-19 sequence).
|
| parity_window_days — D-12 rolling window. 7 days default matches the
|   documented parallel-run window in CONTEXT.md Half B.
|
| drill_allowed_env_var / disable_live_allowed_env_var /
| immediate_publish_allowed_env_var — env key NAMES (not values). Commands
|   look up these var names at runtime to decide whether --live is allowed.
|   Storing the NAME here keeps the safety gate out of env so an operator
|   accidentally setting CUTOVER_DRILL_ALLOWED=true doesn't auto-approve
|   anything until the command itself references this key.
|
| backup_path / drill_report_path — storage paths for cutover artefacts
|   (mysqldump snapshots + drill-report markdown). Defaults land under
|   storage/app/cutover/ so they survive deploys and live alongside audit
|   logs. Overridable via env for ops wanting NAS / S3 paths.
|
| legacy_plugins / legacy_cron_hooks — D-18 WP-CLI deregistration targets.
|   Hardcoded here (not env) because the plugin + cron-hook slugs are
|   structural facts about the legacy WordPress install — ops doesn't need
|   to tune them at deploy time.
*/

return [

    // D-12 — parity gate default; Phase 7 ships with 99% locked as the go-live threshold.
    'parity_threshold_percent' => (int) env('CUTOVER_PARITY_THRESHOLD_PERCENT', 99),

    // D-12 — rolling window for parity trend analysis. 7 days matches the
    // documented parallel-run window (CONTEXT.md Half B).
    'parity_window_days' => (int) env('CUTOVER_PARITY_WINDOW_DAYS', 7),

    // D-17 — the env var NAMES (not values) that gate --live on the three
    // cutover commands. Storing the NAME here is safer than storing the
    // value: an ops admin setting CUTOVER_DRILL_ALLOWED=true directly does
    // nothing until the command explicitly reads this config key.
    //
    // The NAME keys (*_env_var) are kept for backward-compat and for the
    // human-readable error messages the commands print ("Set %s=true in .env"
    // — the operator needs to know which env var to flip).
    'drill_allowed_env_var' => 'CUTOVER_DRILL_ALLOWED',
    'disable_live_allowed_env_var' => 'CUTOVER_DISABLE_LIVE_ALLOWED',
    'immediate_publish_allowed_env_var' => 'CUTOVER_IMMEDIATE_PUBLISH_ALLOWED',

    // D-17 (cont.) — the VALUES of those gate env vars, resolved at
    // config-load time. Commands read these via config() instead of env()
    // because env() reads outside config/*.php return the .env default in
    // cached-config mode (see d7d0e39 + 2026-05-31 cutover incident).
    //
    // The two-step safety property survives: setting CUTOVER_DRILL_ALLOWED=true
    // in the operator's shell only ARMS the gate; the command still needs to
    // explicitly read config('cutover.drill_allowed') (the deliberate-lookup
    // half of the D-17 design).
    //
    // Nullable on purpose: a missing env var binds to null, which the
    // commands' truthy-check treats as "gate closed" (refuses --live). Tests
    // override via config(['cutover.drill_allowed' => 'true']) instead of
    // putenv() so the override survives Laravel's config caching.
    'drill_allowed' => env('CUTOVER_DRILL_ALLOWED'),
    'disable_live_allowed' => env('CUTOVER_DISABLE_LIVE_ALLOWED'),
    'immediate_publish_allowed' => env('CUTOVER_IMMEDIATE_PUBLISH_ALLOWED'),

    // CUT-04 — mysqldump + gzip output directory.
    'backup_path' => env('CUTOVER_BACKUP_PATH', storage_path('app/cutover/backups')),

    // CUT-05 — drill-report markdown output directory.
    'drill_report_path' => env('CUTOVER_DRILL_REPORT_PATH', storage_path('app/cutover')),

    // D-18 — WordPress plugin slugs to deactivate + cron hooks to unschedule.
    // These are structural facts about the legacy install — not env-tunable.
    'legacy_plugins' => [
        'stock-updater',
        'woocommerce-bitrix24-integration',
    ],

    'legacy_cron_hooks' => [
        'stock_updater_daily_sync',
        'itgalaxy_bitrix24_send',
        'itgalaxy_bitrix24_status',
    ],

    // Daily `cutover:divergence-scan --live` 01:00 schedule toggle. Same
    // env()-broken-in-cached-config issue as pricing.undercut_schedule_enabled
    // (see that comment) — read via config() from routes/console.php.
    'divergence_scan_schedule_enabled' => (bool) env('CUTOVER_DIVERGENCE_SCAN_SCHEDULE_ENABLED', false),

    // CUT-04 — Woo WordPress DB connection credentials for mysqldump. Bound
    // at config-load time so the values survive `php artisan config:cache`
    // (same cached-config gotcha as d7d0e39 — env() reads outside config/*.php
    // return the .env default at cache-build time, not the live value). Read
    // via config('cutover.woo_db.*') from WooDbSnapshotter.
    'woo_db' => [
        'host' => env('WOO_DB_HOST', '127.0.0.1'),
        'username' => env('WOO_DB_USERNAME', 'root'),
        'password' => env('WOO_DB_PASSWORD', ''),
        'database' => env('WOO_DB_DATABASE', 'wordpress'),
    ],

    // CUT-05 — cutover write gate (D-16 STEP 1 — "flag readable" probe).
    // RollbackDrill reads this to report whether ops can flip the cutover
    // back to OFF. Nullable on purpose: the drill PASSES when the value is
    // non-null and FAILS when unset, so a missing key is itself diagnostic.
    // Bound here (not read via env() inline) to survive config:cache — same
    // cached-config rationale as the woo_db block above.
    'woo_write_enabled' => env('WOO_WRITE_ENABLED'),

];
