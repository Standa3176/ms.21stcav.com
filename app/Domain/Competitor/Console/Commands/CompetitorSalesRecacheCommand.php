<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Console\Commands;

use App\Console\Commands\BaseCommand;
use App\Domain\Competitor\Jobs\RecacheSalesCountsJob;
use App\Domain\Products\Models\Product;

/**
 * Phase 5 Plan 03 Task 3 — nightly sales-counter recache driver.
 *
 * Scheduled daily at 02:00 via routes/console.php. Chunks products by 100
 * SKUs per job and dispatches one RecacheSalesCountsJob per chunk onto the
 * sync-bulk queue.
 *
 * A3 fallback path: the dispatched job is currently a stub (see
 * RecacheSalesCountsJob docblock for TODO-A3-FOLLOWUP). The command +
 * schedule ship today so activating real recache post-Phase-5 is a
 * single-class body change, not a plumbing rewrite.
 *
 * Extends BaseCommand → correlation_id auto-threads through Context so
 * job dispatches + subsequent DB writes share the operator's CID.
 */
class CompetitorSalesRecacheCommand extends BaseCommand
{
    protected $signature = 'competitor:sales-recache';

    protected $description = 'Recompute last_sales_count_90d for every product via nightly chunked jobs (Phase 5 Plan 03; A3 fallback stub).';

    protected function perform(): int
    {
        $chunksDispatched = 0;

        Product::query()
            ->select(['id', 'sku'])
            ->whereNotNull('sku')
            ->orderBy('id')
            ->chunk(100, function ($products) use (&$chunksDispatched): void {
                $skus = $products
                    ->pluck('sku')
                    ->filter(fn ($sku) => is_string($sku) && $sku !== '')
                    ->values()
                    ->all();

                if ($skus === []) {
                    return;
                }

                RecacheSalesCountsJob::dispatch($skus)->onQueue('sync-bulk');
                $chunksDispatched++;
            });

        $this->info(sprintf(
            'Dispatched %d RecacheSalesCountsJob chunk(s) onto the sync-bulk queue.',
            $chunksDispatched,
        ));

        return 0;
    }
}
