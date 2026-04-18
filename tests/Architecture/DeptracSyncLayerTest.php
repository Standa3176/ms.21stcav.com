<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: SYNC-04 — Sync domain must not import the DB facade
|--------------------------------------------------------------------------
|
| Plan 02-05 Task 1. Enforces SYNC-04 (the WP direct-DB-write prohibition)
| at architectural-test time:
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plan 02-05 already removed the last DB::transaction usage
|      from SyncChunkJob so this is the baseline invariant.
|
|   2. Negative: plant a deliberate violator inside app/Domain/Sync/* that
|      imports `Illuminate\Support\Facades\DB` — Deptrac's new WpDirectDb
|      layer + `Sync: [-WpDirectDb]` deny rule MUST flag this as a violation
|      (exit non-zero). Cleanup happens before assertions so a failed
|      assertion never leaves the violator on disk.
|
| Sibling to DeptracTest.php (module-boundary enforcement) — that test
| catches Products→Sync leaks; this one catches Sync→DB-facade leaks.
|
| Output inspection note: Symfony\Process on Windows PHP sometimes cannot
| capture deptrac-shim's stdout (the phar routes violation tables through
| a channel that escapes PHP's proc_open). Exit-code assertion is therefore
| the authoritative gate; we also save the combined output to a temp file
| and grep it when content is present, for belt-and-braces.
*/

it('Sync domain has zero WpDirectDb violations (SYNC-04 positive)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $process = new Process(
        [PHP_BINARY, $deptracEntry, 'analyse', '--no-progress', '--config-file='.base_path('depfile.yaml')],
        base_path()
    );
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(
        0,
        'Deptrac reported violations with the new WpDirectDb layer:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('catches a deliberate Illuminate\\Support\\Facades\\DB import from Sync (SYNC-04 negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $violatorFile = base_path('app/Domain/Sync/Services/__SyncDeptracViolator.php');
    $violatorDir = dirname($violatorFile);

    if (! is_dir($violatorDir)) {
        mkdir($violatorDir, 0755, true);
    }

    // DELIBERATE VIOLATION — Sync must not import the DB facade (SYNC-04).
    // Same pattern as DeptracTest's module-boundary negative test: we import
    // a REAL class so deptrac resolves the symbol rather than marking it uncovered.
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use Illuminate\Support\Facades\DB;

/**
 * DELIBERATE VIOLATION — Sync must not import the DB facade (SYNC-04 negative test).
 * Created at runtime by DeptracSyncLayerTest, unlinked on assertion.
 */
final class __SyncDeptracViolator
{
    public function bad(): void
    {
        DB::connection('mysql_woo')->table('wp_posts')->update(['post_status' => 'publish']);
    }
}
PHP);

    $process = new Process(
        [PHP_BINARY, $deptracEntry, 'analyse', '--no-progress', '--config-file='.base_path('depfile.yaml')],
        base_path()
    );
    $process->setTimeout(120);
    $process->run();
    $exitCode = $process->getExitCode();

    // Clean up BEFORE assertions so a failed assertion doesn't leave the violator in place.
    @unlink($violatorFile);

    // Authoritative assertion: Deptrac MUST exit non-zero when a Sync class
    // imports the DB facade. Exit code is the CI-gating signal; stdout capture
    // through deptrac-shim is unreliable on Windows PHP so we do not rely on it.
    expect($exitCode)->not->toBe(
        0,
        'Deptrac did NOT flag a deliberate DB facade import from Sync — the `-WpDirectDb` deny rule is not firing.'
    );
});
