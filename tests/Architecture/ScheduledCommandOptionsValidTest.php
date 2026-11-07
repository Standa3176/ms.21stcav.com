<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Symfony\Component\Console\Input\StringInput;

/*
|--------------------------------------------------------------------------
| Quick task 261107-x3l — scheduled artisan options must bind
|--------------------------------------------------------------------------
|
| `Schedule::command('x', ['--flag' => true])` compiles to `--flag='1'`.
| Symfony rejects that for a VALUE_NONE option ("does not accept a value"),
| so the job fails on every tick. products:draft-from-suggestions never ran
| from 035fb9b to 2026-11-07 for exactly this reason. VALUE_NONE flags must be
| bare list items: ['--flag'].
|
| This guard binds EVERY scheduled artisan event against its command's real
| InputDefinition, so the whole class of bug fails the build.
*/

function optsValidEvents(): array
{
    config()->set('pricing.undercut_schedule_enabled', true);
    config()->set('b2b.storefront.sync_schedule_enabled', true);
    config()->set('agents.seo_batch_schedule_enabled', true);
    config()->set('agents.ad_optimisation_schedule_enabled', true);
    config()->set('cutover.divergence_scan_schedule_enabled', true);
    config()->set('cutover.sell_price_reconcile_enabled', true);

    $schedule = new Schedule;
    app()->instance(Schedule::class, $schedule);
    ScheduleFacade::clearResolvedInstance(Schedule::class);

    require base_path('routes/console.php');

    return $schedule->events();
}

function optsValidCommandFor(string $name): string
{
    foreach (optsValidEvents() as $event) {
        $tail = optsValidTail($event);
        if ($tail !== null && str_starts_with($tail, $name.' ')) {
            return $tail;
        }
    }

    return '';
}

/** Everything after the `artisan` token, or null for non-artisan events. */
function optsValidTail(Event $event): ?string
{
    $command = (string) $event->command;
    if ($command === '' || ! preg_match('/artisan[\'"]?\s+(.+)$/s', $command, $m)) {
        return null;
    }

    return trim($m[1]);
}

it('binds every scheduled artisan event against its command definition', function (): void {
    $checked = 0;
    $failures = [];
    $all = Artisan::all();

    foreach (optsValidEvents() as $event) {
        $tail = optsValidTail($event);
        if ($tail === null) {
            continue;
        }

        $input = new StringInput($tail);
        $name = (string) $input->getFirstArgument();

        if (! isset($all[$name])) {
            $failures[] = "unknown command [{$name}] in: {$tail}";

            continue;
        }

        $command = $all[$name];
        $command->mergeApplicationDefinition();

        try {
            $input->bind($command->getDefinition());
            $checked++;
        } catch (Throwable $e) {
            $failures[] = "{$name}: {$e->getMessage()} (compiled: {$tail})";
        }
    }

    expect($checked)->toBeGreaterThan(0);
    expect($failures)->toBe([], implode("\n", $failures));
});

it('compiles products:draft-from-suggestions with bare flags', function (): void {
    $cmd = optsValidCommandFor('products:draft-from-suggestions');

    expect($cmd)->not->toBe('');
    foreach (['--auto-approve', '--create-missing-brands', '--source-images', '--no-confirm'] as $flag) {
        expect($cmd)->toMatch('/'.preg_quote($flag, '/').'(\s|$)/');
    }
    expect($cmd)->toContain('--min-competitors=2');
    expect($cmd)->toContain('--max-competitors=3');
    expect($cmd)->toContain('--limit=');
});

it('compiles products:restore-sourceable-pending with bare flags', function (): void {
    $cmd = optsValidCommandFor('products:restore-sourceable-pending');

    expect($cmd)->not->toBe('');
    foreach (['--live', '--push-to-woo', '--include-listed-out-of-stock'] as $flag) {
        expect($cmd)->toMatch('/'.preg_quote($flag, '/').'(\s|$)/');
    }
});
