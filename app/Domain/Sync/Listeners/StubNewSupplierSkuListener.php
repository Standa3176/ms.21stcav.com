<?php

declare(strict_types=1);

namespace App\Domain\Sync\Listeners;

use App\Domain\Sync\Events\NewSupplierSkuDetected;
use Illuminate\Support\Facades\Log;

/**
 * D-09 stub listener — Phase 2 establishes the event producer; Phase 6 wires the
 * real CreateWooProductJob listener (AUTO-01). Without this stub the event would
 * accumulate in failed_jobs waiting for a handler.
 *
 * Intentionally no-op: logs receipt for operator visibility, no DB writes,
 * returns immediately.
 */
final class StubNewSupplierSkuListener
{
    public function handle(NewSupplierSkuDetected $event): void
    {
        Log::info('NewSupplierSkuDetected (stub — Phase 6 wires the real handler)', [
            'sku' => $event->sku,
            'supplier_price' => $event->supplierPrice,
            'supplier_stock' => $event->supplierStock,
            'correlation_id' => $event->correlationId,
        ]);
    }
}
