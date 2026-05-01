<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Enums;

/**
 * Phase 11 Plan 01 — RejectionReason enum (D-08).
 *
 * Structured reject reason captured by the "Mark as rejected" Filament row
 * action (Plan 11-03). Persists to `quotes.rejection_metadata` JSON
 * alongside an optional free-text note. Parallels Phase 10 D-09's
 * `agent_rejection_feedback` shape — feeds future v2.x analytics on
 * quote-loss patterns (deferred dashboard surface).
 *
 * 5 cases ratified by CONTEXT.md D-08:
 *   - price_too_high — competitor undercut, customer pushback
 *   - wrong_specifications — equipment didn't match brief
 *   - competitor_won — explicit "we lost to {competitor}" capture
 *   - delayed_decision — customer paused (not lost; resurrect candidate)
 *   - other — free-text note required when this case is selected
 */
enum RejectionReason: string
{
    case PriceTooHigh = 'price_too_high';

    case WrongSpecifications = 'wrong_specifications';

    case CompetitorWon = 'competitor_won';

    case DelayedDecision = 'delayed_decision';

    case Other = 'other';
}
