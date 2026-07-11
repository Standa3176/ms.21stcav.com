<?php

declare(strict_types=1);

use App\Console\Commands\DraftFromSuggestionsCommand;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Quick task 260711-aps Task 4 — twice-weekly scheduled auto-publish
|--------------------------------------------------------------------------
| routes/console.php registers products:draft-from-suggestions with the
| 2/3-competitor band + --auto-approve, twice weekly (Mon+Thu 05:00 London).
| The batch cap is tunable via config('product_auto_create.scheduled_publish_limit')
| (env AUTO_PUBLISH_SCHEDULED_LIMIT, default 25). Tests never hit real Woo — they
| inspect the registered schedule and the (already-covered) selection seam.
*/

/** The registered draft-from-suggestions scheduled event (twice-weekly auto-publish). */
function scheduledAutoPublishEvent(): ?object
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        if (str_contains((string) $event->command, 'draft-from-suggestions')
            && str_contains((string) $event->command, '--auto-approve')) {
            return $event;
        }
    }

    return null;
}

it('registers the twice-weekly auto-publish schedule (Mon+Thu 05:00) with the 2/3-competitor args', function (): void {
    $event = scheduledAutoPublishEvent();

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 5 * * 1,4');
    expect($event->command)
        ->toContain('--min-competitors=2')
        ->toContain('--max-competitors=3')
        ->toContain('--create-missing-brands')
        ->toContain('--source-images')
        ->toContain('--no-confirm');
});

it('runs the auto-publish schedule without overlapping', function (): void {
    $event = scheduledAutoPublishEvent();

    expect($event->withoutOverlapping)->toBeTrue();
});

it('exposes a tunable batch cap defaulting to 25', function (): void {
    expect(config('product_auto_create.scheduled_publish_limit'))->toBe(25);
});

it('the scheduled 2..3 arg-set selects only the 2- and 3-competitor pending SKUs', function (): void {
    foreach ([1 => 'ONE', 2 => 'TWO', 3 => 'THREE', 4 => 'FOUR'] as $competitors => $sku) {
        Suggestion::create([
            'kind' => 'new_product_opportunity',
            'status' => 'pending',
            'evidence' => ['sku' => $sku, 'supporting_competitors' => $competitors],
            'proposed_at' => now(),
        ]);
    }

    // Mirrors the scheduled arg-set: --min-competitors=2 --max-competitors=3.
    $skus = app(DraftFromSuggestionsCommand::class)
        ->pendingOpportunitySuggestionsQuery(2, 3)
        ->get()
        ->map(fn ($row) => (string) (json_decode((string) $row->evidence, true)['sku'] ?? ''))
        ->sort()
        ->values()
        ->all();

    expect($skus)->toBe(['THREE', 'TWO']);
});
