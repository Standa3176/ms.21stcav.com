<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use RuntimeException;

/**
 * Phase 8 Plan 02 — token-usage → pence conversion (D-08 post-flight cost calc).
 *
 * Reads per-million-token rates from `config('agents.pricing.{model}')`. Plan 01
 * seeded the table for `claude-sonnet-4-6` ($3/$15 per million in/out, expressed
 * as 0.00024 / 0.0012 pence-per-token assuming £/$ ≈ 1.0; operator recalibrates
 * after the first 2 weeks of real spend per CONTEXT D-08).
 *
 * Rounding: ceil() — we round UP so a 0.5p call records as 1p, never as 0.
 * BudgetGuard then accumulates these post-flight increments into the daily +
 * monthly Cache::add counters; under-counting would silently let the £200/month
 * kill-switch slip past its true ceiling.
 *
 * Throws RuntimeException for unknown model names. Fail-loud is safer than
 * silent zero — an agent calling an unbudgeted model should surface as a
 * runtime error in the AgentRun row, not as a free LLM call.
 */
final class CostCalculator
{
    /**
     * @param  string  $model  Anthropic model identifier (e.g. claude-sonnet-4-6).
     *
     * @throws RuntimeException when the model has no pricing entry in config/agents.php.
     */
    public function compute(int $promptTokens, int $completionTokens, string $model): int
    {
        $rates = config("agents.pricing.{$model}");

        if (! is_array($rates)
            || ! isset($rates['input_pence_per_token'], $rates['output_pence_per_token'])
        ) {
            throw new RuntimeException("No pricing configured for model: {$model}");
        }

        $pence = ($promptTokens * (float) $rates['input_pence_per_token'])
            + ($completionTokens * (float) $rates['output_pence_per_token']);

        // ceil() rounds 0.084p → 1p so we never under-bill the kill-switch counter.
        return (int) ceil($pence);
    }
}
