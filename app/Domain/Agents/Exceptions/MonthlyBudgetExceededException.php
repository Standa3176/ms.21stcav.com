<?php

declare(strict_types=1);

namespace App\Domain\Agents\Exceptions;

/**
 * Phase 8 Plan 03 — thrown by BudgetGuard when the global monthly £200
 * kill-switch is reached (D-01 / D-02).
 *
 * Distinct class from BudgetExceededException so Plan 04 RunAgentJob's
 * catch-block can branch and set AgentRun.status to MonthlyBudgetBlocked,
 * write a one-off `agent_budget_exceeded` Suggestion (D-02 — first
 * occurrence per month surfaces in admin inbox; subsequent dispatches log
 * but don't re-suggest), and trigger the AlertRecipient
 * receives_agent_alerts notification.
 *
 * Kill-switch precedence: this exception fires BEFORE the daily check —
 * once monthly is exhausted, every kind is blocked regardless of its
 * own day's spend.
 */
final class MonthlyBudgetExceededException extends \RuntimeException
{
}
