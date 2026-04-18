<?php

declare(strict_types=1);

namespace App\Domain\Sync\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Fired by SyncChunkJob after a successful Woo stock write.
 *
 * Listeners (Phase 3+) may propagate zero-stock flips to dependent systems.
 * After-commit semantics via DomainEvent base class.
 */
final class SupplierStockChanged extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly int $oldStock,
        public readonly int $newStock,
        public readonly string $reason = 'supplier_sync',
    ) {
        parent::__construct();
    }
}
