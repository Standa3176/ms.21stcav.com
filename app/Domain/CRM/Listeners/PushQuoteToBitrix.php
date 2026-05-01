<?php

declare(strict_types=1);

namespace App\Domain\CRM\Listeners;

use App\Domain\CRM\Jobs\PushQuoteToBitrixDealJob;
use App\Domain\Quotes\Events\QuoteApproved;

/**
 * Phase 11 Plan 04 — listener for QuoteApproved (Anti-Pattern 2 prevention).
 *
 * Lives in app/Domain/CRM/Listeners/ (NOT app/Domain/Quotes/Listeners/) per
 * CONTEXT.md D-XX one-way arrow: Quotes domain emits QuoteApproved; CRM
 * domain consumes via this listener. This keeps BitrixClient + BitrixEntityMap
 * + push wiring inside CRM. Quotes domain MUST NOT import BitrixClient.
 *
 * Deptrac proves the directional invariant on every CI run:
 *   - CRM ruleset includes Quotes (CRM listener may read Quote model — ALLOWED)
 *   - Quotes ruleset MUST NOT include CRM (Quotes domain doesn't import
 *     anything from CRM — DENIED)
 *
 * Single responsibility: dispatch PushQuoteToBitrixDealJob with the quote_id +
 * correlation_id from the event payload. The Job (NOT the listener) owns the
 * Bitrix interaction, retry policy, idempotency, and DLQ.
 *
 * EventServiceProvider.$listen registers this listener against QuoteApproved.
 * Email dispatch (QuoteSentMail) is NOT routed through this listener — that
 * lives in ApproveQuoteAction (Plan 11-03) inside the same DB transaction
 * boundary. This listener handles ONLY the Bitrix push.
 */
final class PushQuoteToBitrix
{
    public function handle(QuoteApproved $event): void
    {
        PushQuoteToBitrixDealJob::dispatch(
            $event->quoteId,
            $event->correlationId,
        );
    }
}
