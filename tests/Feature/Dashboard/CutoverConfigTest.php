<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 01 Task 1 — config/cutover.php shape + defaults
|--------------------------------------------------------------------------
|
| Locks the cutover tunables in place so Plans 07-05 + 07-06 can depend on
| the exact key names + default values. Breaking this test means a later
| plan's command reads a differently-named key and silently fails closed.
*/

it('exposes parity_threshold_percent as 99 by default', function (): void {
    expect(config('cutover.parity_threshold_percent'))->toBe(99);
});

it('exposes parity_window_days as 7 by default', function (): void {
    expect(config('cutover.parity_window_days'))->toBe(7);
});

it('exposes the three env-var gate NAMES (not values)', function (): void {
    expect(config('cutover.drill_allowed_env_var'))->toBe('CUTOVER_DRILL_ALLOWED');
    expect(config('cutover.disable_live_allowed_env_var'))->toBe('CUTOVER_DISABLE_LIVE_ALLOWED');
    expect(config('cutover.immediate_publish_allowed_env_var'))->toBe('CUTOVER_IMMEDIATE_PUBLISH_ALLOWED');
});

it('exposes non-empty backup_path + drill_report_path defaults', function (): void {
    expect(config('cutover.backup_path'))->toBeString()->not->toBeEmpty();
    expect(config('cutover.drill_report_path'))->toBeString()->not->toBeEmpty();
});

it('lists the two legacy WordPress plugin slugs for D-18 disable sequence', function (): void {
    $plugins = config('cutover.legacy_plugins');

    expect($plugins)->toBeArray();
    expect($plugins)->toContain('stock-updater');
    expect($plugins)->toContain('woocommerce-bitrix24-integration');
});

it('lists the three legacy cron hook names for D-18 unschedule sequence', function (): void {
    $hooks = config('cutover.legacy_cron_hooks');

    expect($hooks)->toBeArray();
    expect($hooks)->toContain('stock_updater_daily_sync');
    expect($hooks)->toContain('itgalaxy_bitrix24_send');
    expect($hooks)->toContain('itgalaxy_bitrix24_status');
});
