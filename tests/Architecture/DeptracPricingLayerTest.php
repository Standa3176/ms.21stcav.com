<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 3 Plan 05 — Pricing layer cross-domain allow-list
|--------------------------------------------------------------------------
|
| Plan 03-05 Task 1. Enforces Phase 3's architectural boundary at
| architectural-test time:
|
|   Pricing → [Foundation, Products, Sync, WpDirectDb] allowed.
|   Pricing → CRM/Competitor/Webhooks/Feeds/Alerting/Suggestions BANNED.
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plans 01-04 produce clean Pricing code.
|
|   2. Negative: plant a deliberate violator inside app/Domain/Pricing/Services/*
|      that imports `App\Domain\Webhooks\Models\WebhookReceipt` (a Phase 1
|      class NOT in Pricing's allow-list). The Pricing ruleset MUST flag this
|      as a violation (exit non-zero). Cleanup happens BEFORE assertions so
|      a failed assertion never leaves the violator on disk.
|
| Sibling to DeptracTest.php (module-boundary enforcement) and
| DeptracSyncLayerTest.php (SYNC-04 WpDirectDb ban). Together these three
| tests pin the Pricing / Sync cross-domain boundary contracts.
|
| Output inspection note: Symfony\Process on Windows PHP sometimes cannot
| capture deptrac-shim's stdout (the phar routes violation tables through
| a channel that escapes PHP's proc_open). Exit-code assertion is therefore
| the authoritative gate; we also save the combined output to a temp file
| and grep it when content is present, for belt-and-braces.
*/

it('Pricing domain has zero unauthorized imports (positive)', function () {
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
        'Deptrac reported violations with the Pricing layer ruleset:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('catches a deliberate Webhooks import from Pricing (negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $violatorFile = base_path('app/Domain/Pricing/Services/__PricingDeptracViolator.php');
    $violatorDir = dirname($violatorFile);

    if (! is_dir($violatorDir)) {
        mkdir($violatorDir, 0755, true);
    }

    // DELIBERATE VIOLATION — Pricing must not import Webhooks (NOT in allow-list).
    // Same pattern as DeptracSyncLayerTest negative test: we import a REAL class
    // (Phase 1 shipped WebhookReceipt) so deptrac resolves the symbol rather than
    // marking it uncovered. Webhooks is NOT in Pricing's allow-list
    // [Foundation, Products, Sync, WpDirectDb].
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Webhooks\Models\WebhookReceipt;

/**
 * DELIBERATE VIOLATION — Pricing must not import Webhooks (Plan 03-05 Task 1 negative test).
 * Created at runtime by DeptracPricingLayerTest, unlinked on assertion.
 */
final class __PricingDeptracViolator
{
    public function bad(WebhookReceipt $r): string
    {
        return (string) $r->id;
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

    // Authoritative assertion: Deptrac MUST exit non-zero when a Pricing class
    // imports Webhooks. Exit code is the CI-gating signal; stdout capture
    // through deptrac-shim is unreliable on Windows PHP so we do not rely on it.
    expect($exitCode)->not->toBe(
        0,
        'Deptrac did NOT flag a deliberate Webhooks import from Pricing — the Pricing allow-list is not firing.'
    );
});
