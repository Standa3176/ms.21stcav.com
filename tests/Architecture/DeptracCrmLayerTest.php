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
|   CRM → [Foundation, Sync, Alerting, Webhooks, Suggestions, Agents,
|          Quotes, Pricing, Integrations] allowed.
|   CRM → Products / Competitor / Feeds  BANNED.
|
|   (Pricing was ADDED to CRM's allow-list in Phase 11 Plan 04 —
|   PushQuoteToBitrixDealJob calls PriceCalculator::stripVat. The negative
|   violator below therefore imports a Products class, which remains BANNED,
|   so it still proves the CRM domain rule fires on a genuine cross-domain
|   read for non-Filament code. 260709: before this repoint the negative was
|   passing only because of the 8 ambient Filament violations — masking the
|   fact that its old Pricing token had become allowed.)
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plans 04-01..04-05 produce clean CRM code.
|
|   2. Negative: plant a deliberate violator inside app/Domain/CRM/Services/*
|      that imports `App\Domain\Products\Models\Product` (a Products class
|      NOT in CRM's allow-list). The CRM ruleset MUST flag this as a
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

it('catches a deliberate Products import from CRM (negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $violatorFile = base_path('app/Domain/CRM/Services/__CrmDeptracViolator.php');
    $violatorDir = dirname($violatorFile);

    if (! is_dir($violatorDir)) {
        mkdir($violatorDir, 0755, true);
    }

    // DELIBERATE VIOLATION — CRM must not import Products (NOT in CRM's allow-list
    // [Foundation, Sync, Alerting, Webhooks, Suggestions, Agents, Quotes, Pricing,
    // Integrations]). Same pattern as DeptracSyncLayerTest + DeptracPricingLayerTest
    // negative tests: import a REAL class (Products\Models\Product) so deptrac
    // resolves the symbol rather than marking it uncovered. Products (not Pricing)
    // is used because Pricing joined CRM's allow-list in Phase 11 Plan 04.
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\CRM\Services;

use App\Domain\Products\Models\Product;

/**
 * DELIBERATE VIOLATION — CRM must not import Products (Plan 04-05 Task 3 negative test).
 * Created at runtime by DeptracCrmLayerTest, unlinked on assertion.
 */
final class __CrmDeptracViolator
{
    public function bad(Product $product): string
    {
        return (string) $product::class;
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
    // imports Products. Exit code is the CI-gating signal; stdout capture
    // through deptrac-shim is unreliable on Windows PHP so we do not rely on it.
    expect($exitCode)->not->toBe(
        0,
        'Deptrac did NOT flag a deliberate Products import from CRM — the CRM allow-list is not firing.'
    );
});
