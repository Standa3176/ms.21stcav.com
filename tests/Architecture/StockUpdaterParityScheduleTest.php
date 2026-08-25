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

// ── 260825-n5v — pricing monitoring must stay scheduled ───────────────────
//
// Every pricing fault found in August 2026 was found by a human deciding to go
// looking: the 5,319 lost price pushes surfaced only from reading failed_jobs by
// hand, and the CP4 homonym had been live for weeks. Both audit tools EXISTED
// and neither was scheduled. A command that is not scheduled is not monitoring,
// and these three tests are what stop that happening again quietly.

it('registers pricing:health-check daily at 09:30 Europe/London', function (): void {
    // The STATIC check — a product that is wrong and never moves is invisible to
    // any movement audit, which is exactly how CP4 survived for weeks.
    expectScheduled('pricing:health-check', '30 9 * * *');
});

it('registers pricing:audit-movements daily at 09:35 Europe/London', function (): void {
    // The DYNAMIC check — were today's CHANGES correct?
    expectScheduled('pricing:audit-movements', '35 9 * * *');
});

it('registers pricing:review-ceiling-blocks weekly on Monday', function (): void {
    // Weekly, not daily: a review list to work through, not an alarm. Daily
    // would train people to skim it, and then to skim the alarms too.
    expectScheduled('pricing:review-ceiling-blocks', '45 9 * * 1');
});

it('keeps the pricing health check subscribed to alerts', function (): void {
    // --notify is the difference between a log line nobody reads and an email.
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $match = collect($schedule->events())->first(
        fn ($event) => str_contains((string) ($event->command ?? ''), 'pricing:health-check'),
    );

    expect($match)->not->toBeNull()
        ->and((string) $match->command)->toContain('--notify');
});
