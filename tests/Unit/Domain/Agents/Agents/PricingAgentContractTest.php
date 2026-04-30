<?php

declare(strict_types=1);

use App\Domain\Agents\Agents\PricingAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\AgentRegistry;

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 01 Task 3 — PricingAgent contract surface (Unit suite)
|--------------------------------------------------------------------------
|
| Mirrors PricingAgentRegistrationTest (Feature suite) but lives under
| tests/Unit/ so it does NOT inherit RefreshDatabase. PricingAgent has
| zero Eloquent dependencies — DB-less verification is sufficient and
| works regardless of MySQL availability (Phase 6/7/8 MySQL-deferral
| precedent — see 08-01-SUMMARY §Issues Encountered).
|
| This test exists alongside PricingAgentRegistrationTest because:
|   - the Feature variant proves the contract holds when DB is available
|     (full-stack: AppServiceProvider → AgentRegistry → app() resolution
|     with the database connection booted)
|   - the Unit variant proves the same contract without booting the DB —
|     so the contract is verified on every CI push regardless of MySQL
|     state, and so a regression in static methods + DI binding cannot
|     hide behind a deferred Feature test
|
| Plan 10-02+ will swap Tools/Pricing/* using() callable bodies for real
| DB queries — at that point the Feature variant's RefreshDatabase becomes
| load-bearing for tool tests. PricingAgent itself stays DB-free.
*/

it('AgentRegistry resolves "pricing" to PricingAgent (PRCAGT-01)', function (): void {
    expect(app(AgentRegistry::class)->resolve('pricing'))->toBeInstanceOf(PricingAgent::class);
});

it('compile-time identity matches PRCAGT-01 + PRCAGT-05', function (): void {
    expect(PricingAgent::kind())->toBe('pricing');
    expect(PricingAgent::trustTier())->toBe(TrustTier::Trusted);
});

it('tools() returns 5 Tool instances with expected PRCAGT-02 names', function (): void {
    $names = array_map(fn ($t) => $t->name(), app(PricingAgent::class)->tools());
    expect($names)->toEqualCanonicalizing([
        'read_margin_history',
        'read_competitor_prices',
        'read_supplier_price_trend',
        'read_sales_volume_90d',
        'propose_margin_band',
    ]);
});

it('guardrails() returns 2 guardrails (PromptInjectionXmlFence skipped per Trusted)', function (): void {
    $classes = array_map(fn ($g) => $g::class, app(PricingAgent::class)->guardrails());
    expect($classes)->toEqualCanonicalizing([
        SensitiveFieldsStripGuardrail::class,
        OutboundRegexFilterGuardrail::class,
    ]);
});

it('execute() throws LogicException — RunPricingAgentJob owns orchestration', function (): void {
    app(PricingAgent::class)->execute([], TrustTier::Trusted);
})->throws(\LogicException::class);

it('config/agents.php pricing daily cap is 500p (PRCAGT-05; locks RESEARCH OQ2)', function (): void {
    expect((int) config('agents.daily_caps.pricing'))->toBe(500);
});
