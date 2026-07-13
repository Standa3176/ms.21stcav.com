<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260713-add Task 2 — daily ad-optimisation cadence
|--------------------------------------------------------------------------
|
| The scheduled agents:run-ad-optimisation was dialled back from everySixHours
| to daily (07:00 Europe/London) to stop near-identical pending advice piling
| up. Combined with the skip-if-pending guard (Task 1), at most one new
| suggestion lands per day, only once the previous is actioned.
|
| Pins the cadence via the live Schedule facade + the routes/console.php source
| literal (config()-gated, withoutOverlapping() retained).
*/

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('schedule:list output contains agents:run-ad-optimisation', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('agents:run-ad-optimisation');
});

it('Schedule facade registers agents:run-ad-optimisation as a daily 07:00 Europe/London cron', function () {
    $schedule = app(Schedule::class);

    $matchingEvents = collect($schedule->events())
        ->filter(fn (ScheduledEvent $e) => str_contains((string) $e->command, 'agents:run-ad-optimisation'));

    expect($matchingEvents)->not->toBeEmpty();

    /** @var ScheduledEvent $event */
    $event = $matchingEvents->first();

    expect($event->expression)->toBe('0 7 * * *')
        ->and($event->timezone)->toBe('Europe/London');
});

it('the ad-optimisation schedule no longer uses everySixHours', function () {
    $source = (string) file_get_contents(base_path('routes/console.php'));

    // Extract the ad-optimisation schedule block and confirm the old cadence is gone.
    $adOptBlockStart = strpos($source, 'agents:run-ad-optimisation');
    expect($adOptBlockStart)->not->toBeFalse();

    $block = substr($source, (int) $adOptBlockStart, 300);
    expect($block)
        ->toContain("dailyAt('07:00')")
        ->not->toContain('everySixHours');
});
