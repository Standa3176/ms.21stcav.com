<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Listeners;

use App\Domain\ProductAutoCreate\Services\CompletenessScorer;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Events\SupplierStockChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Phase 6 Plan 03 — completeness-score recompute trigger (A3 FINDING mitigation).
 *
 * Plan 01's SaveQuietlyObserverTest confirmed that Laravel 12 `saveQuietly`
 * suppresses BOTH `saving` and `saved` events. Phase 2's `SyncChunkJob` writes
 * via `forceFill + saveQuietly` (to avoid activity_log bloat across 15k-SKU
 * runs), so a Product observer would NEVER fire during the real supplier-sync
 * path. Plan 01 therefore locked in the **listener-based** strategy:
 *
 *   - Subscribe to Phase 2's `SupplierPriceChanged` / `SupplierStockChanged` /
 *     `SupplierSkuMissing` domain events.
 *   - Look up the Product by `woo_product_id` + recompute CompletenessScorer.
 *   - Persist score + missing_fields + computed_at via forceFill+saveQuietly
 *     (same write pattern, keeps activity_log clean).
 *
 * Queue: `default` — work is cheap (one DB read, one DB write, pure PHP score).
 * Fail-open: if the product isn't found (legitimately — supplier SKUs not yet
 * auto-created), we silently return.
 *
 * CreateWooProductJob ALSO calls CompletenessScorer directly after its Woo POST
 * so a fresh draft always carries an accurate initial score.
 */
final class RecomputeCompletenessOnSupplierChange implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private CompletenessScorer $scorer) {}

    public function handlePriceChanged(SupplierPriceChanged $event): void
    {
        $this->recomputeByWooId($event->wooProductId);
    }

    public function handleStockChanged(SupplierStockChanged $event): void
    {
        $this->recomputeByWooId($event->wooProductId);
    }

    public function handleSkuMissing(SupplierSkuMissing $event): void
    {
        $this->recomputeByWooId($event->wooProductId);
    }

    private function recomputeByWooId(int $wooProductId): void
    {
        $product = Product::query()->where('woo_product_id', $wooProductId)->first();
        if ($product === null) {
            return;
        }

        $score = $this->scorer->score($product);
        $product->forceFill([
            'completeness_score' => $score['score'],
            'completeness_missing_fields' => $score['missing_fields'],
            'completeness_computed_at' => now(),
        ])->saveQuietly();
    }
}
