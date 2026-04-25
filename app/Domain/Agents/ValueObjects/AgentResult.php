<?php

declare(strict_types=1);

namespace App\Domain\Agents\ValueObjects;

use App\Domain\Agents\Enums\FinishReason;

/**
 * Phase 8 Plan 03 — return value of `RunsAsAgent::execute()` (AGNT-01).
 *
 * Captures the D-06 forensic snapshot the framework persists onto AgentRun
 * + the queue of SuggestionDrafts ready for AgentSuggestionWriter to write
 * via the Suggestions seam.
 *
 * Readonly so once an agent returns, the framework can't mutate token counts
 * or finish reasons before BudgetGuard records spend or AgentRun snapshots.
 */
final readonly class AgentResult
{
    /**
     * @param  array<int, SuggestionDraft>  $suggestionDrafts
     * @param  array<int, array{tool_name: string, inputs: mixed, outputs: mixed, tokens_used: int, latency_ms: int}>  $toolCalls
     */
    public function __construct(
        public array $suggestionDrafts,
        public string $agentReasoning,
        public FinishReason $finishReason,
        public int $promptTokens,
        public int $completionTokens,
        public int $costPence,
        public ?string $langfuseTraceId,
        public array $toolCalls,
    ) {}
}
