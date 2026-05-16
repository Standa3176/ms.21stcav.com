<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture: Phase 12 Plan 03 — config/seo_agent.php shape + regex
|                                    compilability invariant
|--------------------------------------------------------------------------
|
| Locks:
|   1. config('seo_agent.guardrails') has the 3 starter categories
|      (competitor_brands / price_claims_absolute / marketing_superlatives)
|   2. Each category contains ≥4 regex patterns (≥12 total)
|   3. Every regex compiles via @preg_match (returns 0 on no-match, NOT false
|      from compile error). A malformed regex would surface here at CI
|      rather than silently degrading the guardrail to no-op at runtime
|      (T-12-03-06 — accepted threat, but cheap to catch in dev)
|   4. GuardrailViolationException accepts the new failedPatternKey +
|      matchedExcerpt fields AND remains backward-compatible with Phase 10
|      construction patterns (default constructor + fromGuardrail factory)
|
| Threat surface: T-12-03-01..03 (regex coverage), T-12-03-06 (malformed
| pattern crashing guardrail). Pattern set lives in version control so PR
| review is the calibration loop; this test gates the file against the
| shape contract Plan 12-04 RunSeoAgentJob depends on.
*/

use App\Domain\Agents\Exceptions\GuardrailViolationException;

it('config/seo_agent.php loads and exposes guardrails key', function () {
    $config = require base_path('config/seo_agent.php');

    expect($config)->toBeArray();
    expect($config)->toHaveKey('guardrails');
    expect($config['guardrails'])->toBeArray();
});

it('config/seo_agent.php guardrails has 3 categories (competitor_brands / price_claims_absolute / marketing_superlatives)', function () {
    $guardrails = (array) config('seo_agent.guardrails', []);

    expect($guardrails)->toHaveKey('competitor_brands');
    expect($guardrails)->toHaveKey('price_claims_absolute');
    expect($guardrails)->toHaveKey('marketing_superlatives');
});

it('competitor_brands category has ≥4 regex patterns', function () {
    $patterns = (array) data_get(require base_path('config/seo_agent.php'), 'guardrails.competitor_brands', []);

    expect(count($patterns))->toBeGreaterThanOrEqual(4);
});

it('price_claims_absolute category has ≥4 regex patterns', function () {
    $patterns = (array) data_get(require base_path('config/seo_agent.php'), 'guardrails.price_claims_absolute', []);

    expect(count($patterns))->toBeGreaterThanOrEqual(4);
});

it('marketing_superlatives category has ≥4 regex patterns', function () {
    $patterns = (array) data_get(require base_path('config/seo_agent.php'), 'guardrails.marketing_superlatives', []);

    expect(count($patterns))->toBeGreaterThanOrEqual(4);
});

it('total regex patterns across all 3 categories is ≥12', function () {
    $guardrails = (array) config('seo_agent.guardrails', []);
    $total = array_sum(array_map(fn ($cat) => count((array) $cat), $guardrails));

    expect($total)->toBeGreaterThanOrEqual(12);
});

it('every regex in config/seo_agent.php compiles (no malformed patterns)', function () {
    $guardrails = (array) config('seo_agent.guardrails', []);

    foreach ($guardrails as $key => $regexes) {
        foreach ((array) $regexes as $regex) {
            // @preg_match returns false on compile error, 0 on no-match, 1 on match.
            // Empty string is a guaranteed no-match so a return of false here means
            // the pattern itself failed to compile.
            $result = @preg_match($regex, '');
            expect($result)->not->toBeFalse(
                "Regex `{$regex}` in category `{$key}` failed to compile"
            );
        }
    }
});

it('GuardrailViolationException accepts new failedPatternKey + matchedExcerpt fields', function () {
    $e = new GuardrailViolationException(
        guardrailClass: 'App\\Some\\Guardrail',
        message: 'forbidden pattern matched',
        failedPatternKey: 'marketing_superlatives',
        matchedExcerpt: 'revolutionary new product',
    );

    expect($e->guardrailClass)->toBe('App\\Some\\Guardrail');
    expect($e->getMessage())->toBe('forbidden pattern matched');
    expect($e->failedPatternKey)->toBe('marketing_superlatives');
    expect($e->matchedExcerpt)->toBe('revolutionary new product');
});

it('GuardrailViolationException::fromGuardrail() factory still works (Phase 10 backward-compat)', function () {
    // Phase 10 RunPricingAgentJob constructs the exception via this static
    // factory. The new readonly fields default to empty string so the existing
    // call shape continues to compile.
    $e = GuardrailViolationException::fromGuardrail(
        'App\\Domain\\Agents\\Guardrails\\OutboundRegexFilterGuardrail',
        'cost_price=1234 leaked in response'
    );

    expect($e->guardrailClass)->toBe('App\\Domain\\Agents\\Guardrails\\OutboundRegexFilterGuardrail');
    expect($e->getMessage())->toBe('cost_price=1234 leaked in response');
    expect($e->failedPatternKey)->toBe('');
    expect($e->matchedExcerpt)->toBe('');
});

it('GuardrailViolationException is still a RuntimeException (Phase 8 contract)', function () {
    $e = new GuardrailViolationException();

    expect($e)->toBeInstanceOf(\RuntimeException::class);
});
