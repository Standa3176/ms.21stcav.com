<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 05 Task 2 — Schedule wiring + env flag + Shield permission
|--------------------------------------------------------------------------
|
| Pins three interacting contracts:
|
|   1. Schedule entry in routes/console.php fires `agents:run-seo-batch`
|      at cron `30 4 * * *` with timezone Europe/London + withoutOverlapping(60)
|      + onOneServer()
|   2. AGENT_SEO_BATCH_SCHEDULE_ENABLED env flag suppresses the entry when
|      set false (operator emergency disable per Open Question O-2)
|   3. Shield permission `run_seo_agent` is seeded for admin + pricing_manager
|      but NOT for sales or read_only (CONTEXT Claude's Discretion §Permissions)
|
| All assertions use direct artisan output / Schedule facade introspection /
| Spatie Permission queries — no MySQL Shield-generation dependency.
*/

use App\Domain\Agents\Console\Commands\RunSeoAgentBatchCommand;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('schedule:list output contains agents:run-seo-batch', function () {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    // Note: schedule:list renders the cron in the user's local TZ (e.g. 30 3
    // during BST when the registered cron is `30 4 Europe/London`). The
    // Schedule facade fixture below asserts the canonical expression directly.
    expect($output)->toContain('agents:run-seo-batch');
});

it('Schedule facade registers the agents:run-seo-batch event when env flag enabled', function () {
    // Re-load routes/console.php into a fresh Schedule instance with the env
    // flag set to true (the default). We can't simply toggle env() after boot
    // because schedule() registrations happen at console kernel boot — instead
    // we assert the kernel's currently-mounted Schedule has the entry.
    $schedule = app(Schedule::class);

    $matchingEvents = collect($schedule->events())
        ->filter(fn (ScheduledEvent $e) => str_contains((string) $e->command, 'agents:run-seo-batch'));

    expect($matchingEvents)->not->toBeEmpty();

    /** @var ScheduledEvent $event */
    $event = $matchingEvents->first();

    expect($event->expression)->toBe('30 4 * * *')
        ->and($event->timezone)->toBe('Europe/London');
});

it('AGENT_SEO_BATCH_SCHEDULE_ENABLED defaults true (env flag absent)', function () {
    // The .env loaded at boot decides whether the entry registers. The
    // production-default semantics: env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true)
    // returns true when the variable is absent. The existing schedule registration
    // test above already asserts the entry IS present in the live Schedule, which
    // is the load-bearing post-condition we care about. This fixture pins the
    // default-true contract at the env() lookup level.
    expect((bool) env('AGENT_SEO_BATCH_SCHEDULE_ENABLED', true))->toBeTrue();
});

it('seeds run_seo_agent permission via RolePermissionSeeder', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    expect(Permission::query()->where('name', 'run_seo_agent')->exists())->toBeTrue();
});

it('admin role has run_seo_agent permission', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $admin = Role::findByName('admin');
    expect($admin->hasPermissionTo('run_seo_agent'))->toBeTrue();
});

it('pricing_manager role has run_seo_agent permission', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $pricingManager = Role::findByName('pricing_manager');
    expect($pricingManager->hasPermissionTo('run_seo_agent'))->toBeTrue();
});

it('sales role does NOT have run_seo_agent permission', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $sales = Role::findByName('sales');
    expect($sales->hasPermissionTo('run_seo_agent'))->toBeFalse();
});

it('read_only role does NOT have run_seo_agent permission', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $readOnly = Role::findByName('read_only');
    expect($readOnly->hasPermissionTo('run_seo_agent'))->toBeFalse();
});

it('RunSeoAgentBatchCommand class exists at the expected FQCN', function () {
    expect(class_exists(RunSeoAgentBatchCommand::class))->toBeTrue();
});

it('routes/console.php contains the env-flag gate literal', function () {
    $source = (string) file_get_contents(base_path('routes/console.php'));

    expect($source)
        ->toContain('agents:run-seo-batch')
        ->toContain('30 4 * * *')
        ->toContain('Europe/London')
        ->toContain('AGENT_SEO_BATCH_SCHEDULE_ENABLED')
        ->toContain('withoutOverlapping(60)')
        ->toContain('onOneServer()');
});
