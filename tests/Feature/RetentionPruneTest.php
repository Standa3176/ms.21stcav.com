<?php

declare(strict_types=1);

use App\Domain\Sync\Models\SyncDiff;
use App\Foundation\Integration\Models\IntegrationEvent;
use Spatie\Activitylog\Models\Activity;

it('prunes activity_log rows older than --days and leaves recent ones', function () {
    // Seed 2 old rows + 1 recent row
    activity('system')->log('old-1');
    activity('system')->log('old-2');
    Activity::query()->update(['created_at' => now()->subDays(400)]); // age them

    activity('system')->log('recent');

    $this->artisan('activitylog:prune', ['--days' => 365])
        ->expectsOutputToContain('Pruned 2 activity_log rows')
        ->assertExitCode(0);

    expect(Activity::where('description', 'recent')->count())->toBe(1);
    // Recent row + meta-audit row about the prune itself = at least 2
    expect(Activity::count())->toBeGreaterThanOrEqual(2);
});

it('writes a meta-audit row when activity_log is pruned (D-09)', function () {
    $this->artisan('activitylog:prune', ['--days' => 365])->assertExitCode(0);

    expect(
        Activity::where('log_name', 'system')
            ->where('description', 'activitylog.pruned')
            ->exists()
    )->toBeTrue();
});

it('prunes integration_events older than --days and writes meta-audit', function () {
    \DB::table('integration_events')->insert([
        'channel' => 'woo', 'direction' => 'outbound', 'operation' => 'old',
        'endpoint' => '/', 'method' => 'GET', 'status' => 'success',
        'correlation_id' => 'cid-old', 'created_at' => now()->subDays(100),
    ]);
    \DB::table('integration_events')->insert([
        'channel' => 'woo', 'direction' => 'outbound', 'operation' => 'recent',
        'endpoint' => '/', 'method' => 'GET', 'status' => 'success',
        'correlation_id' => 'cid-recent', 'created_at' => now()->subDays(10),
    ]);

    $this->artisan('integration-events:prune', ['--days' => 90])->assertExitCode(0);

    expect(IntegrationEvent::where('operation', 'old')->count())->toBe(0);
    expect(IntegrationEvent::where('operation', 'recent')->count())->toBe(1);
    expect(Activity::where('description', 'integration-events.pruned')->exists())->toBeTrue();
});

it('SKIPS sync_diffs prune while WOO_WRITE_ENABLED=false (D-08 / Pitfall L)', function () {
    config(['services.woo.write_enabled' => false]);

    // Seed a 100-day-old SyncDiff (well beyond 30-day retention threshold)
    SyncDiff::create([
        'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/1',
        'payload' => ['x' => 1], 'correlation_id' => 'cid-1',
        'created_at' => now()->subDays(100),
    ]);

    $this->artisan('sync-diffs:prune')
        ->expectsOutputToContain('Skipped')
        ->assertExitCode(0);

    // Row MUST still exist — parity evidence preserved
    expect(SyncDiff::count())->toBe(1);

    // Meta-audit records the skip reason
    expect(Activity::where('description', 'sync-diffs.prune.skipped')->exists())->toBeTrue();
});

it('prunes sync_diffs older than 30 days when WOO_WRITE_ENABLED=true (post-cutover)', function () {
    config(['services.woo.write_enabled' => true]);

    // Old, applied — should be deleted
    SyncDiff::create([
        'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/1',
        'payload' => [], 'correlation_id' => 'cid-old-applied',
        'created_at' => now()->subDays(60), 'applied_at' => now()->subDays(55),
        'status' => 'applied',
    ]);

    // Old, but NOT applied — must be kept for investigation
    SyncDiff::create([
        'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/2',
        'payload' => [], 'correlation_id' => 'cid-old-pending',
        'created_at' => now()->subDays(60), 'applied_at' => null,
        'status' => 'pending',
    ]);

    // Recent, applied — must be kept (within retention window)
    SyncDiff::create([
        'channel' => 'woo', 'method' => 'PUT', 'endpoint' => 'products/3',
        'payload' => [], 'correlation_id' => 'cid-recent',
        'created_at' => now()->subDays(5), 'applied_at' => now()->subDays(3),
        'status' => 'applied',
    ]);

    $this->artisan('sync-diffs:prune')->assertExitCode(0);

    expect(SyncDiff::where('correlation_id', 'cid-old-applied')->count())->toBe(0);
    expect(SyncDiff::where('correlation_id', 'cid-old-pending')->count())->toBe(1); // un-applied kept
    expect(SyncDiff::where('correlation_id', 'cid-recent')->count())->toBe(1);

    expect(Activity::where('description', 'sync-diffs.pruned')->exists())->toBeTrue();
});

it('schedules all 3 prune commands in routes/console.php', function () {
    $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
    $commandsOnSchedule = collect($schedule->events())
        ->map(fn ($e) => $e->command ?? $e->description)
        ->filter();

    $expected = [
        'activitylog:prune',
        'integration-events:prune',
        'sync-diffs:prune',
    ];

    foreach ($expected as $needle) {
        $found = $commandsOnSchedule->contains(fn ($c) => str_contains((string) $c, $needle));
        expect($found)->toBeTrue("Expected `{$needle}` to be scheduled in routes/console.php");
    }
});

it('routes/console.php file uses withoutOverlapping on each prune', function () {
    $contents = file_get_contents(base_path('routes/console.php'));
    expect($contents)->toContain('withoutOverlapping');

    // Each of the 3 commands should have withoutOverlapping declared
    $withoutOverlappingCount = substr_count($contents, 'withoutOverlapping');
    expect($withoutOverlappingCount)->toBeGreaterThanOrEqual(3);
});

it('PruneActivityLogCommand honours custom --days argument', function () {
    activity('system')->log('very-old');
    Activity::query()->update(['created_at' => now()->subDays(50)]);

    // --days=30 should catch the 50-day-old row
    $this->artisan('activitylog:prune', ['--days' => 30])->assertExitCode(0);

    expect(Activity::where('description', 'very-old')->count())->toBe(0);
});
