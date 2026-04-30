<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 10 Plan 01 — STUB for PRCAGT-02 read_margin_history tool.
 *
 * Plan 10-02 ships the real implementation:
 *   - reads activity_log + Suggestion rows for the last 90 days
 *     (CONTEXT D-04 — aligned with Phase 5 sales-threshold-90d window)
 *   - downsamples to ≤30 entries with evenly-spaced sampling
 *   - enforces the 3 KB soft cap with `_truncated:true` + `_total_available:N`
 *     hints (CONTEXT D-05)
 *
 * Stub returns `_stub: true` so any premature integration test fires loudly
 * rather than silently returning empty arrays the LLM would treat as
 * "no data" signal.
 */
final class ReadMarginHistoryTool extends Tool
{
    public function name(): string
    {
        return 'read_margin_history';
    }

    public function description(): string
    {
        return 'Read the last 90 days of margin changes for a SKU. Returns up to 30 entries (downsampled if more exist) with date, rule scope, old/new bps, delta. Use once per SKU to understand price trajectory.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'The SKU to look up')
            ->using(fn (string $sku): string => json_encode([
                '_stub' => true,
                '_phase' => '10-01-skeleton',
                '_note' => 'Plan 10-02 ships the real implementation',
                'sku' => $sku,
            ], JSON_THROW_ON_ERROR));
    }
}
