<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 Plan 03 Task 3 — A3 FALLBACK STUB.
 *
 * A3 gate at planner dev-env check 2026-04-19:
 *   grep -rn "getOrders|/orders" app/Domain/Sync/Services/WooClient.php
 *   → WOOCLIENT_ORDERS_MISSING
 *
 * The nightly authoritative recache path is therefore event-driven-only
 * until WooClient gains an orders endpoint. This stub:
 *   - Keeps the schedule entry (competitor:sales-recache at 02:00) live so
 *     post-WooClient-extension work activates real recache with zero
 *     plumbing changes.
 *   - Logs recache.wooclient_orders_missing so ops observability shows the
 *     gap rather than silently skipping.
 *   - Does NOT mutate products.last_sales_count_90d — the real-time
 *     IncrementSkuSalesCount listener (Task 1) remains the sole
 *     maintenance path.
 *
 * TODO-A3-FOLLOWUP (post-Phase-5): extend WooClient with:
 *   public function getOrders(array $params): array;
 * and replace this job body with the aggregation plan:
 *   1. GET /orders?after={90d_ago}&status=any&per_page=100, paginated.
 *   2. Aggregate SKU → count from line_items[] using W1 semantics
 *      (1 increment per line-item; NOT multiplied by quantity; matches
 *      IncrementSkuSalesCount listener exactly to prevent drift).
 *   3. Filter aggregated SKUs by this->skus and UPDATE products set
 *      last_sales_count_90d = N, last_sales_count_computed_at = now().
 *   4. SKUs in this->skus with zero orders: set count=0 + computed_at=now
 *      (authoritative overwrite).
 *
 * Queue: sync-bulk — long-running external-IO queue (when activated).
 * Routed via onQueue() rather than $queue property to avoid PHP 8.4
 * trait-collision (Plan 05-02 precedent on IngestCompetitorCsvJob).
 */
class RecacheSalesCountsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var string[] */
    public array $skus;

    /**
     * @param  string[]  $skus  SKUs to recache (1..N per job; command chunks at 100)
     *
     * Queue routed via onQueue() in the constructor rather than a `public
     * string $queue` property to avoid PHP 8.4 trait-collision with
     * Illuminate\Bus\Queueable (Plan 05-02 precedent — documented in Plan
     * 05-02 SUMMARY Deviation #1).
     */
    public function __construct(array $skus)
    {
        $this->skus = array_values($skus);
        $this->onQueue('sync-bulk');
    }

    public function handle(): void
    {
        Log::warning('recache.wooclient_orders_missing', [
            'sku_count' => count($this->skus),
            'sample_skus' => array_slice($this->skus, 0, 5),
            'note' => 'A3 fallback — real-time IncrementSkuSalesCount listener is authoritative until WooClient exposes /orders',
            'followup' => 'TODO-A3-FOLLOWUP (post-Phase-5)',
        ]);
    }
}
