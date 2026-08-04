<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Quick task 260728-fwx T0 — nightly cutover:auto-sync must NOT push categories
|--------------------------------------------------------------------------
| D2(a): routes/console.php registers cutover:auto-sync at 23:00 with an
| explicit --field allow-list. The operator moved the FacetWP category cleanup
| to the WP side; the app pushing local `categories` over Woo on the nightly
| self-heal would silently REVERT that cleanup at cutover. So the schedule's
| field set must include stock_quantity + buy_price and must NOT include
| category_id.
|
| These tests inspect the registered schedule only — no Woo, no network,
| driver-portable (no DB touched).
*/

/** The registered cutover:auto-sync scheduled event. */
function scheduledCutoverAutoSyncEvent(): ?object
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        if (str_contains((string) $event->command, 'cutover:auto-sync')) {
            return $event;
        }
    }

    return null;
}

it('registers cutover:auto-sync nightly at 23:00 Europe/London', function (): void {
    $event = scheduledCutoverAutoSyncEvent();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 23 * * *')
        ->and($event->timezone)->toBe('Europe/London');
});

it('pushes stock_quantity and buy_price on the nightly self-heal', function (): void {
    $event = scheduledCutoverAutoSyncEvent();

    expect((string) $event->command)
        ->toContain('--field=')
        ->toContain('stock_quantity')
        ->toContain('buy_price');
});

it('does NOT push category_id (would revert the WP-side FacetWP category cleanup — 260728-fwx D2(a))', function (): void {
    $event = scheduledCutoverAutoSyncEvent();

    // Isolate the --field argument and assert category_id is absent from it.
    // Asserting on the field arg specifically (not the whole command string)
    // guards against a false pass if "category" ever appears elsewhere.
    preg_match('/--field=(\S+)/', (string) $event->command, $matches);
    $fields = $matches[1] ?? '';
    $fieldSet = explode(',', $fields);

    expect($fields)->not->toBe('')
        ->and($fieldSet)->not->toContain('category_id')
        ->and((string) $event->command)->not->toContain('category_id');
});

it('runs the self-heal without overlapping', function (): void {
    $event = scheduledCutoverAutoSyncEvent();

    expect($event->withoutOverlapping)->toBeTrue();
});
