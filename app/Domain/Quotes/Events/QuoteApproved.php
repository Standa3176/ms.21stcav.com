<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 11 Plan 03 STUB — QuoteApproved domain event (D-04 + QUOT-05).
 *
 * Fires on the draft → sent transition inside ApproveQuoteAction's
 * DB::transaction. Plan 11-04 wires the PushQuoteToBitrix listener (in CRM
 * domain) to consume this event and dispatch PushQuoteToBitrixDealJob on the
 * crm-bitrix queue. Phase 7 dashboard subscribes for the "quotes sent today"
 * widget (post-Phase-11 dashboard refresh).
 *
 * Plan 11-03 ships this as a readonly DTO so ApproveQuoteAction can dispatch
 * it cleanly today; Plan 11-04 will not modify the payload shape — only add
 * the listener that catches it.
 *
 * ShouldDispatchAfterCommit ensures the event fires AFTER the surrounding
 * DB::transaction commits; if the transaction rolls back, the listener never
 * runs (Phase 1 D-? domain-event pattern).
 */
final class QuoteApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $quoteId,
        public readonly ?int $userId,
        public readonly string $customerEmail,
        public readonly ?int $customerGroupId,
        public readonly string $statusBefore,
        public readonly string $statusAfter,
        public readonly string $correlationId,
    ) {}
}
