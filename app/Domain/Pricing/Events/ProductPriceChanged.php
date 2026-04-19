<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 3 Plan 02 — emitted by RecomputePriceListener when a recomputed
 * products.sell_price (or product_variants.sell_price) differs from the stored
 * value in integer pennies (D-13 fire-on-diff contract).
 *
 * Downstream consumers (Phase 2's Woo-push listener, shadow-gated by
 * WOO_WRITE_ENABLED) subscribe to this and PUT the new price back to Woo.
 * correlation_id threads through DomainEvent so the supplier sync row, the
 * recompute log line, and the Woo push log line are all joinable on CID.
 *
 * Primitives only per DomainEvent convention (T-03-05 mitigation — passing
 * Eloquent models via SerializesModels leaks hidden columns on dispatch).
 */
final class ProductPriceChanged extends DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly string $sku,
        public readonly int $oldPennies,
        public readonly int $newPennies,
        public readonly int $marginBasisPoints,
        public readonly string $resolutionSource,  // 'override' | 'brand_category' | 'category' | 'brand' | 'default_tier'
    ) {
        parent::__construct();
    }
}
