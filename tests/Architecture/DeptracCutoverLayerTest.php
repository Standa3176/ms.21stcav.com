<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 7 Plan 06 — Cutover layer cross-domain allow-list
|--------------------------------------------------------------------------
|
| Plan 07-06 Task 1. Permanent CI gate for the Cutover domain boundary
| established in Plan 07-05 (6 artisan commands + 7 services):
|
|   Cutover → [Foundation, Products, Pricing, Sync, Suggestions, Alerting,
|              Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard,
|              WpDirectDb] allowed.
|   Cutover → Feeds BANNED (Feeds is the v2 channel-feed domain, out of v1 scope).
|   Other domains → Cutover BANNED (one-way arrow: Cutover orchestrates the
|                   legacy-plugin migration; nothing depends on it).
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plan 07-05 ships all Cutover services + commands with zero
|      Cutover-related violations after the allow-list landed in Plan 07-06.
|
|   2. Negative (CRM → Cutover): plant a deliberate violator inside
|      app/Domain/CRM/* that imports App\Domain\Cutover\Services\DivergenceScanner.
|      CRM's allow-list omits Cutover → Deptrac MUST flag the import (exit != 0).
|      This is the key one-way-arrow assertion — no prior-phase domain may
|      depend on Cutover.
|
|   3. Negative (Cutover → Feeds): plant a violator inside app/Domain/Cutover/*
|      importing a Feeds class. Feeds is explicitly NOT in Cutover's allow-list
|      → Deptrac MUST flag it.
|
|   4. Dual-file allow-list grep: Plan 05-05 lesson — `depfile.yaml` and
|      `deptrac.yaml` MUST stay in sync. Assert BOTH files declare the
|      Cutover layer with the locked allow-list members (Foundation,
|      Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor,
|      CRM, ProductAutoCreate, Dashboard all present).
|
| Cleanup happens in `finally` so a failed assertion never leaves the violator
| file on disk.
|
| Output inspection note: Symfony\Process on Windows PHP sometimes cannot
| capture deptrac-shim's stdout. Exit-code assertion is therefore the
| authoritative gate — mirrors Plans 02-05 / 03-05 / 04-05 / 05-05 / 06-06.
*/

it('Cutover domain has zero cross-domain import violations (positive)', function () {
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
        'Deptrac reported violations with the Cutover layer ruleset:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('catches a deliberate Cutover import from CRM (negative — one-way arrow)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    // DivergenceScanner is the canonical Cutover-layer service (Plan 07-05).
    if (! class_exists('App\\Domain\\Cutover\\Services\\DivergenceScanner')) {
        test()->markTestSkipped('DivergenceScanner class not available — Cutover layer scaffolding may have changed.');
    }

    $violatorFile = base_path('app/Domain/CRM/__DeptracViolatorCutoverRef.php');

    // DELIBERATE VIOLATION — CRM's allow-list is [Foundation, Sync, Alerting,
    // Webhooks, Suggestions]. Cutover is NOT in there. Importing
    // DivergenceScanner must trip the layer boundary. Parameter type-hint
    // usage per Plan 05-05 AST-traversal lesson.
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\CRM;

use App\Domain\Cutover\Services\DivergenceScanner;

/**
 * DELIBERATE VIOLATION — CRM must not import Cutover (Plan 07-06 Task 1 one-way-arrow negative).
 * Created at runtime by DeptracCutoverLayerTest, unlinked in finally.
 */
final class __DeptracViolatorCutoverRef
{
    public function bad(DivergenceScanner $s): string
    {
        return $s::class;
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
            'Deptrac did NOT flag a deliberate Cutover import from CRM — the Cutover one-way-arrow contract is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('catches a deliberate Feeds import from Cutover (negative — Feeds not in allow-list)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    // Feeds layer ships FeedGenerator interface in Phase 1 (FOUND-13 contract).
    if (! interface_exists('App\\Domain\\Feeds\\Contracts\\FeedGenerator')) {
        test()->markTestSkipped('FeedGenerator interface not available — Feeds layer scaffolding may have changed.');
    }

    $violatorFile = base_path('app/Domain/Cutover/__DeptracViolatorFeedsRef.php');

    $body = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Cutover;

use App\Domain\Feeds\Contracts\FeedGenerator;

/**
 * DELIBERATE VIOLATION — Cutover must not import Feeds (Plan 07-06 Task 1).
 * Created at runtime by DeptracCutoverLayerTest, unlinked in finally.
 */
final class __DeptracViolatorFeedsRef
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
            'Deptrac did NOT flag a deliberate Feeds import from Cutover — the Cutover allow-list is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('both deptrac config files declare the Cutover layer with the locked allow-list', function () {
    $deptracYaml = file_get_contents(base_path('deptrac.yaml'));
    $depfileYaml = file_get_contents(base_path('depfile.yaml'));

    // Plan 07-06 dual-config-sync grep: the Cutover allow-list must
    // contain at minimum Foundation, Products, Pricing, Sync, Suggestions,
    // Alerting, Webhooks, Competitor, CRM, ProductAutoCreate, Dashboard —
    // the full Plan 07-05 + 07-06 mandated super-set. WpDirectDb is also
    // permitted (for OverridePopulator's local-DB transaction) but not
    // asserted here (same pattern as Plan 06-06 DeptracProductAutoCreateLayerTest).
    $pattern = '/Cutover:\s*\[[^\]]*\bFoundation\b[^\]]*\bProducts\b[^\]]*\bPricing\b[^\]]*\bSync\b[^\]]*\bSuggestions\b[^\]]*\bAlerting\b[^\]]*\bWebhooks\b[^\]]*\bCompetitor\b[^\]]*\bCRM\b[^\]]*\bProductAutoCreate\b[^\]]*\bDashboard\b[^\]]*\]/s';

    expect($deptracYaml)->toMatch(
        $pattern,
        'deptrac.yaml does not declare the Cutover allow-list with all 11 mandated members — Plan 07-06 dual-config-sync assertion.'
    );
    expect($depfileYaml)->toMatch(
        $pattern,
        'depfile.yaml does not declare the Cutover allow-list with all 11 mandated members — Plan 07-06 dual-config-sync assertion.'
    );
});
