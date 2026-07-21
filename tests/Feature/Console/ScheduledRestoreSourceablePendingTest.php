<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Quick task 260721-apr Task 2 — daily auto-promote schedule
|--------------------------------------------------------------------------
| routes/console.php registers products:restore-sourceable-pending --live
| --push-to-woo daily at 07:25 Europe/London — AFTER the Mon-Fri 07:00
| supplier:db-sync and AFTER products:flag-missing-buy-price (07:15), so a
| product restored this morning already has today's buy_price and cannot be
| re-demoted by the same morning's run (churn-safe).
|
| 07:30 is deliberately NOT used: it is the documented contended slot
| (suggestions:auto-apply Mon-Fri 07:30) — see the suppliers:check-stale
| comment in routes/console.php.
|
| Safe to schedule before cutover: with WOO_WRITE_ENABLED=false the push is a
| shadow no-op (SyncDiff only) and becomes effective the moment writes are
| re-enabled. Tests inspect the registered schedule only — no Woo, no network.
*/

/** The registered restore-sourceable-pending scheduled event. */
function scheduledRestoreSourceableEvent(): ?object
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        if (str_contains((string) $event->command, 'restore-sourceable-pending')) {
            return $event;
        }
    }

    return null;
}

it('registers products:restore-sourceable-pending daily at 07:25 Europe/London', function (): void {
    $event = scheduledRestoreSourceableEvent();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('25 7 * * *')
        ->and($event->timezone)->toBe('Europe/London');
});

it('schedules the promote with --live AND --push-to-woo (a local-only restore stays hidden on Woo)', function (): void {
    $event = scheduledRestoreSourceableEvent();

    expect((string) $event->command)
        ->toContain('--live')
        ->toContain('--push-to-woo');
});

it('runs the promote without overlapping', function (): void {
    $event = scheduledRestoreSourceableEvent();

    expect($event->withoutOverlapping)->toBeTrue();
});

it('runs AFTER the supplier sync (07:00) and the missing-buy-price demotion (07:15)', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $minuteOfDay = static function (string $cron): int {
        [$minute, $hour] = explode(' ', $cron);

        return ((int) $hour * 60) + (int) $minute;
    };

    $promote = scheduledRestoreSourceableEvent();

    $dbSync = collect($schedule->events())->first(
        fn ($e) => str_contains((string) $e->command, 'supplier:db-sync') && $e->expression === '0 7 * * 1-5',
    );
    $flagMissing = collect($schedule->events())->first(
        fn ($e) => str_contains((string) $e->command, 'products:flag-missing-buy-price'),
    );

    expect($dbSync)->not->toBeNull()
        ->and($flagMissing)->not->toBeNull()
        ->and($minuteOfDay($promote->expression))->toBeGreaterThan($minuteOfDay($dbSync->expression))
        ->and($minuteOfDay($promote->expression))->toBeGreaterThan($minuteOfDay($flagMissing->expression));
});

it('documents that the push is a shadow no-op until WOO_WRITE_ENABLED=true', function (): void {
    $event = scheduledRestoreSourceableEvent();

    expect(strtolower((string) $event->description))
        ->toContain('shadow')
        ->toContain('woo_write_enabled');
});
