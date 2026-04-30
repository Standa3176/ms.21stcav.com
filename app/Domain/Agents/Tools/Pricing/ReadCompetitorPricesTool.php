<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 10 Plan 01 — STUB for PRCAGT-02 read_competitor_prices tool.
 *
 * Plan 10-02 ships the real implementation:
 *   - reads competitor_prices over the last 90 days (CONTEXT D-04)
 *   - groups by competitor in the response shape
 *   - caps at 50 most-recent rows across all competitors (most-recent-per-
 *     competitor first)
 *   - 3 KB soft cap with `_truncated:true` + `_total_available:N` hints
 *     (CONTEXT D-05) — Logitech-MeetUp scenario covered (30+ competitors ×
 *     90d days = thousands of rows otherwise)
 *
 * Stub returns `_stub: true` so any premature integration test fires loudly.
 */
final class ReadCompetitorPricesTool extends Tool
{
    public function name(): string
    {
        return 'read_competitor_prices';
    }

    public function description(): string
    {
        return 'Read the last 90 days of competitor prices for a SKU, grouped by competitor. Returns up to 50 most-recent rows across all competitors. Use to spot competitor pricing trends and active price points.';
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
