<?php

declare(strict_types=1);

namespace App\Domain\Agents\Events;

use App\Domain\Agents\Models\AgentRun;
use App\Foundation\Events\DomainEvent;

/**
 * Phase 8 Plan 04 — agent run started lifecycle event.
 *
 * Fires AFTER RunAgentJob has persisted the AgentRun row with status='running'
 * but BEFORE BudgetGuard pre-flight + ClaudeClient call. Listeners get a
 * fully-hydrated AgentRun row with started_at + correlation_id populated.
 *
 * Phase 8 ships zero listeners (events fire and dissipate, captured only by
 * AgentRun's LogsActivity trait). Phase 10/12/14/15 may subscribe for
 * domain-specific signals (e.g. PricingAgentRunStarted → freeze rule
 * recompute for the duration).
 *
 * ShouldDispatchAfterCommit (inherited from DomainEvent): inside any
 * surrounding DB::transaction, the event only dispatches if the transaction
 * commits. RunAgentJob's writes are not transactional today, so this is
 * functionally identical to standard dispatch — but the after-commit
 * semantics future-proof against a Plan 05+ refactor that wraps the run.
 */
final class AgentRunStarted extends DomainEvent
{
    public function __construct(public readonly AgentRun $run)
    {
        parent::__construct();
    }
}
