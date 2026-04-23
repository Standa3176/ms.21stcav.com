<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Events;

use App\Foundation\Events\DomainEvent;

/**
 * Phase 6 Plan 03 — fired for in-job terminal failures that short-circuit the
 * pipeline (e.g. duplicate detected, supplier returned empty, pre-POST bail).
 *
 * Distinct from the queued-retry DLQ path (which is modelled via Suggestion
 * rows created by CreateWooProductJob::failed() after Laravel exhausts $tries).
 * AutoCreateFailed fires SYNCHRONOUSLY inside handle() when the job decides to
 * stop without an exception — duplicate SKU is the canonical example.
 */
final class AutoCreateFailed extends DomainEvent
{
    public function __construct(
        public readonly string $sku,
        public readonly string $reason,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $exceptionMessage = null,
    ) {
        parent::__construct();
    }
}
