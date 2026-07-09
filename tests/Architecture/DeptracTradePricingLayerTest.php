<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 9 Plan 01 — TradePricing Deptrac layer (TRDE-01)
|--------------------------------------------------------------------------
|
| Phase 5 Plan 05-05 + Phase 7 Plan 07-06 + Phase 8 Plan 08-01 lesson:
| depfile.yaml and deptrac.yaml MUST stay byte-identical on layer + ruleset
| entries. Any drift silently breaks one of the two enforcement runs (the
| deptrac CLI is invoked separately for each in CI).
|
|   1. Dual-YAML structural assertion: BOTH yamls declare a `TradePricing`
|      layer whose collector matches `app/Domain/TradePricing/.*` AND have a
|      `TradePricing` ruleset entry containing the locked allow-list
|      (Foundation, Pricing, Products). Order-tolerant: the test asserts
|      presence, not order.
|
|   2. Negative denial assertion: BOTH yamls' TradePricing allow-list does
|      NOT contain Sync, CRM, Webhooks, Cutover, Marketing, Agents, Channels,
|      Quotes — the layers explicitly denied per CONTEXT §code_context
|      "Deptrac dual-YAML lesson" + RESEARCH §Specifics "TradePricing layer
|      is tight". Catches accidental copy-paste drift from another phase's
|      allow-list.
|
|   3. Positive Deptrac run: `deptrac analyse` exits 0 against BOTH yamls.
|      Any violation in shipped TradePricing code (only Models/CustomerGroup
|      at end of Plan 09-01; future plans add Services/Listeners/Filament)
|      trips. Mirrors the exit-code assertion pattern of every prior Phase
|      Deptrac test (Phase 5 / 6 / 7 / 8) — capturing stdout via Symfony
|      Process is unreliable on Windows PHP per the Phase 7 precedent.
*/

it('TradePricing layer registered in BOTH depfile.yaml and deptrac.yaml with the TRDE-01 allow-list', function (): void {
    foreach (['depfile.yaml', 'deptrac.yaml'] as $yamlPath) {
        $config = Yaml::parseFile(base_path($yamlPath));

        // Deptrac shim accepts both top-level layout (parameters: { layers: ... })
        // and namespaced layout (deptrac: { layers: ... }) — handle both shapes.
        $params = $config['parameters'] ?? $config['deptrac'] ?? $config;
        $layerNames = array_column($params['layers'] ?? [], 'name');

        expect($layerNames)->toContain('TradePricing');

        $ruleset = $params['ruleset'] ?? [];
        expect($ruleset)->toHaveKey('TradePricing');

        $allowed = $ruleset['TradePricing'];
        // TRDE-01 mandated allow-list — decorator over v1 Pricing reading Products taxonomy.
        expect($allowed)->toContain('Foundation');
        expect($allowed)->toContain('Pricing');
        expect($allowed)->toContain('Products');
        // Plan 09-04 deviation — Webhooks added so listener can subscribe to v1 events.
        expect($allowed)->toContain('Webhooks');

        // Explicit denials — these layers MUST NOT appear in TradePricing's allow-list.
        // Catches accidental copy-paste drift from another phase's allow-list (e.g.
        // pasting Dashboard's broad allow-list onto TradePricing). Webhooks was added
        // to TradePricing's allow-list in Plan 09-04 to permit the
        // UpdateCustomerGroupOnUserRoleChange listener to subscribe to Webhooks events
        // (one-way arrow; Phase 6 ProductAutoCreate listener-based-extension precedent).
        foreach (['Sync', 'CRM', 'Cutover', 'Marketing', 'Agents', 'Channels', 'Quotes'] as $denied) {
            expect($allowed)->not->toContain($denied);
        }
    }
});

it('TradePricing collector covers app/Domain/TradePricing/.* but excludes its Filament subdir in both YAMLs', function (): void {
    // 260709: TradePricing's collector was converted from a plain `directory`
    // collector to a `bool` collector that MUST match app/Domain/TradePricing/.*
    // and MUST_NOT match app/Domain/TradePricing/Filament/.* — domain-embedded
    // Filament is presentation and belongs to the Http layer, not the domain layer.
    foreach (['depfile.yaml', 'deptrac.yaml'] as $yamlPath) {
        $config = Yaml::parseFile(base_path($yamlPath));
        $params = $config['parameters'] ?? $config['deptrac'] ?? $config;

        $tradeLayer = null;
        foreach ($params['layers'] ?? [] as $layer) {
            if (($layer['name'] ?? null) === 'TradePricing') {
                $tradeLayer = $layer;
                break;
            }
        }

        expect($tradeLayer)->not->toBeNull();
        expect($tradeLayer['collectors'][0]['type'] ?? null)->toBe('bool');
        expect($tradeLayer['collectors'][0]['must'][0]['regex'] ?? null)->toBe('app/Domain/TradePricing/.*');
        expect($tradeLayer['collectors'][0]['must_not'][0]['regex'] ?? null)->toBe('app/Domain/TradePricing/Filament/.*');
    }
});

it('deptrac analyse exits 0 against depfile.yaml (positive — TradePricing layer clean)', function (): void {
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
        'Deptrac reported violations on depfile.yaml after TradePricing layer registration:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('deptrac analyse exits 0 against deptrac.yaml (positive — TradePricing layer clean)', function (): void {
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
        'Deptrac reported violations on deptrac.yaml after TradePricing layer registration:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});
