<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 10 Plan 01 — STUB for PRCAGT-02 read_supplier_price_trend tool.
 *
 * Plan 10-02 ships the real implementation:
 *   - reads the last 90 days of supplier_price changes (CONTEXT D-04)
 *   - caps at 30 entries with evenly-spaced downsampling
 *   - 3 KB soft cap with `_truncated`/`_total_available` hints (D-05)
 *   - degraded fallback to current buy_price when audit_log lacks supplier
 *     price history (Phase 2 doesn't populate per-product buy_price audit
 *     trail) — RESEARCH §Tool 3 Option A
 *
 * Stub returns `_stub: true` so any premature integration test fires loudly.
 */
final class ReadSupplierPriceTrendTool extends Tool
{
    public function name(): string
    {
        return 'read_supplier_price_trend';
    }

    public function description(): string
    {
        return 'Read the last 90 days of supplier price changes for a SKU. Returns up to 30 data points (downsampled if more exist) showing buy-price trajectory. Use to gauge cost-side volatility before proposing margin.';
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
