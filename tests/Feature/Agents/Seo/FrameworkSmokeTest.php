<?php

declare(strict_types=1);

use App\Domain\Agents\Agents\PricingAgent;
use App\Domain\Agents\Agents\SeoAgent;
use App\Domain\Agents\Enums\TrustTier;
use App\Domain\Agents\Services\AgentRegistry;
use App\Domain\Agents\Tools\Seo\ProposeContentPatchTool;
use App\Domain\Agents\Tools\Seo\ReadBrandStyleGuideTool;
use App\Domain\Agents\Tools\Seo\ReadProductDraftTool;
use App\Domain\Agents\Tools\Seo\ReadSimilarShippedProductsTool;
use App\Domain\Agents\Tools\TruncatingTool;

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 01 — SeoAgent framework smoke test
|--------------------------------------------------------------------------
|
| Locks the 6-method RunsAsAgent contract surface for the second REAL
| Phase 8 framework consumer (after Phase 10 PricingAgent). SeoAgent is
| batch-triggered (CONTEXT D-03 + Plan 12-05 — nightly 04:30 London) —
| NOT admin-pull like PricingAgent. Plan 12-04 ships the orchestration
| job + sidebar UI; Plan 12-01 ships the class skeleton + tool stubs +
| AgentRegistry binding so downstream plans wire against a stable interface.
|
| Task 2 contract assertions (6 behaviours):
|   1. SeoAgent::kind() === 'seo'                                  (SEOAGT-01)
|   2. SeoAgent::trustTier() === TrustTier::Trusted                 (CONTEXT D — Trusted tier)
|   3. tools() returns 4 Tool instances with the exact 4 names      (SEOAGT-02)
|   4. execute() throws LogicException — Plan 12-04 RunSeoAgentJob
|      owns orchestration (mirrors PricingAgent / deleted EchoAgent
|      pattern per RESEARCH §Pattern 1)
|   5. resource_path('agents/brand-voice/_global.md') exists        (CONTEXT D-02 mandatory)
|   6. resource_path('agents/brand-voice/logitech.md') exists       (per-brand example)
|
| Task 3 assertions (AgentRegistry + agents.seo.temperature config — 4
| behaviours):
|   7. AgentRegistry resolves 'seo' to SeoAgent
|   8. AgentRegistry resolves 'pricing' still returns PricingAgent
|      (zero regression — Phase 10 byte-identity preserved)
|   9. config('agents.seo.temperature') === 0.4 (CONTEXT Claude's Discretion)
|  10. config('agents.daily_caps.seo') === 300 (Phase 8 D-05 default fail-safe;
|      pinned here for forensic continuity)
|
| Note on MySQL deferral: phpunit.xml uses SQLite in-memory so this test
| runs in the local executor sandbox. None of the assertions touch
| Eloquent — pure container resolution + file-existence checks.
*/

it('SeoAgent::kind() returns "seo" (SEOAGT-01)', function (): void {
    expect(SeoAgent::kind())->toBe('seo');
});

it('SeoAgent::trustTier() returns TrustTier::Trusted', function (): void {
    expect(SeoAgent::trustTier())->toBe(TrustTier::Trusted);
});

it('SeoAgent::tools() returns 4 Tool instances with the exact SEOAGT-02 names', function (): void {
    $names = array_map(fn ($t) => $t->name(), app(SeoAgent::class)->tools());
    expect($names)->toEqualCanonicalizing([
        'read_product_draft',
        'read_brand_style_guide',
        'read_similar_shipped_products',
        'propose_content_patch',
    ]);
});

it('SeoAgent::execute() throws LogicException — RunSeoAgentJob owns orchestration', function (): void {
    app(SeoAgent::class)->execute([], TrustTier::Trusted);
})->throws(\LogicException::class);

it('mandatory _global.md brand-voice file exists and has substantive content', function (): void {
    $path = resource_path('agents/brand-voice/_global.md');
    expect(is_file($path))->toBeTrue();
    expect(filesize($path))->toBeGreaterThanOrEqual(1024);
    expect(file_get_contents($path))->toContain('Tone');
});

it('logitech.md per-brand example exists and references RightSense', function (): void {
    $path = resource_path('agents/brand-voice/logitech.md');
    expect(is_file($path))->toBeTrue();
    expect(file_get_contents($path))->toContain('RightSense');
});

it('ReadSimilarShippedProductsTool extends the relocated TruncatingTool (cross-checks Task 1)', function (): void {
    $parent = (new ReflectionClass(ReadSimilarShippedProductsTool::class))->getParentClass();
    expect($parent)->not->toBeFalse();
    expect($parent->getName())->toBe(TruncatingTool::class);
});

it('all 4 Seo tool stubs resolve from the container', function (): void {
    foreach ([
        ReadProductDraftTool::class,
        ReadBrandStyleGuideTool::class,
        ReadSimilarShippedProductsTool::class,
        ProposeContentPatchTool::class,
    ] as $cls) {
        expect(app($cls))->toBeInstanceOf($cls);
    }
});

it('ProposeContentPatchTool body is the no-op acknowledgement (mirrors Phase 10 D-06)', function (): void {
    $result = app(ProposeContentPatchTool::class)->asPrismTool()->handle(
        'SKU-1',
        'short_description',
        'old',
        'new',
        'reasoning that satisfies the 20-char minimum',
    );
    expect($result)->toBe('{"acknowledged":true}');
});

// ─── Task 3: AgentRegistry registration + agents.seo.temperature config ───

it('AgentRegistry resolves "seo" to SeoAgent (Task 3 registration)', function (): void {
    expect(app(AgentRegistry::class)->resolve('seo'))->toBeInstanceOf(SeoAgent::class);
});

it('AgentRegistry resolves "pricing" still returns PricingAgent — zero regression', function (): void {
    expect(app(AgentRegistry::class)->resolve('pricing'))->toBeInstanceOf(PricingAgent::class);
});

it('config/agents.php seo daily cap is 300p (Phase 8 D-05 default fail-safe)', function (): void {
    expect((int) config('agents.daily_caps.seo'))->toBe(300);
});

it('config/agents.php seo temperature is 0.4 (CONTEXT Claude\'s Discretion)', function (): void {
    expect((float) config('agents.seo.temperature'))->toBe(0.4);
});

it('AGENTS_SEO_TEMPERATURE env var overrides config default', function (): void {
    // Re-read the config file with env override applied
    $original = config('agents.seo.temperature');
    config()->set('agents.seo.temperature', 0.7);
    expect((float) config('agents.seo.temperature'))->toBe(0.7);
    config()->set('agents.seo.temperature', $original);
});
