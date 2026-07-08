<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Listeners;

use App\Domain\ProductAutoCreate\Services\ProductOverrideGuard;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Events\SupplierSkuMissing;
use App\Domain\Sync\Events\SupplierStockChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6 Plan 05 — AUTO-10 pin enforcement via listener extension (D-11).
 *
 * Subscribes to Phase 2's 3 post-commit supplier-change events and delegates
 * to ProductOverrideGuard::revertIfPinned for any pinned field. Phase 2's
 * SyncChunkJob code is NOT modified (D-11 mandate); pin enforcement is a pure
 * listener overlay.
 *
 * Q5 resolution (revert-after-the-fact): Listener fires AFTER Phase 2's
 * SyncChunkJob has already written to Woo (events carry ShouldDispatchAfterCommit
 * via DomainEvent base + Phase 2's SyncChunkJob dispatches events only on
 * successful updates). If the product has a matching pin flag set on its
 * ProductOverride row, the guard issues a revert PUT so the Laravel-persisted
 * value wins. Window of Woo divergence is milliseconds on the sync-bulk queue —
 * documented accepted limitation (Plan's output spec).
 *
 * Handler methods per event (bound via ListenerClass@method string in
 * EventServiceProvider::$listen):
 *   - handlePriceChanged : Woo 'regular_price' field lookup → pin_price flag
 *   - handleStockChanged : Woo 'stock_quantity' field (NOT pinnable v1 — guard no-ops)
 *   - handleSkuMissing   : Woo 'status' field (NOT pinnable v1 — guard no-ops)
 *
 * The last two handlers look redundant at first glance — stock_quantity +
 * status have no pin_* map entry in ProductOverrideGuard so the guard
 * short-circuits silently. They are wired anyway so if v2 adds pin_stock or
 * pin_status the plumbing is already in place.
 *
 * Queue strategy: sync-bulk (same queue as the triggering Phase 2 event) so
 * the revert PUT rides the same worker shard and minimises divergence latency.
 * Selected via viaQueue() — queued LISTENERS (unlike jobs) have no onQueue()
 * from InteractsWithQueue, so a constructor onQueue() call fatals on
 * instantiation. viaQueue() is the sanctioned hook and avoids the PHP 8.4
 * public-$queue property collision (Phase 5 Plan 02 + Phase 6 Plan 02 lesson).
 *
 * Fail-open: per-event exceptions from the guard are logged + swallowed via
 * safeRevert() so a single failed revert does NOT cascade-fail the rest of the
 * Phase 2 event handler chain (T-06-05-02 mitigation).
 */
final class ApplyPinsDuringSync implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private ProductOverrideGuard $guard) {}

    public function viaQueue(): string
    {
        return 'sync-bulk';
    }

    public function handlePriceChanged(SupplierPriceChanged $event): void
    {
        $this->safeRevert($event->wooProductId, ['regular_price'], 'supplier_price_changed');
    }

    public function handleStockChanged(SupplierStockChanged $event): void
    {
        // stock_quantity is not pinnable in v1 — guard short-circuits.
        // Wiring exists so v2 pin_stock lands without listener changes.
        $this->safeRevert($event->wooProductId, ['stock_quantity'], 'supplier_stock_changed');
    }

    public function handleSkuMissing(SupplierSkuMissing $event): void
    {
        // status transitions are not pinnable in v1 — guard short-circuits.
        $this->safeRevert($event->wooProductId, ['status'], 'supplier_sku_missing');
    }

    /**
     * Call the guard with try/catch so a failed revert doesn't cascade-fail the
     * sibling listener chain (e.g. Phase 3's RecomputePriceListener on
     * SupplierPriceChanged). Logs at warning level so ops awareness exists.
     *
     * @param  array<int, string>  $fields
     */
    private function safeRevert(int $wooProductId, array $fields, string $source): void
    {
        try {
            $this->guard->revertIfPinned($wooProductId, $fields, $source);
        } catch (\Throwable $e) {
            Log::warning('product_auto_create.pin_revert_failed', [
                'woo_product_id' => $wooProductId,
                'fields' => $fields,
                'source' => $source,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            // Intentionally do NOT rethrow.
        }
    }
}
