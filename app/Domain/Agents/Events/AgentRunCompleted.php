<?php

declare(strict_types=1);

namespace App\Domain\Agents\Events;

use App\Domain\Agents\Models\AgentRun;
use App\Foundation\Events\DomainEvent;

/**
 * Phase 8 Plan 04 — agent run completed successfully.
 *
 * Fires after RunAgentJob has:
 *   1. recorded final cost + token counts + tool_calls onto the AgentRun row
 *   2. called BudgetGuard::recordSpend
 *   3. written any AgentResult.suggestionDrafts via AgentSuggestionWriter
 *   4. set status='completed' + completed_at
 *
 * Listeners get the fully-finalised AgentRun including cost_pence,
 * langfuse_trace_id, finish_reason. Phase 10's PricingAgent will subscribe
 * to enrich the triggering margin_change Suggestion's `evidence` JSON with
 * the agent's reasoning summary.
 */
final class AgentRunCompleted extends DomainEvent
{
    public function __construct(public readonly AgentRun $run)
    {
        parent::__construct();
    }
}
