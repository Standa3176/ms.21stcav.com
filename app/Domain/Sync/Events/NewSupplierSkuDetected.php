<?php

declare(strict_types=1);

namespace App\Domain\Sync\Events;

use App\Foundation\Events\DomainEvent;

/**
 * D-09 — fired by SyncSupplierCommand when a supplier feed row has no matching Woo SKU.
 *
 * Phase 6 wires the real CreateWooProductJob listener (AUTO-01 producer);
 * Phase 2 ships StubNewSupplierSkuListener so the event doesn't pile up in
 * failed_jobs waiting for a handler.
 */
final class NewSupplierSkuDetected extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly string $supplierPrice,
        public readonly int $supplierStock,
    ) {
        parent::__construct();
    }
}
