<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Stock-updater parity glue — schedule entries
|--------------------------------------------------------------------------
|
| Architecture invariant: the 5 new schedule entries from the parity glue
| ship MUST be registered in routes/console.php with the expected cron
| expressions. Catches drift if anyone "tidies up" the schedule and
| accidentally removes one.
*/

it('registers products:flag-missing-buy-price Mon-Fri at 07:15 Europe/London', function (): void {
    expectScheduled('products:flag-missing-buy-price', '15 7 * * 1-5');
});

it('registers suggestions:auto-apply Mon-Fri at 07:30 Europe/London', function (): void {
    expectScheduled('suggestions:auto-apply', '30 7 * * 1-5');
});

it('registers reports:supplier-sync-digest Mon-Fri at 08:00 Europe/London', function (): void {
    expectScheduled('reports:supplier-sync-digest', '0 8 * * 1-5');
});

it('registers a 09:00 woo:import-products safety-net retry Mon-Fri', function (): void {
    expectScheduledWithDescription('woo:import-products', '0 9 * * 1-5', 'safety-net');
});

it('registers a 09:05 supplier:db-sync safety-net retry Mon-Fri', function (): void {
    expectScheduledWithDescription('supplier:db-sync', '5 9 * * 1-5', 'safety-net');
});

// ── helpers ──

function expectScheduled(string $command, string $expectedCron): void
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())->first(
        fn ($event) => str_contains((string) ($event->command ?? ''), $command)
            && $event->expression === $expectedCron,
    );

    expect($match)->not->toBeNull("No schedule entry for {$command} with cron {$expectedCron}");
    expect($match->timezone)->toBe('Europe/London');
}

function expectScheduledWithDescription(string $command, string $expectedCron, string $descriptionMatch): void
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())->first(
        fn ($event) => str_contains((string) ($event->command ?? ''), $command)
            && $event->expression === $expectedCron
            && stripos((string) ($event->description ?? ''), $descriptionMatch) !== false,
    );

    expect($match)->not->toBeNull("No schedule entry for {$command} with cron {$expectedCron} and description containing '{$descriptionMatch}'");
    expect($match->timezone)->toBe('Europe/London');
}
