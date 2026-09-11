<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Quick task 260911 — every scheduled command runs on UK time
|--------------------------------------------------------------------------
|
| config('app.timezone') is UTC, and should stay that way: timestamps are
| STORED in UTC and changing that would be a far larger and riskier edit than
| anything it fixes.
|
| The consequence is that a Schedule entry without an explicit ->timezone()
| silently inherits UTC, so `dailyAt('03:00')` means 03:00 UTC — 04:00 London
| for the seven months of BST.
|
| routes/console.php staggers work in ten-minute slots. Four entries were
| missing the zone and slid an hour out of position against the other 47 every
| summer, collapsing the gaps the stagger exists to create.
| products:reconcile-woo-maintenance was the one that mattered: it writes to
| Woo and drifted toward the Mon/Thu auto-create publish, where both compete
| for the same 60/min write throttle.
|
| Reading the resolved Schedule rather than grepping the file means a new
| entry added in any other service provider is caught too.
*/

it('runs every scheduled command on Europe/London, never the UTC default', function (): void {
    $events = app(Schedule::class)->events();

    expect($events)->not->toBeEmpty();

    $wrong = [];
    foreach ($events as $event) {
        if ($event->timezone === null) {
            $wrong[] = [$event->command ?? $event->description ?? '?', 'no timezone set'];

            continue;
        }

        $tz = $event->timezone instanceof DateTimeZone
            ? $event->timezone->getName()
            : (string) $event->timezone;

        if ($tz !== 'Europe/London') {
            $wrong[] = [$event->command ?? $event->description ?? '?', $tz];
        }
    }

    $detail = implode("\n", array_map(
        static fn (array $r): string => '  - '.trim((string) $r[0]).' → '.$r[1],
        $wrong,
    ));

    expect($wrong)->toBe([], "Scheduled commands not on Europe/London:\n".$detail
        ."\n\nAdd ->timezone('Europe/London'). Without it the entry inherits "
        ."config('app.timezone') = UTC and drifts an hour every BST.");
});

it('keeps app.timezone as UTC, because storage must stay UTC', function (): void {
    // The fix for schedule drift is per-entry, NOT flipping this. Changing it
    // would reinterpret every stored timestamp comparison in the app.
    expect(config('app.timezone'))->toBe('UTC');
});
