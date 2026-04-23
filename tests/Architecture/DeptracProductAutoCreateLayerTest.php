<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 6 Plan 06 — ProductAutoCreate layer cross-domain allow-list
|--------------------------------------------------------------------------
|
| Plan 06-06 Task 1. Permanent CI gate for the ProductAutoCreate domain
| boundary established across Plans 06-01..06-05:
|
|   ProductAutoCreate → [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks] allowed.
|   ProductAutoCreate → CRM / Competitor / Feeds BANNED.
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plans 06-01..06-05 produce clean ProductAutoCreate code.
|
|   2. Negative (CRM): plant a deliberate violator inside
|      app/Domain/ProductAutoCreate/* that imports
|      `App\Domain\CRM\Services\BitrixClient`. ProductAutoCreate's allow-list
|      omits CRM → Deptrac MUST flag the import (exit != 0).
|
|   3. Negative (Feeds): plant a violator importing a Feeds class. Feeds is
|      not in the allow-list → Deptrac MUST flag it.
|
|   4. Dual-file allow-list grep: Plan 05-05 lesson — `depfile.yaml` and
|      `deptrac.yaml` MUST stay in sync. Assert BOTH files declare the
|      ProductAutoCreate layer with the locked allow-list (Foundation,
|      Products, Pricing, Sync, Suggestions, Alerting members present on
|      the ProductAutoCreate ruleset line).
|
| Cleanup happens in `finally` so a failed assertion never leaves the violator
| file on disk.
|
| Output inspection note: Symfony\Process on Windows PHP sometimes cannot
| capture deptrac-shim's stdout. Exit-code assertion is therefore the
| authoritative gate — mirrors Plans 02-05 + 03-05 + 04-05 + 05-05.
|
| Sibling tests (DeptracSyncLayerTest, DeptracPricingLayerTest,
| DeptracCrmLayerTest, DeptracCompetitorLayerTest) all target
| `--config-file=depfile.yaml`; this test follows the same convention plus
| adds an explicit dual-file grep assertion per the Plan 06-06 must_haves.
*/

it('ProductAutoCreate domain has zero cross-domain import violations (positive)', function () {
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
        'Deptrac reported violations with the ProductAutoCreate layer ruleset:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('catches a deliberate CRM import from ProductAutoCreate (negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    // Guard — BitrixClient is the canonical CRM import fingerprint (Phase 4 Plan 02).
    if (! class_exists('App\\Domain\\CRM\\Services\\BitrixClient')) {
        test()->markTestSkipped('BitrixClient class not available — CRM layer scaffolding may have changed.');
    }

    $violatorFile = base_path('app/Domain/ProductAutoCreate/__DeptracViolatorCrm.php');

    // DELIBERATE VIOLATION — ProductAutoCreate must not import CRM (not in
    // allow-list [Foundation, Products, Pricing, Sync, Suggestions, Alerting, Webhooks]).
    // Import a REAL class so deptrac resolves the symbol rather than marking uncovered.
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate;

use App\Domain\CRM\Services\BitrixClient;

/**
 * DELIBERATE VIOLATION — ProductAutoCreate must not import CRM (Plan 06-06 Task 1 negative test).
 * Created at runtime by DeptracProductAutoCreateLayerTest, unlinked in finally.
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
            'Deptrac did NOT flag a deliberate CRM import from ProductAutoCreate — the ProductAutoCreate allow-list is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('catches a deliberate Feeds import from ProductAutoCreate (negative)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    // Feeds layer ships FeedGenerator interface in Phase 1 (FOUND-13 contract).
    // Guard for unlikely case where the interface is renamed/moved.
    if (! interface_exists('App\\Domain\\Feeds\\Contracts\\FeedGenerator')) {
        test()->markTestSkipped('FeedGenerator interface not available — Feeds layer scaffolding may have changed.');
    }

    $violatorFile = base_path('app/Domain/ProductAutoCreate/__DeptracViolatorFeeds.php');

    // CRITICAL: Deptrac AST traversal only detects class references used via type
    // annotations or new/instanceof — a bare `::class` constant doesn't register
    // (confirmed empirically on deptrac-shim phar build, Plan 05-05 precedent).
    // Hence the parameter type-hint usage below, matching the CRM negative test shape.
    $body = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate;

use App\Domain\Feeds\Contracts\FeedGenerator;

/**
 * DELIBERATE VIOLATION — ProductAutoCreate must not import Feeds (Plan 06-06 Task 1).
 * Created at runtime by DeptracProductAutoCreateLayerTest, unlinked in finally.
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
            'Deptrac did NOT flag a deliberate Feeds import from ProductAutoCreate — the ProductAutoCreate allow-list is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('both deptrac config files declare the ProductAutoCreate layer with the locked allow-list', function () {
    $deptracYaml = file_get_contents(base_path('deptrac.yaml'));
    $depfileYaml = file_get_contents(base_path('depfile.yaml'));

    // Plan 06-06 dual-config-sync grep: the ProductAutoCreate allow-list must
    // contain at minimum Foundation, Products, Pricing, Sync, Suggestions,
    // Alerting (the locked super-set from Plan 06-01; Webhooks is present
    // from Plan 06-03 forward-compat but is not part of the minimum contract
    // this test asserts — matches Plan 05-05 DeptracCompetitorLayerTest shape
    // which only asserts the minimum mandated members).
    //
    // The pattern anchors on `ProductAutoCreate:` followed by a bracketed
    // list, and requires each named layer to appear inside the same `[...]`
    // block in the documented order-tolerant way (each `[^\]]*` segment
    // allows intervening entries). Matches whether the list is single-line
    // or folded across lines.
    $pattern = '/ProductAutoCreate:\s*\[[^\]]*\bFoundation\b[^\]]*\bProducts\b[^\]]*\bPricing\b[^\]]*\bSync\b[^\]]*\bSuggestions\b[^\]]*\bAlerting\b[^\]]*\]/s';

    expect($deptracYaml)->toMatch(
        $pattern,
        'deptrac.yaml does not declare the ProductAutoCreate allow-list with Foundation, Products, Pricing, Sync, Suggestions, Alerting — Plan 06-06 dual-config-sync assertion.'
    );
    expect($depfileYaml)->toMatch(
        $pattern,
        'depfile.yaml does not declare the ProductAutoCreate allow-list with Foundation, Products, Pricing, Sync, Suggestions, Alerting — Plan 06-06 dual-config-sync assertion.'
    );
});
