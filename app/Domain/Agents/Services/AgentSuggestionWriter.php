<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Agents\ValueObjects\SuggestionDraft;
use App\Domain\Suggestions\Models\Suggestion;

/**
 * Phase 8 Plan 03 — sole DB-write seam from Agents → Suggestions (AGNT-12 + AGNT-13).
 *
 * Plan 04 RunAgentJob iterates the AgentResult.suggestionDrafts collection
 * and calls write() per draft. This is the ONLY non-AgentRun writer in the
 * Agents domain — every other write path is forbidden by
 * AgentsWriteOnlyViaSuggestionsTest.
 *
 * Three load-bearing behaviours:
 *
 *   1. Provenance morph activation (Claude's Discretion in CONTEXT) —
 *      sets proposed_by_type=AgentRun::class + proposed_by_id={run.id}.
 *      Filament SuggestionResource shows "Proposed by: Agent {kind} run
 *      {ulid-prefix}" by checking $record->proposedBy instanceof AgentRun.
 *      Distinguishes agent-reasoned suggestions from rule-based ones (Phase 5
 *      MarginAnalyser sets proposed_by_type=null).
 *
 *   2. Shadow-mode flag (AGNT-12) — when AGENT_WRITE_ENABLED=false
 *      (config('agents.write_enabled')), writes status='shadow' instead of
 *      'pending'. SuggestionResource list view filters shadow rows out so
 *      ops doesn't get suggestion-flooded during agent rollout. Once an
 *      operator flips the env var, subsequent writes land as 'pending'.
 *
 *   3. Correlation thread — copies AgentRun.triggering_correlation_id onto
 *      Suggestion.correlation_id so log queries can still trace a v1
 *      suggestion → triggering integration_event chain through the agent
 *      hop. integration_events.correlation_id is the join key.
 *
 * Schema verification (W6 plan-checker iter 1): suggestions table v1 baseline
 * (database/migrations/2026_04_18_180100_create_suggestions_table.php) ships
 * all required columns — correlation_id, payload, evidence, proposed_at,
 * proposed_by_type, proposed_by_id. NO additive migration shipped from Plan 03.
 *
 * Architecture note: AgentsWriteOnlyViaSuggestionsTest's grep matches
 * `::create(` and would flag `Suggestion::create(...)` here. The test's
 * Finder is updated in Task 3 to ->notPath('Services/AgentSuggestionWriter.php')
 * so the sanctioned write path is exempt; every other Agents-domain file
 * stays gated.
 */
final class AgentSuggestionWriter
{
    public function write(SuggestionDraft $draft, AgentRun $run): Suggestion
    {
        $writeEnabled = (bool) config('agents.write_enabled', false);
        $status = $writeEnabled ? Suggestion::STATUS_PENDING : 'shadow';

        return Suggestion::create([
            'kind' => $draft->kind,
            'status' => $status,
            'correlation_id' => $run->triggering_correlation_id ?? '',
            'payload' => $draft->payload,
            'evidence' => $draft->evidence,
            'proposed_by_type' => AgentRun::class,
            'proposed_by_id' => $run->id,
            'proposed_at' => now(),
        ]);
    }
}
