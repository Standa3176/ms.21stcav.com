<?php

declare(strict_types=1);

use App\Domain\Sync\Models\SyncError;
use App\Domain\Sync\Models\SyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| PruneSyncErrorsCommand (D-07) — Plan 02-05 Task 2
|--------------------------------------------------------------------------
|
| Verifies:
|  P1. Rows older than --days are deleted, recent rows preserved.
|  P2. A meta-audit row (spatie/activitylog, log_name=system,
|      description=sync-errors.pruned) is written with deleted_count,
|      cutoff_date, and days properties (D-09 compliance).
|  P3. --days=0 is a graceful no-op (safety guard against accidental wipe).
|  P4. The default --days (omitted flag) is 90 per D-07 convention.
|  P5. routes/console.php schedules sync-errors:prune at 03:20 with
|      withoutOverlapping + onOneServer — verified through Laravel's
|      scheduler introspection API.
|
| correlation_id threading: BaseCommand-style threading isn't used by the
| Phase 1 Prune* commands (they extend Illuminate\Console\Command) so the
| Auditor row's correlation_id may be null when invoked directly from tests
| without a pre-seeded Context. Tests that need it seed Context::add
| explicitly in beforeEach.
*/

beforeEach(function () {
    // Plain UUID (no prefix) — integration_events.correlation_id is VARCHAR(36).
    // Same convention applied across Phase 2 test files.
    Context::add('correlation_id', (string) Str::uuid());
});

it('P1: deletes sync_errors older than --days and retains newer rows', function () {
    $run = SyncRun::factory()->create();

    $old = SyncError::create([
        'sync_run_id' => $run->id,
        'sku' => 'OLD-1',
        'error_class' => 'Test',
        'error_message' => 'old row',
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now()->subDays(120),
    ]);

    $recent = SyncError::create([
        'sync_run_id' => $run->id,
        'sku' => 'NEW-1',
        'error_class' => 'Test',
        'error_message' => 'recent row',
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now()->subDays(10),
    ]);

    $this->artisan('sync-errors:prune', ['--days' => 90])
        ->expectsOutputToContain('Pruned 1 sync_errors rows')
        ->assertExitCode(0);

    expect(SyncError::where('sku', 'OLD-1')->exists())->toBeFalse();
    expect(SyncError::where('sku', 'NEW-1')->exists())->toBeTrue();
});

it('P2: writes an Auditor meta-audit row (sync-errors.pruned) with deleted_count + cutoff_date + days', function () {
    $run = SyncRun::factory()->create();

    // Seed 3 old rows that will be pruned
    foreach (['A', 'B', 'C'] as $sku) {
        SyncError::create([
            'sync_run_id' => $run->id,
            'sku' => "OLD-{$sku}",
            'error_class' => 'Test',
            'error_message' => 'old',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now()->subDays(100),
        ]);
    }

    $this->artisan('sync-errors:prune', ['--days' => 30])->assertExitCode(0);

    $activity = Activity::where('log_name', 'system')
        ->where('description', 'sync-errors.pruned')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties)->toHaveKey('deleted_count');
    expect($activity->properties['deleted_count'])->toBe(3);
    expect($activity->properties)->toHaveKey('cutoff_date');
    expect($activity->properties)->toHaveKey('days');
    expect($activity->properties['days'])->toBe(30);
});

it('P3: --days=0 is a graceful no-op (safety guard)', function () {
    $run = SyncRun::factory()->create();

    // Seed a row that would be pruned if --days=0 treated as "delete everything"
    SyncError::create([
        'sync_run_id' => $run->id,
        'sku' => 'KEEP-ME',
        'error_class' => 'Test',
        'error_message' => 'do not delete',
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now()->subDays(1),
    ]);

    $this->artisan('sync-errors:prune', ['--days' => 0])
        ->expectsOutputToContain('aborted')
        ->assertExitCode(0);  // still SUCCESS — graceful no-op

    expect(SyncError::where('sku', 'KEEP-ME')->exists())->toBeTrue();

    // Audit trail captures the skip reason (D-09)
    expect(
        Activity::where('log_name', 'system')
            ->where('description', 'sync-errors.prune.skipped')
            ->exists()
    )->toBeTrue();
});

it('P4: default --days is 90 per D-07 convention', function () {
    $run = SyncRun::factory()->create();

    SyncError::create([
        'sync_run_id' => $run->id,
        'sku' => 'EIGHTY-DAY',  // 80 days old — INSIDE the 90-day window (kept)
        'error_class' => 'Test',
        'error_message' => 'within window',
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now()->subDays(80),
    ]);

    SyncError::create([
        'sync_run_id' => $run->id,
        'sku' => 'HUNDRED-DAY',  // 100 days old — OUTSIDE 90-day window (pruned)
        'error_class' => 'Test',
        'error_message' => 'outside window',
        'correlation_id' => (string) Str::uuid(),
        'created_at' => now()->subDays(100),
    ]);

    // No --days flag — should use the D-07 default of 90
    $this->artisan('sync-errors:prune')->assertExitCode(0);

    expect(SyncError::where('sku', 'EIGHTY-DAY')->exists())->toBeTrue();
    expect(SyncError::where('sku', 'HUNDRED-DAY')->exists())->toBeFalse();
});

it('P5: routes/console.php schedules sync-errors:prune at 03:20 with withoutOverlapping + onOneServer', function () {
    // Contents-level assertion — routes/console.php is the source of truth.
    $contents = file_get_contents(base_path('routes/console.php'));
    expect($contents)->toContain("sync-errors:prune");
    expect($contents)->toMatch('/sync-errors:prune[\s\S]{0,400}?dailyAt\(\s*[\'"]03:20[\'"]\s*\)/');
    expect($contents)->toMatch('/sync-errors:prune[\s\S]{0,500}?withoutOverlapping/');
    expect($contents)->toMatch('/sync-errors:prune[\s\S]{0,500}?onOneServer/');

    // Runtime-level assertion — the scheduler registered an event referencing
    // the command. This catches someone accidentally commenting out the
    // Schedule::command(...) call while leaving the string in the file.
    $schedule = app(Schedule::class);
    $commandsOnSchedule = collect($schedule->events())
        ->map(fn ($e) => $e->command ?? $e->description)
        ->filter();

    $found = $commandsOnSchedule->contains(fn ($c) => str_contains((string) $c, 'sync-errors:prune'));
    expect($found)->toBeTrue('Expected `sync-errors:prune` to be scheduled in routes/console.php');
});
