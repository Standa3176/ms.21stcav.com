<?php

declare(strict_types=1);

use App\Domain\Agents\Agents\PricingAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Guardrails\OutboundRegexFilterGuardrail;
use App\Domain\Agents\Guardrails\SensitiveFieldsStripGuardrail;
use App\Domain\Agents\Services\AgentRegistry;

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 01 Task 3 — PricingAgent registration + contract surface
|--------------------------------------------------------------------------
|
| Locks the 6-method RunsAsAgent contract surface for the first REAL
| Phase 8 framework consumer. PricingAgent is admin-pull triggered (CONTEXT
| D-01) — Filament button on margin_change Suggestion detail (Plan 10-04
| ships the Filament button + RunPricingAgentJob). Plan 10-01 ships the
| class skeleton + AgentRegistry binding so downstream plans wire against
| a stable interface.
|
| 6 contract assertions:
|   1. AgentRegistry resolves 'pricing' to PricingAgent
|   2. PricingAgent::kind() === 'pricing'                         (PRCAGT-01)
|   3. PricingAgent::trustTier() === TrustTier::Trusted           (PRCAGT-05)
|   4. tools() returns 5 Tool instances with the exact 5 names    (PRCAGT-02)
|   5. guardrails() returns 2 guardrails (PromptInjectionXmlFence
|      skipped per Trusted tier)
|   6. execute() throws LogicException — RunPricingAgentJob owns
|      orchestration (RESEARCH §Pattern 2 — same shape as deleted
|      EchoAgent::execute)
|
| Plus 1 budget-cap lock:
|   7. config('agents.daily_caps.pricing') === 500                (PRCAGT-05)
|      — RESEARCH Open Question 2 RESOLVED; locks the value so future
|      config drift fails build.
|
| Note on MySQL deferral (Phase 6/7/8 precedent): Feature tests inherit
| RefreshDatabase via Pest's default dataset for tests/Feature. When MySQL
| is offline (port 3306 refused), tests fail at the connection step rather
| than the assertion step. The PHP-l + container-resolution checks below
| still validate the contract; runtime DB binding only matters for tests
| that touch Eloquent (none here — PricingAgent is pure DI wiring).
*/

it('AgentRegistry resolves "pricing" to PricingAgent (PRCAGT-01 contract)', function (): void {
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
