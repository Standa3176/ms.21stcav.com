<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| 260728-fwx T1 — spec:sync-taxonomy-cache nightly schedule invariant
|--------------------------------------------------------------------------
|
| The pa_* term-vocabulary cache must stay fresh for the SpecTaxonomyResolver
| (T2). Lock the nightly registration so a future "tidy up" of the schedule
| can't silently drop it (which would slowly stale the vocabulary and let Woo
| auto-create duplicate terms on create).
*/

it('registers spec:sync-taxonomy-cache nightly at 02:40 Europe/London withoutOverlapping', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())->first(
        fn ($event) => str_contains((string) ($event->command ?? ''), 'spec:sync-taxonomy-cache')
            && $event->expression === '40 2 * * *',
    );

    expect($match)->not->toBeNull('No nightly 02:40 schedule entry for spec:sync-taxonomy-cache');
    expect($match->timezone)->toBe('Europe/London');
});
