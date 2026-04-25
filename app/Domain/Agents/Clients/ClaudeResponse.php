<?php

declare(strict_types=1);

namespace App\Domain\Agents\Clients;

use App\Domain\Agents\Enums\FinishReason;

/**
 * Phase 8 Plan 02 — value object capturing the slice of a Prism\Prism\Text\Response
 * that the Agents framework cares about for forensics + cost accounting.
 *
 * Why exist at all: Prism's Response is vendor-shaped (FinishReason enum cases like
 * `Stop`, `ToolCalls`, `Length`, plus seven other less-relevant cases). Our domain
 * speaks the D-06 schema (`end_turn`, `tool_use`, `max_tokens`, `stop_sequence`,
 * `error`). ClaudeResponse is the translation layer + the only object the rest of
 * the Agents domain (RunAgentJob, AgentSuggestionWriter, GuardrailEngine) ever sees.
 *
 * Read-only by construction so downstream code can't accidentally mutate token
 * counts or finish-reason after the fact.
 *
 * Fields snapshot the cost-accounting + forensics minimum:
 *   - text                — the agent's final reply (truncated upstream by AgentRun.agent_reasoning_summary 8KB cap)
 *   - finishReason        — local FinishReason enum (D-06 mirror)
 *   - promptTokens        — Prism Usage.promptTokens summed across all steps
 *   - completionTokens    — Prism Usage.completionTokens summed across all steps
 *   - costPence           — CostCalculator output (post-flight, config-driven rates)
 *   - langfuseTraceId     — populated by mliviu79 shim via Laravel Context (Q2 RESOLVED);
 *                           null when shim absent or test-mode bypasses HTTP layer
 *   - toolCalls / steps   — for tool_calls JSON column on AgentRun (5y forensics, D-07)
 *   - responseMessages    — multi-turn audit (Prism Response.messages collection)
 */
final readonly class ClaudeResponse
{
    /**
     * @param  array<int, mixed>  $toolCalls       Prism ToolCall[] objects from final step
     * @param  array<int, mixed>|\Illuminate\Support\Collection<int, mixed>  $steps           Prism Step collection (each has its own usage + tool calls)
     * @param  array<int, mixed>|\Illuminate\Support\Collection<int, mixed>  $responseMessages Multi-turn message log (UserMessage + AssistantMessage + ToolResultMessage)
     */
    public function __construct(
        public string $text,
        public FinishReason $finishReason,
        public int $promptTokens,
        public int $completionTokens,
        public int $costPence,
        public ?string $langfuseTraceId,
        public array|\Illuminate\Support\Collection $toolCalls,
        public array|\Illuminate\Support\Collection $steps,
        public array|\Illuminate\Support\Collection $responseMessages,
    ) {}

    /**
     * Map Prism's FinishReason enum case-name into our local FinishReason.
     *
     * Prism v0.100.1 ships seven cases: Stop, Length, ContentFilter, ToolCalls,
     * Error, Other, Unknown. Local D-06 schema has five: EndTurn, ToolUse,
     * MaxTokens, StopSequence, Error. The mapping is total — every Prism case
     * lands somewhere; unknown vendor strings (defensively) fall through to
     * `Error` so a future Prism version that adds a new case never produces an
     * uncaught match exception in production.
     *
     * Note: Prism does not currently emit `StopSequence` distinct from `Stop`,
     * so the local StopSequence case is reserved for future use (no Prism arm).
     */
    public static function mapFinishReason(string $prismCaseName): FinishReason
    {
        return match ($prismCaseName) {
            'Stop' => FinishReason::EndTurn,
            'ToolCalls' => FinishReason::ToolUse,
            'Length' => FinishReason::MaxTokens,
            'ContentFilter', 'Error', 'Other', 'Unknown' => FinishReason::Error,
            default => FinishReason::Error,
        };
    }
}
