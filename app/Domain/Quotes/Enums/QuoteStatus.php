<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Enums;

/**
 * Phase 11 Plan 01 — QuoteStatus enum (QUOT-01).
 *
 * 7 cases mirror the schema column allow-list. Two cases (PendingApproval +
 * Approved) are RESERVED-BUT-UNUSED in v1.0 per CONTEXT.md D-05 + D-06 —
 * they sit in the enum so a future v1.x extension can introduce a multi-step
 * approval workflow without a schema migration.
 *
 * v1.0 transitions (D-04 + D-05 + D-07):
 *   draft → sent → accepted | rejected | expired
 *   sent → draft (admin-only revert within 5 minutes of send — D-05)
 *
 * D-06 explicitly DEFERRED: NO `withdrawn` case ships in v1. Sales overwrites
 * a quote by editing it in draft mode; creating a new quote with the same
 * customer is the v1 pattern. quote_history relation deferred to v1.x.
 *
 * Why a string-backed enum mirroring STATUS_* string constants on the Quote
 * model: the constants pattern (mirror of Phase 8 AgentRun) preserves
 * raw-string queries in legacy callsites — `where('status', 'draft')` works
 * exactly the same as `where('status', QuoteStatus::Draft->value)`.
 *
 * Why STRING in DB column (not native ENUM): Plan 11-01 schema uses
 * `string('status', 32)->default('draft')` for SQLite test-DB compat (the
 * test suite uses SQLite via meetingstore_ops_testing — native ENUM types
 * are MySQL-only). Validation lives at the application layer through this
 * enum + Quote::saving observer (Plan 11-02).
 */
enum QuoteStatus: string
{
    case Draft = 'draft';

    /**
     * D-05 + D-06 RESERVED: enum case exists but unused in v1.0 transitions.
     * Future v1.x can introduce a 4-eyes pricing-manager approval queue
     * without a schema migration. Code paths MUST NOT branch on this case
     * in v1.0; QuoteStatus::isReserved() returns true.
     */
    case PendingApproval = 'pending_approval';

    /**
     * D-05 + D-06 RESERVED: enum case exists but unused in v1.0 transitions.
     * See PendingApproval comment.
     */
    case Approved = 'approved';

    case Sent = 'sent';

    case Accepted = 'accepted';

    case Rejected = 'rejected';

    case Expired = 'expired';

    /**
     * D-05 + D-06 helper — returns true if the case is reserved-but-unused
     * in v1.0. Plan 11-03 Filament Resource hides reserved cases from the
     * status filter dropdown; Plan 11-04 PushQuoteToBitrixDealJob refuses
     * to push reserved-status quotes (defensive fail-loud).
     */
    public static function isReserved(self $case): bool
    {
        return in_array($case, [self::PendingApproval, self::Approved], true);
    }
}
