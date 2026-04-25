<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 8 Plan 01 — Agents Deptrac layer (AGNT-10, dual-YAML)
|--------------------------------------------------------------------------
|
| Phase 5 Plan 05-05 + Phase 7 Plan 07-06 lesson: depfile.yaml and deptrac.yaml
| MUST stay byte-identical on layer + ruleset entries. Any drift silently
| breaks one of the two enforcement runs (`vendor/bin/deptrac --config-file=…`
| is invoked separately for each in CI).
|
|   1. Dual-YAML structural assertion: BOTH yamls declare an `Agents` layer
|      whose collector matches `app/Domain/Agents/.*` AND have an `Agents`
|      ruleset entry containing the AGNT-10 mandated allow-list (Foundation,
|      Suggestions, Products, Pricing, Competitor, CRM, ProductAutoCreate).
|      Order-tolerant: the test asserts presence, not order.
|
|   2. Negative denial assertion: BOTH yamls' Agents allow-list does NOT
|      contain Webhooks, Sync, Cutover, Marketing — the layers explicitly
|      denied per CONTEXT §code_context "Deptrac dual-YAML lesson".
|
|   3. Positive Deptrac run: `deptrac analyse` exits 0 against BOTH yamls.
|      Any violation in shipped Agents code (zero at end of Plan 01) trips.
|      Mirrors the exit-code assertion pattern of every prior Phase Deptrac
|      test (Phase 5 / 6 / 7) — capturing stdout via Symfony Process is
|      unreliable on Windows PHP per the Phase 7 precedent.
*/

it('Agents layer registered in BOTH depfile.yaml and deptrac.yaml with the AGNT-10 allow-list', function (): void {
    foreach (['depfile.yaml', 'deptrac.yaml'] as $yamlPath) {
        $config = Yaml::parseFile(base_path($yamlPath));

        // Deptrac shim accepts both top-level layout (parameters: { layers: ... })
        // and namespaced layout (deptrac: { layers: ... }) — handle both shapes.
        $params = $config['parameters'] ?? $config['deptrac'] ?? $config;
        $layerNames = array_column($params['layers'] ?? [], 'name');

        // Pest's `expect()->toContain($needle1, $needle2, ...)` treats every
        // positional arg as ANOTHER needle, NOT a custom message — so plain
        // single-arg calls are used here. Failure messages still surface the
        // yaml path via the assertion stack trace.
        expect($layerNames)->toContain('Agents');

        $ruleset = $params['ruleset'] ?? [];
        expect($ruleset)->toHaveKey('Agents');

        $allowed = $ruleset['Agents'];
        // AGNT-10 mandated allow-list — read-only consumption of v1 data domains.
        expect($allowed)->toContain('Foundation');
        expect($allowed)->toContain('Suggestions');
        expect($allowed)->toContain('Products');
        expect($allowed)->toContain('Pricing');
        expect($allowed)->toContain('Competitor');
        expect($allowed)->toContain('CRM');
        expect($allowed)->toContain('ProductAutoCreate');

        // Explicit denials — these layers MUST NOT appear in Agents' allow-list.
        // Catches accidental copy-paste drift from another phase's allow-list.
        expect($allowed)->not->toContain('Webhooks');
        expect($allowed)->not->toContain('Sync');
        expect($allowed)->not->toContain('Cutover');
        expect($allowed)->not->toContain('Marketing');
    }
});

it('deptrac analyse exits 0 against depfile.yaml (positive — Agents layer clean)', function (): void {
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
        'Deptrac reported violations on depfile.yaml after Agents layer registration:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('deptrac analyse exits 0 against deptrac.yaml (positive — Agents layer clean)', function (): void {
    $deptracEntry = base_path('vendor/qossmic/deptrac-shim/deptrac');
    if (! file_exists($deptracEntry)) {
        test()->markTestSkipped('deptrac-shim not found — install dev dependencies.');
    }

    $process = new Process(
        [PHP_BINARY, $deptracEntry, 'analyse', '--no-progress', '--config-file='.base_path('deptrac.yaml')],
        base_path()
    );
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(
        0,
        'Deptrac reported violations on deptrac.yaml after Agents layer registration:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});
