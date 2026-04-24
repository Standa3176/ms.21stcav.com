<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 7 Plan 06 — Dashboard layer cross-domain allow-list
|--------------------------------------------------------------------------
|
| Plan 07-06 Task 1. Permanent CI gate for the Dashboard domain boundary
| established across Plans 07-01..07-04:
|
|   Dashboard → [Foundation, Products, Pricing, Sync, Suggestions, Alerting,
|                Webhooks, Competitor, CRM, ProductAutoCreate, WpDirectDb] allowed.
|   Dashboard → Feeds BANNED (Feeds is the v2 channel-feed domain, out of v1 scope).
|   Other domains → Dashboard BANNED (one-way arrow: Dashboard aggregates
|                   everything; nothing depends on it).
|
|   1. Positive: the current codebase passes `vendor/bin/deptrac analyse`
|      (exit 0). Plans 07-01..07-05 + Plan 07-06 Task 1 relocation of
|      CsvExportWriter + QueuedCsvExportJob to app/Filament/Exports/ (out of
|      layer scope) produce clean Dashboard code with zero violations.
|
|   2. Negative (Sync → Dashboard): plant a deliberate violator inside
|      app/Domain/Sync/* that imports App\Domain\Dashboard\Models\DashboardSnapshot.
|      Sync's allow-list omits Dashboard → Deptrac MUST flag the import (exit != 0).
|      This is the key one-way-arrow assertion — no prior-phase domain may
|      depend on Dashboard.
|
|   3. Negative (Dashboard → Feeds): plant a violator inside
|      app/Domain/Dashboard/* importing a Feeds class. Feeds is explicitly
|      NOT in Dashboard's allow-list → Deptrac MUST flag it.
|
|   4. Dual-file allow-list grep: Plan 05-05 lesson — `depfile.yaml` and
|      `deptrac.yaml` MUST stay in sync. Assert BOTH files declare the
|      Dashboard layer with the locked allow-list members (Foundation,
|      Products, Pricing, Sync, Suggestions, Alerting, Webhooks, Competitor,
|      CRM, ProductAutoCreate all present).
|
| Cleanup happens in `finally` so a failed assertion never leaves the violator
| file on disk.
|
| Output inspection note: Symfony\Process on Windows PHP sometimes cannot
| capture deptrac-shim's stdout. Exit-code assertion is therefore the
| authoritative gate — mirrors Plans 02-05 / 03-05 / 04-05 / 05-05 / 06-06.
*/

it('Dashboard domain has zero cross-domain import violations (positive)', function () {
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
        'Deptrac reported violations with the Dashboard layer ruleset:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('catches a deliberate Dashboard import from Sync (negative — one-way arrow)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    // DashboardSnapshot is the canonical Dashboard-layer model (Plan 07-01).
    if (! class_exists('App\\Domain\\Dashboard\\Models\\DashboardSnapshot')) {
        test()->markTestSkipped('DashboardSnapshot class not available — Dashboard layer scaffolding may have changed.');
    }

    $violatorFile = base_path('app/Domain/Sync/__DeptracViolatorDashboardRef.php');

    // DELIBERATE VIOLATION — Sync's allow-list is [Foundation, Products,
    // Alerting, -WpDirectDb]. Dashboard is NOT in there. Importing
    // DashboardSnapshot must trip the layer boundary. Import a REAL class so
    // deptrac resolves the symbol rather than marking uncovered.
    //
    // CRITICAL: Deptrac AST traversal only detects class references via type
    // annotations or new/instanceof — a bare `::class` constant doesn't
    // register (Plan 05-05 precedent). Hence the parameter type-hint usage.
    file_put_contents($violatorFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Dashboard\Models\DashboardSnapshot;

/**
 * DELIBERATE VIOLATION — Sync must not import Dashboard (Plan 07-06 Task 1 one-way-arrow negative).
 * Created at runtime by DeptracDashboardLayerTest, unlinked in finally.
 */
final class __DeptracViolatorDashboardRef
{
    public function bad(DashboardSnapshot $s): string
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
            'Deptrac did NOT flag a deliberate Dashboard import from Sync — the Dashboard one-way-arrow contract is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('catches a deliberate Feeds import from Dashboard (negative — Feeds not in allow-list)', function () {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    // Feeds layer ships FeedGenerator interface in Phase 1 (FOUND-13 contract).
    if (! interface_exists('App\\Domain\\Feeds\\Contracts\\FeedGenerator')) {
        test()->markTestSkipped('FeedGenerator interface not available — Feeds layer scaffolding may have changed.');
    }

    $violatorFile = base_path('app/Domain/Dashboard/__DeptracViolatorFeedsRef.php');

    $body = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Feeds\Contracts\FeedGenerator;

/**
 * DELIBERATE VIOLATION — Dashboard must not import Feeds (Plan 07-06 Task 1).
 * Created at runtime by DeptracDashboardLayerTest, unlinked in finally.
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
            'Deptrac did NOT flag a deliberate Feeds import from Dashboard — the Dashboard allow-list is not firing.'
        );
    } finally {
        @unlink($violatorFile);
    }
});

it('both deptrac config files declare the Dashboard layer with the locked allow-list', function () {
    $deptracYaml = file_get_contents(base_path('deptrac.yaml'));
    $depfileYaml = file_get_contents(base_path('depfile.yaml'));

    // Plan 07-06 dual-config-sync grep: the Dashboard allow-list must
    // contain at minimum Foundation, Products, Pricing, Sync, Suggestions,
    // Alerting, Webhooks, Competitor, CRM, ProductAutoCreate — the full
    // Plan 07-02 mandated super-set. WpDirectDb is also permitted but not
    // asserted here (same pattern as Plan 06-06 DeptracProductAutoCreateLayerTest).
    //
    // The pattern anchors on `Dashboard:` followed by a bracketed list, and
    // requires each named layer to appear inside the same `[...]` block in
    // an order-tolerant way.
    $pattern = '/Dashboard:\s*\[[^\]]*\bFoundation\b[^\]]*\bProducts\b[^\]]*\bPricing\b[^\]]*\bSync\b[^\]]*\bSuggestions\b[^\]]*\bAlerting\b[^\]]*\bWebhooks\b[^\]]*\bCompetitor\b[^\]]*\bCRM\b[^\]]*\bProductAutoCreate\b[^\]]*\]/s';

    expect($deptracYaml)->toMatch(
        $pattern,
        'deptrac.yaml does not declare the Dashboard allow-list with all 10 mandated members — Plan 07-06 dual-config-sync assertion.'
    );
    expect($depfileYaml)->toMatch(
        $pattern,
        'depfile.yaml does not declare the Dashboard allow-list with all 10 mandated members — Plan 07-06 dual-config-sync assertion.'
    );
});
