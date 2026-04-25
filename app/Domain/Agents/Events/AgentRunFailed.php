<?php

declare(strict_types=1);

namespace App\Domain\Agents\Events;

use App\Domain\Agents\Models\AgentRun;
use App\Foundation\Events\DomainEvent;

/**
 * Phase 8 Plan 04 — agent run terminated abnormally.
 *
 * Fires from each catch-block in RunAgentJob::handle() — covers:
 *   - BudgetExceededException                  (status=budget_exceeded)
 *   - MonthlyBudgetExceededException           (status=monthly_budget_blocked)
 *   - GuardrailViolationException              (status=guardrail_blocked)
 *   - any other \Throwable                     (status=failed)
 *
 * AgentRun is already persisted with the appropriate status + completed_at +
 * (where applicable) guardrail_failures JSON before this event fires.
 *
 * Plan 05 will register `AlertRecipient`s with `receives_agent_alerts=true`
 * to be notified on the first failure-of-kind per day (CONTEXT §Integration
 * Points "Outbound (notification distribution)" — first failed/budget
 * blocked event of the day per the Plan 1 ThrottledFailedJobNotifier 5-min
 * dedup pattern).
 */
final class AgentRunFailed extends DomainEvent
{
    public function __construct(
        public readonly AgentRun $run,
        public readonly \Throwable $exception,
    ) {
        parent::__construct();
    }
}
