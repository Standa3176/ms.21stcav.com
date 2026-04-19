<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

use App\Domain\Products\Models\Product;

/**
 * Phase 5 Plan 03 Task 1 — denormalised 90-day sales counter lookup.
 *
 * Reads products.last_sales_count_90d (Plan 05-01 column). ZERO live Woo
 * REST per call — live lookup across 2000+ SKUs per CSV ingest would trip
 * Woo's 2-req/sec rate limit in minutes. Instead the column is maintained
 * in hybrid fashion:
 *
 * 1. Real-time: IncrementSkuSalesCount listener increments the column
 *    when Phase 1's OrderReceived fires (one increment per line-item — W1
 *    FROZEN semantics; NOT multiplied by quantity).
 *
 * 2. Nightly authoritative recache (TODO-A3-FOLLOWUP): CompetitorSalesRecache
 *    command runs at 02:00 and dispatches RecacheSalesCountsJob which
 *    recomputes the counter via Woo REST GET /orders?after={90d_ago}. This
 *    corrects any drift from (a) clock-skew, (b) orders that arrived before
 *    the listener was wired, (c) orders Woo backfills post-facto. A3 gate
 *    outcome in this plan: WooClient lacks a getOrders method; the nightly
 *    recache stub logs `recache.wooclient_orders_missing` and exits — real
 *    implementation deferred to a post-Phase-5 WooClient extension.
 *
 * Threshold check reads config('competitor.sales_threshold_90d', 10) —
 * locked from Plan 05-01 at 10 orders/90 days per D-05.
 */
class SalesCounterService
{
    public function getCount(string $sku): int
    {
        $count = Product::where('sku', $sku)->value('last_sales_count_90d');

        return (int) ($count ?? 0);
    }

    public function meetsThreshold(string $sku): bool
    {
        $threshold = (int) config('competitor.sales_threshold_90d', 10);

        return $this->getCount($sku) >= $threshold;
    }
}
