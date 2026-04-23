<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 6 Plan 03 — fired at CreateWooProductJob entry.
 *
 * Carries only the SKU (T-03-05: primitives, never full Eloquent models).
 * Inherits ShouldDispatchAfterCommit + correlation_id via DomainEvent base
 * (Pitfall P2-I rollback-safe dispatch).
 */
final class AutoCreateAttempted extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
    ) {
        parent::__construct();
    }
}
