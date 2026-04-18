<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: module-boundary enforcement via Deptrac
|--------------------------------------------------------------------------
|
| Wraps `vendor/bin/deptrac analyse` as Pest feature-level assertions. The
| CI workflow (.github/workflows/ci.yml) also runs Deptrac standalone — this
| test catches violations during local `vendor/bin/pest` runs before push.
|
| Two sibling tests:
|   1. Positive — current codebase passes (exit 0)
|   2. Negative — a deliberate cross-domain violator is planted, deptrac is
|      expected to exit non-zero, violator file is cleaned up.
*/

it('has zero module-boundary violations', function () {
    $deptracBin = base_path('vendor/bin/deptrac');
    if (! file_exists($deptracBin) && ! file_exists($deptracBin.'.bat')) {
        test()->markTestSkipped('deptrac binary not found — install dev dependencies.');
    }

    $process = new Process([PHP_BINARY, base_path('vendor/qossmic/deptrac-shim/deptrac'), 'analyse', '--no-progress'], base_path());
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(0, 'Deptrac reported violations:'.PHP_EOL.$process->getOutput().$process->getErrorOutput());
});

it('catches a deliberate cross-domain violation (negative test)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $violatorFile = base_path('app/Domain/Products/Services/__DeptracViolator.php');
    $violatorDir = dirname($violatorFile);

    if (! is_dir($violatorDir)) {
        mkdir($violatorDir, 0755, true);
    }

    // Write a file that imports across module boundaries — Deptrac MUST catch this.
    // We import a REAL class (Sync\Models\SyncDiff exists since Plan 04) so deptrac
    // actually resolves the symbol rather than marking it uncovered.
    file_put_contents($violatorFile, <<<'PHP'
<?php

namespace App\Domain\Products\Services;

// DELIBERATE VIOLATION — Products must not depend on Sync (negative test).
use App\Domain\Sync\Models\SyncDiff;

class __DeptracViolator
{
    public function __construct(private SyncDiff $diff) {}
}
PHP);

    $process = new Process([PHP_BINARY, $deptracEntry, 'analyse', '--no-progress'], base_path());
    $process->setTimeout(120);
    $process->run();
    $exitCode = $process->getExitCode();

    // Clean up BEFORE assertions so a failed assertion doesn't leave the violator in place.
    @unlink($violatorFile);

    // Deptrac should have exited non-zero (violation detected).
    expect($exitCode)->not->toBe(0);
});
