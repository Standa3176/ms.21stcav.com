<?php

declare(strict_types=1);

namespace App\Domain\Sync\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Fired by SyncChunkJob after a successful Woo price write.
 *
 * Downstream listeners (Phase 3 PricingEngine) may recompute sell_price based on
 * the new supplier buy_price. Implements ShouldDispatchAfterCommit via inheritance
 * so a rolled-back per-SKU write does NOT fire this (Pitfall P2-I).
 */
final class SupplierPriceChanged extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly string $oldPrice,
        public readonly string $newPrice,
        public readonly string $reason = 'supplier_sync',
    ) {
        parent::__construct();
    }
}
