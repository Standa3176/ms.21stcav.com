<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Listeners;

use App\Domain\Pricing\Services\PriceRecomputer;
use App\Domain\Sync\Events\SupplierPriceChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 3 Plan 02 Task 2; refactored in Plan 04 Task 1.
 *
 * Subscribes to Phase 2's SupplierPriceChanged and delegates the recompute
 * pipeline to App\Domain\Pricing\Services\PriceRecomputer (the shared core
 * also invoked by the bulk RecomputePriceJob — one implementation, no drift).
 *
 * Queue: default. sync-woo-push is reserved for the downstream Woo PUT
 * triggered by ProductPriceChanged. persist=true is hardcoded — dry-run is
 * an operator-tool concern (pricing:recompute --dry-run), not event-stream.
 */
final class RecomputePriceListener implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly PriceRecomputer $recomputer,
    ) {}

    public function handle(SupplierPriceChanged $event): void
    {
        $this->recomputer->recompute(
            wooProductId: $event->wooProductId,
            wooVariationId: $event->wooVariationId,
            sku: $event->sku,
            correlationId: $event->correlationId,
            persist: true,
        );
    }
}
