<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 6 Plan 03 — fired after a successful Woo POST creates the draft product.
 *
 * Six readonly properties cover the full "what happened" contract so the Phase 7
 * dashboard tile + any downstream listeners can render status without another
 * DB lookup. completenessScore is the freshly-computed score at create-time;
 * the D-08 listener recomputes after supplier-feed mutations.
 */
final class AutoCreateSucceeded extends DomainEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly int $wooProductId,
        public readonly string $sku,
        public readonly string $slug,
        public readonly int $completenessScore,
        public readonly string $autoCreateStatus,
    ) {
        parent::__construct();
    }
}
