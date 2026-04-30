<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 10 Plan 01 — STUB for PRCAGT-02 read_sales_volume_90d tool.
 *
 * Plan 10-02 ships the real implementation:
 *   - reads `products.last_sales_count_90d` cached column (Phase 5 nightly
 *     recache job populates it via SalesCounterService)
 *   - reads `products.last_sales_count_computed_at` (RESEARCH schema
 *     correction — CONTEXT.md said `sales_count_computed_at` but the
 *     migration `2026_04_21_090600_add_sales_count_90d_to_products.php`
 *     uses `last_sales_count_computed_at`)
 *   - returns `_cache_age_hours` hint when computed > 24h ago so the agent
 *     can reflect cache freshness in its confidence reasoning
 *   - no soft cap needed (single integer + timestamp payload)
 *
 * Stub returns `_stub: true` so any premature integration test fires loudly.
 */
final class ReadSalesVolume90dTool extends Tool
{
    public function name(): string
    {
        return 'read_sales_volume_90d';
    }

    public function description(): string
    {
        return 'Read the cached 90-day sales count for a SKU (units sold). Returns the count plus _cache_age_hours when stale (>24h). Use to gauge demand strength before proposing margin.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'The SKU to look up')
            ->using(fn (string $sku): string => json_encode([
                '_stub' => true,
                '_phase' => '10-01-skeleton',
                '_note' => 'Plan 10-02 ships the real implementation reading products.last_sales_count_90d + last_sales_count_computed_at',
                'sku' => $sku,
            ], JSON_THROW_ON_ERROR));
    }
}
