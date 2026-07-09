<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Architecture: Phase 11 Plan 01 — Quotes Deptrac layer (QUOT-01..08)
|--------------------------------------------------------------------------
|
| Phase 5 Plan 05-05 + Phase 7 Plan 07-06 + Phase 8 Plan 08-01 + Phase 9
| Plan 09-01 lesson: depfile.yaml and deptrac.yaml MUST stay byte-identical
| on layer + ruleset entries. Any drift silently breaks one of the two
| enforcement runs (the deptrac CLI is invoked separately for each in CI).
|
| Mirrors the DeptracTradePricingLayerTest shape verbatim:
|
|   1. Dual-YAML structural assertion: BOTH yamls declare a `Quotes` layer
|      whose collector matches `app/Domain/Quotes/.*` AND have a `Quotes`
|      ruleset entry containing the locked allow-list (Foundation,
|      Products, Pricing, TradePricing, Suggestions, CRM, Webhooks).
|      Order-tolerant: the test asserts presence, not order.
|
|   2. Negative denial assertion: BOTH yamls' Quotes allow-list does NOT
|      contain Agents, Competitor, ProductAutoCreate, Sync, Cutover,
|      Marketing, Channels — the layers explicitly denied per CONTEXT
|      §code_context "Deptrac one-way arrow: Quotes emits events; CRM
|      consumes". Catches accidental copy-paste drift from another phase's
|      allow-list.
|
|   3. Positive Deptrac run: `deptrac analyse` exits 0 against BOTH yamls.
|      Any violation in shipped Quotes code (Plan 11-01 ships Models +
|      Enums + Policies + Factories; Plan 11-02 adds Services/Observers;
|      Plan 11-03 adds Filament Resource; Plan 11-04 adds Events) trips.
*/

it('Quotes layer registered in BOTH depfile.yaml and deptrac.yaml with the QUOT allow-list', function (): void {
    foreach (['depfile.yaml', 'deptrac.yaml'] as $yamlPath) {
        $config = Yaml::parseFile(base_path($yamlPath));

        // Deptrac shim accepts both top-level layout (parameters: { layers: ... })
        // and namespaced layout (deptrac: { layers: ... }) — handle both shapes.
        $params = $config['parameters'] ?? $config['deptrac'] ?? $config;
        $layerNames = array_column($params['layers'] ?? [], 'name');

        expect($layerNames)->toContain('Quotes');

        $ruleset = $params['ruleset'] ?? [];
        expect($ruleset)->toHaveKey('Quotes');

        $allowed = $ruleset['Quotes'];
        // QUOT mandated allow-list per CONTEXT.md §Decisions Claude's Discretion.
        expect($allowed)->toContain('Foundation');
        expect($allowed)->toContain('Products');
        expect($allowed)->toContain('Pricing');
        expect($allowed)->toContain('TradePricing');
        expect($allowed)->toContain('Suggestions');
        expect($allowed)->toContain('CRM');
        expect($allowed)->toContain('Webhooks');

        // Explicit denials — these layers MUST NOT appear in Quotes' allow-list.
        // Catches accidental copy-paste drift from another phase's allow-list
        // (e.g. pasting Dashboard's broad allow-list onto Quotes). The Quotes
        // domain is intentionally thin — it is NOT a v1 write-side participant.
        foreach ([
            'Agents',
            'Competitor',
            'ProductAutoCreate',
            'Sync',
            'Cutover',
            'Marketing',
            'Channels',
        ] as $denied) {
            expect($allowed)->not->toContain($denied);
        }
    }
});

it('Quotes collector covers app/Domain/Quotes/.* but excludes its Filament subdir in both YAMLs', function (): void {
    // 260709: Quotes's collector was converted from a plain `directory` collector
    // to a `bool` collector that MUST match app/Domain/Quotes/.* and MUST_NOT match
    // app/Domain/Quotes/Filament/.* — domain-embedded Filament is presentation and
    // belongs to the Http layer, not the domain layer.
    foreach (['depfile.yaml', 'deptrac.yaml'] as $yamlPath) {
        $config = Yaml::parseFile(base_path($yamlPath));
        $params = $config['parameters'] ?? $config['deptrac'] ?? $config;

        $quotesLayer = null;
        foreach ($params['layers'] ?? [] as $layer) {
            if (($layer['name'] ?? null) === 'Quotes') {
                $quotesLayer = $layer;
                break;
            }
        }

        expect($quotesLayer)->not->toBeNull();
        expect($quotesLayer['collectors'][0]['type'] ?? null)->toBe('bool');
        expect($quotesLayer['collectors'][0]['must'][0]['regex'] ?? null)->toBe('app/Domain/Quotes/.*');
        expect($quotesLayer['collectors'][0]['must_not'][0]['regex'] ?? null)->toBe('app/Domain/Quotes/Filament/.*');
    }
});

it('CRM allow-list extension includes Quotes (one-way arrow CRM → Quotes)', function (): void {
    // The PRIMARY arrow is CRM → Quotes (Plan 11-04 PushQuoteToBitrix
    // listener in CRM domain reads the Quote model). Without this
    // extension, the listener would trip a Deptrac violation.
    foreach (['depfile.yaml', 'deptrac.yaml'] as $yamlPath) {
        $config = Yaml::parseFile(base_path($yamlPath));
        $params = $config['parameters'] ?? $config['deptrac'] ?? $config;
        $crmAllowed = $params['ruleset']['CRM'] ?? [];

        // pest expect()->toContain — single argument; assertion message via
        // the test name. CRM ruleset in $yamlPath MUST contain 'Quotes'.
        expect($crmAllowed)->toContain('Quotes');
    }
});

it('deptrac analyse exits 0 against depfile.yaml (positive — Quotes layer clean)', function (): void {
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
        'Deptrac reported violations on depfile.yaml after Quotes layer registration:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});

it('deptrac analyse exits 0 against deptrac.yaml (positive — Quotes layer clean)', function (): void {
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
        'Deptrac reported violations on deptrac.yaml after Quotes layer registration:'.PHP_EOL
            .$process->getOutput().$process->getErrorOutput()
    );
});
