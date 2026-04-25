<?php

declare(strict_types=1);

namespace App\Domain\Agents\Enums;

/**
 * Phase 8 Plan 01 — AgentRun lifecycle state machine (D-06).
 *
 * Transitions:
 *   running → completed
 *   running → failed
 *   running → budget_exceeded            (D-03 daily soft-cap breach)
 *   running → guardrail_blocked          (pre/post guardrail violation)
 *   running → monthly_budget_blocked     (D-02 monthly kill-switch atop daily)
 *
 * Order is contract-stable — `AgentRunTest` asserts the case sequence.
 */
enum AgentRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case BudgetExceeded = 'budget_exceeded';
    case GuardrailBlocked = 'guardrail_blocked';
    case MonthlyBudgetBlocked = 'monthly_budget_blocked';
}
