<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 5 Plan 05 — Competitor layer cross-domain allow-list
|--------------------------------------------------------------------------
|
| Plan 05-05 Task 2. Enforces Phase 5's architectural boundary at
| architectural-test time:
|
|   Competitor → [Foundation, Pricing, Products, Suggestions, Webhooks, Alerting] allowed.
|   Competitor → CRM / Sync (write) / Feeds BANNED.
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plans 05-01..05-04b produce clean Competitor code.
|
|   2. Negative (CRM): plant a deliberate violator inside app/Domain/Competitor/*
|      that imports `App\Domain\CRM\Services\BitrixClient`. Competitor's
|      allow-list omits CRM → Deptrac MUST flag the import (exit != 0).
|
|   3. Negative (Feeds): plant a violator importing a Feeds class. Feeds is
|      not in the allow-list → Deptrac MUST flag it.
|
| Cleanup happens in `finally` so a failed assertion never leaves the violator
| file on disk.
|
| Output inspection note: Symfony\Process on Windows PHP sometimes cannot
| capture deptrac-shim's stdout. Exit-code assertion is therefore the
| authoritative gate — mirrors Plans 02-05 + 03-05 + 04-05.
|
| Sibling tests (DeptracSyncLayerTest, DeptracPricingLayerTest, DeptracCrmLayerTest)
| all target `--config-file=depfile.yaml`, so this test follows the same convention.
| Plan 05-05 Deviation: depfile.yaml and deptrac.yaml are now kept in sync via
| 05-04b regression-triage commit; this test indirectly validates both configs
| via the positive exit-code gate on `depfile.yaml`.
*/

it('Competitor domain has zero cross-domain import violations (positive)', function () {
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
        'Deptrac reported violations with the Competitor layer ruleset:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('catches a deliberate CRM import from Competitor (negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $violatorFile = base_path('app/Domain/Competitor/__DeptracViolatorCrm.php');

    // DELIBERATE VIOLATION — Competitor must not import CRM (not in allow-list
    // [Foundation, Pricing, Products, Suggestions, Webhooks, Alerting]).
    // Import a REAL class so deptrac resolves the symbol rather than marking uncovered.
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Competitor;

use App\Domain\CRM\Services\BitrixClient;

/**
 * DELIBERATE VIOLATION — Competitor must not import CRM (Plan 05-05 Task 2 negative test).
 * Created at runtime by DeptracCompetitorLayerTest, unlinked in finally.
 */
final class __DeptracViolatorCrm
{
    public function bad(BitrixClient $c): string
    {
        return $c::class;
    }
}
PHP);

    try {
        $process = new Process(
            [PHP_BINARY, $deptracEntry, 'analyse', '--no-progress', '--config-file='.base_path('depfile.yaml')],
            base_path()
        );
        $process->setTimeout(120);
        $process->run();
        $exitCode = $process->getExitCode();

        expect($exitCode)->not->toBe(
            0,
            'Deptrac did NOT flag a deliberate CRM import from Competitor — the Competitor allow-list is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('catches a deliberate Feeds import from Competitor (negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    // Feeds layer ships FeedGenerator interface in Phase 1 (FOUND-13 contract).
    // Guard for unlikely case where the interface is renamed/moved.
    if (! interface_exists('App\\Domain\\Feeds\\Contracts\\FeedGenerator')) {
        test()->markTestSkipped('FeedGenerator interface not available — Feeds layer scaffolding may have changed.');
    }

    $violatorFile = base_path('app/Domain/Competitor/__DeptracViolatorFeeds.php');

    // CRITICAL: Deptrac AST traversal only detects class references used via type
    // annotations or new/instanceof — a bare `::class` constant doesn't register
    // (confirmed empirically on deptrac-shim phar build). Hence the parameter
    // type-hint usage below, matching the CRM negative test's shape.
    $body = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Competitor;

use App\Domain\Feeds\Contracts\FeedGenerator;

/**
 * DELIBERATE VIOLATION — Competitor must not import Feeds (Plan 05-05 Task 2).
 * Created at runtime by DeptracCompetitorLayerTest, unlinked in finally.
 */
final class __DeptracViolatorFeeds
{
    public function bad(FeedGenerator $feed): string
    {
        return $feed->channel();
    }
}
PHP;

    file_put_contents($violatorFile, $body);

    try {
        $process = new Process(
            [PHP_BINARY, $deptracEntry, 'analyse', '--no-progress', '--config-file='.base_path('depfile.yaml')],
            base_path()
        );
        $process->setTimeout(120);
        $process->run();
        $exitCode = $process->getExitCode();

        expect($exitCode)->not->toBe(
            0,
            'Deptrac did NOT flag a deliberate Feeds import from Competitor — the Competitor allow-list is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('depfile.yaml declares the Competitor layer with Foundation + Pricing + Products + Suggestions + Alerting', function () {
    $yaml = file_get_contents(base_path('depfile.yaml'));

    // Literal grep — each dependency must appear on the Competitor ruleset line
    // (not just anywhere in the file). The line takes the form:
    //   Competitor:   [Foundation, Pricing, Products, Suggestions, Webhooks, Alerting]
    // Webhooks was added in 05-03 for OrderReceived subscription; it's in the actual
    // allow-list but not in the plan's original 5-entry mandate — see Deviations.
    expect($yaml)->toMatch('/Competitor:\s*\[[^]]*\bFoundation\b/');
    expect($yaml)->toMatch('/Competitor:\s*\[[^]]*\bPricing\b/');
    expect($yaml)->toMatch('/Competitor:\s*\[[^]]*\bProducts\b/');
    expect($yaml)->toMatch('/Competitor:\s*\[[^]]*\bSuggestions\b/');
    expect($yaml)->toMatch('/Competitor:\s*\[[^]]*\bAlerting\b/');
});
