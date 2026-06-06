<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 05 Task 2 — cutover:drill-rollback (CUT-05)
|--------------------------------------------------------------------------
|
| Behaviour tests R1..R4 from 07-05-PLAN:
|   R1 — dry-run reports all 5 steps
|   R2 — dry-run writes drill-report-{date}.md to storage/app/cutover
|   R3 — --live without CUTOVER_DRILL_ALLOWED env fails with error
|   R4 — --live with env=true runs the 5 steps
*/

beforeEach(function (): void {
    $this->tmp = sys_get_temp_dir().'/cutover-drill-test-'.uniqid();
    mkdir($this->tmp, 0o755, true);
    config([
        'cutover.backup_path' => $this->tmp.'/backups',
        'cutover.drill_report_path' => $this->tmp,
        'cutover.drill_allowed_env_var' => 'CUTOVER_DRILL_ALLOWED',
        // Gate closed by default — individual tests open it via config().
        // We override the config value directly (not via putenv) because the
        // command reads config('cutover.drill_allowed'), and Laravel's config
        // is loaded once at boot — putenv() after boot does NOT propagate to
        // config in test runs. (Pattern enforced by tests/Architecture/
        // EnvUsageTest.php as of 260606-c4o.)
        'cutover.drill_allowed' => null,
    ]);
    mkdir($this->tmp.'/backups', 0o755, true);
});

afterEach(function (): void {
    if (isset($this->tmp) && is_dir($this->tmp)) {
        $files = glob($this->tmp.'/*') ?: [];
        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $backups = glob($this->tmp.'/backups/*') ?: [];
        foreach ($backups as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->tmp.'/backups');
        @rmdir($this->tmp);
    }
});

it('registers cutover:drill-rollback in the artisan registry', function (): void {
    expect(array_keys(Artisan::all()))->toContain('cutover:drill-rollback');
});

it('dry-run reports all 5 steps', function (): void {
    $exit = Artisan::call('cutover:drill-rollback');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('step_1_flag_readable');
    expect($output)->toContain('step_2_flag_flip_simulated');
    expect($output)->toContain('step_3_backup_verifiable');
    expect($output)->toContain('step_4_legacy_cron_check');
    expect($output)->toContain('step_5_drill_report');
});

it('dry-run writes drill-report-{YYYY-MM-DD}.md under drill_report_path', function (): void {
    Artisan::call('cutover:drill-rollback');

    $expected = $this->tmp.'/drill-report-'.now()->format('Y-m-d').'.md';
    expect(file_exists($expected))->toBeTrue();
    $contents = file_get_contents($expected);
    expect($contents)->toContain('STEP 1/5');
    expect($contents)->toContain('STEP 5/5');
});

it('--live without CUTOVER_DRILL_ALLOWED env fails fast (R3)', function (): void {
    // beforeEach already sets cutover.drill_allowed = null; nothing more to do.
    $exit = Artisan::call('cutover:drill-rollback', ['--live' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('Drill not allowed');
});

it('--live with CUTOVER_DRILL_ALLOWED=true executes the drill', function (): void {
    // Override the config value directly — equivalent to the operator setting
    // CUTOVER_DRILL_ALLOWED=true in their .env, but survives Laravel's config
    // load order in tests (env() reads after boot are too late).
    config(['cutover.drill_allowed' => 'true']);

    $exit = Artisan::call('cutover:drill-rollback', ['--live' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('step_5_drill_report');
    expect($output)->toContain('LIVE drill completed');
});
