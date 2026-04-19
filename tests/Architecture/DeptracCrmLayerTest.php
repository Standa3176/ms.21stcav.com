<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 4 Plan 05 — CRM layer cross-domain allow-list
|--------------------------------------------------------------------------
|
| Plan 04-05 Task 3. Enforces Phase 4's architectural boundary at
| architectural-test time:
|
|   CRM → [Foundation, Sync, Alerting, Webhooks, Suggestions] allowed.
|   CRM → Pricing / Products / Competitor / Feeds  BANNED.
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plans 04-01..04-05 produce clean CRM code.
|
|   2. Negative: plant a deliberate violator inside app/Domain/CRM/Services/*
|      that imports `App\Domain\Pricing\Services\PriceCalculator` (a Phase 3
|      class NOT in CRM's allow-list). The CRM ruleset MUST flag this as a
|      violation (exit non-zero). Cleanup happens BEFORE assertions so a
|      failed assertion never leaves the violator on disk.
|
| Sibling to DeptracTest.php (module boundaries), DeptracSyncLayerTest.php
| (SYNC-04 WpDirectDb ban), DeptracPricingLayerTest.php (Pricing allow-list).
| Together these pin the Pricing / Sync / CRM cross-domain contracts.
|
| Output inspection note: Symfony\Process on Windows PHP sometimes cannot
| capture deptrac-shim's stdout (the phar routes violation tables through
| a channel that escapes PHP's proc_open). Exit-code assertion is therefore
| the authoritative gate — mirrors Phase 2 Plan 05 + Phase 3 Plan 05 pattern.
*/

it('CRM domain has zero cross-domain import violations (positive)', function () {
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
        'Deptrac reported violations with the CRM layer ruleset:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('catches a deliberate Pricing import from CRM (negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $violatorFile = base_path('app/Domain/CRM/Services/__CrmDeptracViolator.php');
    $violatorDir = dirname($violatorFile);

    if (! is_dir($violatorDir)) {
        mkdir($violatorDir, 0755, true);
    }

    // DELIBERATE VIOLATION — CRM must not import Pricing (NOT in CRM's allow-list
    // [Foundation, Sync, Alerting, Webhooks, Suggestions]). Same pattern as
    // DeptracSyncLayerTest + DeptracPricingLayerTest negative tests: import a
    // REAL class (Phase 3 PriceCalculator) so deptrac resolves the symbol
    // rather than marking it uncovered.
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\Pricing\Services\PriceCalculator;

/**
 * DELIBERATE VIOLATION — CRM must not import Pricing (Plan 04-05 Task 3 negative test).
 * Created at runtime by DeptracCrmLayerTest, unlinked on assertion.
 */
final class __CrmDeptracViolator
{
    public function bad(PriceCalculator $calc): string
    {
        return (string) $calc::class;
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

    // Authoritative assertion: Deptrac MUST exit non-zero when a CRM class
    // imports Pricing. Exit code is the CI-gating signal; stdout capture
    // through deptrac-shim is unreliable on Windows PHP so we do not rely on it.
    expect($exitCode)->not->toBe(
        0,
        'Deptrac did NOT flag a deliberate Pricing import from CRM — the CRM allow-list is not firing.'
    );
});
