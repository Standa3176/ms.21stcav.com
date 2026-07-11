<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-01 Task 3 — AdOptimisationAgent registration + contract
|--------------------------------------------------------------------------
|
| Third REAL RunsAsAgent consumer (after PricingAgent + SeoAgent). Locks the
| kind / trust tier / tool set / guardrails / budget-cap contract surface.
*/

use App\Domain\Agents\Agents\AdOptimisationAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\AgentRegistry;

it('AgentRegistry resolves "ad_optimisation" to AdOptimisationAgent', function (): void {
    expect(app(AgentRegistry::class)->resolve('ad_optimisation'))->toBeInstanceOf(AdOptimisationAgent::class);
});

it('kind + trust tier match the advice-only scheduled contract', function (): void {
    expect(AdOptimisationAgent::kind())->toBe('ad_optimisation');
    expect(AdOptimisationAgent::trustTier())->toBe(TrustTier::Trusted);
});

it('tools() returns the 2 read + 1 propose tools', function (): void {
    $names = array_map(fn ($t) => $t->name(), app(AdOptimisationAgent::class)->tools());
    expect($names)->toEqualCanonicalizing([
        'read_ga4_channel_performance',
        'read_margin_opportunity',
        'propose_marketing_action',
    ]);
});

it('guardrails() returns the same 2 guardrails PricingAgent uses (XmlFence skipped per Trusted)', function (): void {
    $classes = array_map(fn ($g) => $g::class, app(AdOptimisationAgent::class)->guardrails());
    expect($classes)->toEqualCanonicalizing([
        SensitiveFieldsStripGuardrail::class,
        OutboundRegexFilterGuardrail::class,
    ]);
});

it('execute() throws LogicException — RunAdOptimisationJob owns orchestration', function (): void {
    app(AdOptimisationAgent::class)->execute([], TrustTier::Trusted);
})->throws(LogicException::class);

it('systemPrompt() renders the ad_optimisation blade with a stable 64-char hash', function (): void {
    $prompt = app(AdOptimisationAgent::class)->systemPrompt();
    expect($prompt)->toContain('ADVICE-ONLY');
    expect(hash('sha256', $prompt))->toHaveLength(64);
});

it('daily budget cap for ad_optimisation is 300p (uses the pre-existing config key)', function (): void {
    expect((int) config('agents.daily_caps.ad_optimisation'))->toBe(300);
});
