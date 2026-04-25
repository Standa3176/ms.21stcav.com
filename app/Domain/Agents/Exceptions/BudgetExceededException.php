<?php

declare(strict_types=1);

namespace App\Domain\Agents\Exceptions;

/**
 * Phase 8 Plan 03 — thrown by BudgetGuard when a per-kind daily soft cap is hit (D-03).
 *
 * Plan 04 RunAgentJob's catch-block branches on this class (vs
 * MonthlyBudgetExceededException) to set AgentRun.status to
 * AgentRunStatus::BudgetExceeded — distinct from the monthly kill-switch
 * status MonthlyBudgetBlocked. Filament list view filters by status to
 * surface "which kind tripped today's cap".
 */
final class BudgetExceededException extends \RuntimeException
{
}
