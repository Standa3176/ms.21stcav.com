<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 01 Task 1 — config/dashboard.php shape + defaults
|--------------------------------------------------------------------------
|
| Locks the dashboard widget / export tunables so Plans 07-02..07-04 can
| depend on the exact key names + default values.
*/

it('exposes snapshot_ttl_minutes as 15 by default', function (): void {
    expect(config('dashboard.snapshot_ttl_minutes'))->toBe(15);
});

it('exposes widget_poll_seconds as 60 by default', function (): void {
    expect(config('dashboard.widget_poll_seconds'))->toBe(60);
});

it('exposes refresh_interval_minutes as 5 by default', function (): void {
    expect(config('dashboard.refresh_interval_minutes'))->toBe(5);
});

it('exposes snapshot_retention_days as 30 by default', function (): void {
    expect(config('dashboard.snapshot_retention_days'))->toBe(30);
});

it('exposes csv_export_hard_cap as 100000 by default', function (): void {
    expect(config('dashboard.csv_export_hard_cap'))->toBe(100000);
});

it('exposes csv_export_queue_threshold as 10000 by default', function (): void {
    expect(config('dashboard.csv_export_queue_threshold'))->toBe(10000);
});

it('exposes global_search_debounce_ms as 300 by default', function (): void {
    expect(config('dashboard.global_search_debounce_ms'))->toBe(300);
});
