<?php

declare(strict_types=1);

namespace App\Domain\Sync\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Fired by MarkMissingSkusJob for every Woo SKU absent from the supplier feed.
 *
 * $newStatus is the Woo status after the flip:
 *   - 'pending' for simple products without the custom-ms tag (SYNC-06)
 *   - 'publish' for simple products WITH the custom-ms tag (D-03 carve-out — unchanged)
 *   - 'private' for variations regardless of parent tag (D-03 granular)
 *
 * hadCustomMsTag surfaces the decision rationale for downstream listeners / audit.
 */
final class SupplierSkuMissing extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly int $wooProductId,
        public readonly ?int $wooVariationId,
        public readonly bool $hadCustomMsTag,
        public readonly string $newStatus,
    ) {
        parent::__construct();
    }
}
