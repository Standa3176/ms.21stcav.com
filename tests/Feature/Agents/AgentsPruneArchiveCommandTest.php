<?php

declare(strict_types=1);

use App\Domain\Agents\Models\AgentRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 05 Task 3 — AgentsPruneArchiveCommandTest (D-07)
|--------------------------------------------------------------------------
|
| Tests the 8 plan-spec behaviours:
|   1. Default 1825 days — exports rows older than 5 years
|   2. Custom --days=180 (ops override)
|   3. Gzip archive written; gzdecode produces valid JSON
|   4. Rows deleted after archive write
|   5. activity_log row written with description=agent_run_archived
|   6. No rows old enough → exits 0 with "nothing to archive"
|   7. --dry-run logs intent without writing/deleting
|   8. Correlation_id threads onto batch_uuid
*/

beforeEach(function (): void {
    Storage::fake('local');
});

it('default 1825 days picks rows older than 5 years (Test 1)', function (): void {
    AgentRun::factory()->create([
        'completed_at' => now()->subDays(2000),
    ]);
    AgentRun::factory()->create([
        'completed_at' => now()->subDays(100),
    ]);

    $exitCode = Artisan::call('agents:prune-archive');

    expect($exitCode)->toBe(0);
    expect(AgentRun::count())->toBe(1);  // only the recent row remains
});

it('custom --days=180 honours ops override (Test 2)', function (): void {
    AgentRun::factory()->create([
        'completed_at' => now()->subDays(200),
    ]);
    AgentRun::factory()->create([
        'completed_at' => now()->subDays(50),
    ]);

    $exitCode = Artisan::call('agents:prune-archive', ['--days' => 180]);

    expect($exitCode)->toBe(0);
    expect(AgentRun::count())->toBe(1);
});

it('writes a valid gzipped JSON archive (Test 3)', function (): void {
    AgentRun::factory()->create([
        'completed_at' => now()->subDays(2000),
        'kind' => 'echo',
    ]);

    Artisan::call('agents:prune-archive');

    $files = Storage::disk('local')->allFiles('agent-archives');
    expect(count($files))->toBe(1);

    $gz = Storage::disk('local')->get($files[0]);
    $json = gzdecode($gz);
    expect($json)->not->toBeFalse();
    $decoded = json_decode((string) $json, true);
    expect($decoded)->toBeArray();
    expect(count($decoded))->toBe(1);
    expect($decoded[0]['kind'])->toBe('echo');
});

it('deletes rows after archive write succeeds (Test 4)', function (): void {
    AgentRun::factory()->count(3)->create([
        'completed_at' => now()->subDays(2000),
    ]);

    Artisan::call('agents:prune-archive');

    expect(AgentRun::count())->toBe(0);
});

it('writes activity_log row with description=agent_run_archived (Test 5)', function (): void {
    AgentRun::factory()->count(2)->create([
        'completed_at' => now()->subDays(2000),
    ]);

    Artisan::call('agents:prune-archive');

    $row = DB::table('activity_log')
        ->where('description', 'agent_run_archived')
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull();
    $props = json_decode((string) $row->properties, true);
    expect($props['archived_count'])->toBe(2);
    expect($props['deleted_count'])->toBe(2);
    expect($props['days_threshold'])->toBe(1825);
});

it('exits 0 with "nothing to archive" when no rows old enough (Test 6)', function (): void {
    AgentRun::factory()->create([
        'completed_at' => now()->subDays(100),
    ]);

    $exitCode = Artisan::call('agents:prune-archive');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('nothing to archive');
    expect(AgentRun::count())->toBe(1);  // unchanged
});

it('--dry-run logs intent without writing or deleting (Test 7)', function (): void {
    AgentRun::factory()->count(2)->create([
        'completed_at' => now()->subDays(2000),
    ]);

    $exitCode = Artisan::call('agents:prune-archive', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('[dry-run]');
    expect(AgentRun::count())->toBe(2);  // unchanged
    expect(Storage::disk('local')->allFiles('agent-archives'))->toBe([]);
});

it('correlation_id threads onto activity_log batch_uuid (Test 8)', function (): void {
    AgentRun::factory()->create([
        'completed_at' => now()->subDays(2000),
    ]);

    Artisan::call('agents:prune-archive');

    $row = DB::table('activity_log')
        ->where('description', 'agent_run_archived')
        ->orderByDesc('id')
        ->first();

    expect($row->batch_uuid)->not->toBe('');
    // batch_uuid is a UUID v4 (36 chars with 4 dashes per BaseCommand contract)
    expect(strlen((string) $row->batch_uuid))->toBe(36);
});
