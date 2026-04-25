<?php

declare(strict_types=1);

/**
 * Phase 8 Plan 02 — pure-unit tests for CostCalculator. Lives under tests/Unit/
 * so it runs without MySQL — Plan 01's `config/agents.php` has been registered
 * by the framework's container, which is enough.
 *
 * The two pence calculations exercise the published `claude-sonnet-4-6` rates:
 *   input  0.00024 pence/token
 *   output 0.0012  pence/token
 *
 * Test 3 hits the unknown-model path, which throws RuntimeException — fail-loud
 * is safer than silent zero-cost (CONTEXT D-08; an unbudgeted call should
 * surface as a runtime error in AgentRun, not as a free LLM hit).
 */

use App\Domain\Agents\Services\CostCalculator;

it('CostCalculator::compute returns 1 pence for 100 prompt + 50 completion tokens (sonnet-4-6 rates)', function () {
    $calc = app(CostCalculator::class);
    // 100 * 0.00024 + 50 * 0.0012 = 0.024 + 0.06 = 0.084 → ceil → 1
    expect($calc->compute(promptTokens: 100, completionTokens: 50, model: 'claude-sonnet-4-6'))->toBe(1);
});

it('CostCalculator::compute returns 5 pence for 10000 prompt + 2000 completion tokens (sonnet-4-6 rates)', function () {
    $calc = app(CostCalculator::class);
    // 10000 * 0.00024 + 2000 * 0.0012 = 2.4 + 2.4 = 4.8 → ceil → 5
    expect($calc->compute(promptTokens: 10000, completionTokens: 2000, model: 'claude-sonnet-4-6'))->toBe(5);
});

it('CostCalculator::compute throws RuntimeException on unknown model', function () {
    $calc = app(CostCalculator::class);
    expect(fn () => $calc->compute(100, 50, 'unknown-model-xyz'))
        ->toThrow(\RuntimeException::class, 'No pricing configured for model: unknown-model-xyz');
});

it('CostCalculator::compute returns 0 pence for zero tokens (no double-billing on retries)', function () {
    $calc = app(CostCalculator::class);
    expect($calc->compute(0, 0, 'claude-sonnet-4-6'))->toBe(0);
});
