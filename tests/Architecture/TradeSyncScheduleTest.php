<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/*
|--------------------------------------------------------------------------
| Quick task 260913-ik1 — trade:sync runs daily, and runs AFTER the undercut
|--------------------------------------------------------------------------
|
| trade:sync had never been scheduled. It was run once by hand on 2026-09-11,
| which left two faults that both grew every day:
|
|   1. Products created afterwards had NO trade price at all. SKU 47132 was
|      created 2026-09-13 11:38 and carried no b2bking meta of any kind — the
|      operator found it on the storefront showing a standard price only.
|   2. Existing trade prices were computed against pre-repricing retail. The
|      08:00 undercut moved 2,205 retail prices on 2026-09-13 alone.
|
| ORDERING IS THE THING THIS FILE PROTECTS. Trade price is derived FROM retail
| — a discount off products.sell_price, floored at cost+6%, capped at cost+15%,
| and never above retail. Publish it before the undercut and every trade price
| on the storefront is one day stale by construction, which is invisible: the
| prices look plausible, they are simply computed from the wrong number.
|
| A future edit that moves either command, or re-times the other, has to come
| through here and say so.
*/

/**
 * A Schedule with BOTH pricing flags forced on.
 *
 * Why this is needed: `pricing.undercut_schedule_enabled` defaults to FALSE in
 * the repo (opt-in via env, true only on prod), so in the test environment the
 * undercut is never registered. An ordering assertion that simply skips when it
 * cannot find the undercut is worthless — it passes whatever the times are.
 * Verified 2026-09-13 by mutation: moving trade:sync to 07:30, BEFORE the
 * undercut, and watching the first version of this file still report 4 passed.
 *
 * So force both flags on and re-register the console routes onto a fresh
 * Schedule, which gives the real resolved events regardless of env defaults.
 */
function pricingSchedule(): Schedule
{
    config()->set('pricing.undercut_schedule_enabled', true);
    config()->set('b2b.storefront.sync_schedule_enabled', true);

    $schedule = new Schedule;
    app()->instance(Schedule::class, $schedule);

    // The Schedule FACADE caches its resolved instance, so re-binding the
    // container alone leaves Schedule::command() writing to the old object and
    // the fresh Schedule comes back empty.
    ScheduleFacade::clearResolvedInstance(Schedule::class);

    require base_path('routes/console.php');

    return $schedule;
}

function eventMatching(string $needle): ?Event
{
    foreach (pricingSchedule()->events() as $event) {
        if (str_contains((string) $event->command, $needle)) {
            return $event;
        }
    }

    return null;
}

/** Minutes past midnight for a daily event — the whole of the ordering. */
function dailyMinutes(Event $event): int
{
    [$min, $hour] = array_pad(explode(' ', $event->expression), 2, '0');

    return ((int) $hour * 60) + (int) $min;
}

it('schedules trade:sync every day', function (): void {
    $event = eventMatching('trade:sync');

    expect($event)->not->toBeNull(
        'trade:sync is not scheduled. Without it, every product created since '
        .'the last manual run has NO trade price, and every existing trade '
        .'price is computed against pre-repricing retail.'
    );
});

it('publishes trade prices AFTER the retail repricing, never before', function (): void {
    $undercut = eventMatching('pricing:undercut-competitors');
    $trade = eventMatching('trade:sync');

    expect($undercut)->not->toBeNull('the undercut should be registered once its flag is forced on');
    expect($trade)->not->toBeNull();

    expect(dailyMinutes($trade))->toBeGreaterThan(
        dailyMinutes($undercut),
        'trade:sync must run AFTER pricing:undercut-competitors. Trade price is '
        .'a discount off retail, so publishing it first computes every trade '
        .'price from yesterday retail values — silently, because the numbers '
        .'still look reasonable.'
    );
});

it('runs trade:sync live and changed-only', function (): void {
    $command = (string) eventMatching('trade:sync')->command;

    // --live: a dry-run on a schedule writes nothing and looks healthy forever.
    expect($command)->toContain('--live');

    // --changed-only: without it all ~4,333 published products are written
    // nightly. At the 60/min Woo throttle that is 72 minutes of write budget
    // competing with the retail pushes, for a value that moves on a few
    // hundred. --changed-only spends un-throttled GETs instead.
    expect($command)->toContain('--changed-only');
});

it('guards against a long run colliding with the next day', function (): void {
    $event = eventMatching('trade:sync');

    expect($event->withoutOverlapping)->toBeTrue(
        'A --changed-only sweep is GET-bound over the whole catalogue and can '
        .'run long. Two overlapping runs would double the Woo write pressure.'
    );
});
