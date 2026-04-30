<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Suggestions\Models\Suggestion;

/**
 * Phase 10 Plan 04 — sole writer of PricingAgent enrichment onto Suggestion.evidence.
 *
 * Architectural exemption: this is the FOURTH sanctioned writer in app/Domain/Agents/
 * (alongside Models/AgentRun.php, Services/AgentSuggestionWriter.php, Jobs/RunAgentJob.php,
 * Console/Commands/AgentsPruneArchiveCommand.php, Services/AgentRunGdprScrubber.php).
 * Tests/Architecture/AgentsWriteOnlyViaSuggestionsTest exempts this file via Finder->notPath().
 *
 * Why mapper-as-writer (not tool-as-writer) — see CONTEXT D-06:
 *   - ProposeMarginBandTool is a no-op writer (returns {acknowledged:true})
 *   - Mapper-as-writer keeps persistence side-effects testable independently
 *     of the LLM round-trip
 *   - Multiple propose_margin_band invocations during reasoning — only the
 *     LAST wins (D-06 + RESEARCH P10-C "first-vs-last bug" defence)
 *
 * Three terminal-state branches per CONTEXT D-11:
 *   - completed         — happy path; latest call's args overwrite enrichment fields
 *   - no_proposal       — withMaxSteps exhausted; PRESERVES prior enrichment so admin
 *                         still sees last successful agent reasoning
 *   - malformed_proposal— args fail validation (band_min > band_max OR reasoning < 40 chars);
 *                         PRESERVES prior enrichment for the same reason
 *
 * agent_run_ids[] is capped at 10 latest entries (RESEARCH P10-E) to prevent
 * unbounded JSON column growth across many re-runs of the same suggestion.
 */
final class PricingAgentResultMapper
{
    /** RESEARCH P10-E — bound on Suggestion.evidence.agent_run_ids[] growth. */
    public const RUN_IDS_CAP = 10;

    /** Hard ceiling on the persisted reasoning text (matches AgentRun.agent_reasoning_summary 8KB cap halved). */
    private const REASONING_MAX_CHARS = 4096;

    /**
     * Minimum reasoning length the system prompt asks the model for. Shorter
     * means the model didn't actually reason and we should flag malformed.
     */
    private const REASONING_MIN_CHARS = 40;

    public function mergeIntoSuggestion(AgentRun $run, Suggestion $suggestion): void
    {
        $toolCalls = (array) ($run->tool_calls ?? []);

        // Filter for propose_margin_band entries. Phase 8 RunAgentJob writes
        // tool_calls JSON in the shape {tool_name, inputs, outputs, tokens_used,
        // latency_ms} — see RunAgentJob::extractToolCallsFromSteps.
        $proposeCalls = array_values(array_filter(
            $toolCalls,
            fn ($call) => is_array($call) && (($call['tool_name'] ?? '') === 'propose_margin_band'),
        ));

        if ($proposeCalls === []) {
            $this->mergeNoProposalState($run, $suggestion);

            return;
        }

        // LAST call wins (D-06 + RESEARCH P10-C — never first; agent may iterate
        // proposals during reasoning and the final one is its actual answer).
        $last = end($proposeCalls);
        $args = $this->decodeArgs($last['inputs'] ?? null);

        $proposedBps = (int) ($args['proposed_bps'] ?? 0);
        $bandMin = (int) ($args['band_min_bps'] ?? 0);
        $bandMax = (int) ($args['band_max_bps'] ?? 0);
        $confidence = max(0, min(100, (int) ($args['confidence_0_to_100'] ?? 0)));
        $reasoning = (string) ($args['reasoning'] ?? '');

        if ($bandMin > $bandMax || $bandMin < 0 || $bandMax < 0 || strlen($reasoning) < self::REASONING_MIN_CHARS) {
            $this->mergeMalformedState($run, $suggestion);

            return;
        }

        $evidence = (array) $suggestion->evidence;

        // D-02 — append to agent_run_ids[]; cap at RUN_IDS_CAP latest entries (P10-E)
        $evidence['agent_run_ids'] = $this->appendCappedRunId($evidence, $run->id);

        // Latest run wins for displayed enrichment fields (D-02 idempotency)
        $evidence['agent_reasoning'] = mb_substr($reasoning, 0, self::REASONING_MAX_CHARS);
        $evidence['agent_confidence_0_to_100'] = $confidence;
        $evidence['agent_proposed_band_min_bps'] = $bandMin;
        $evidence['agent_proposed_band_max_bps'] = $bandMax;
        $evidence['agent_proposed_bps'] = $proposedBps;
        $evidence['agent_run_status'] = 'completed';
        $evidence['agent_run_completed_at'] = $run->completed_at?->toIso8601String();

        // Phase 1 D-14 morph activation — lets Filament render
        // "Proposed by: Agent {kind} run {ulid-prefix}" via $record->proposedBy.
        $suggestion->proposed_by_type = AgentRun::class;
        $suggestion->proposed_by_id = $run->id;
        $suggestion->evidence = $evidence;
        $suggestion->save();
    }

    public function mergeNoProposalState(AgentRun $run, Suggestion $suggestion): void
    {
        $evidence = (array) $suggestion->evidence;
        $evidence['agent_run_ids'] = $this->appendCappedRunId($evidence, $run->id);
        $evidence['agent_run_status'] = 'no_proposal';
        $evidence['agent_run_completed_at'] = $run->completed_at?->toIso8601String();
        // PRESERVE prior agent_reasoning / agent_confidence_0_to_100 / agent_proposed_band_*
        // so admin still sees the last successful enrichment + a "no proposal" chip from this run.
        $suggestion->evidence = $evidence;
        $suggestion->save();
    }

    public function mergeMalformedState(AgentRun $run, Suggestion $suggestion): void
    {
        $evidence = (array) $suggestion->evidence;
        $evidence['agent_run_ids'] = $this->appendCappedRunId($evidence, $run->id);
        $evidence['agent_run_status'] = 'malformed_proposal';
        $evidence['agent_run_completed_at'] = $run->completed_at?->toIso8601String();
        // Same preservation rationale as mergeNoProposalState.
        $suggestion->evidence = $evidence;
        $suggestion->save();
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, mixed>
     */
    private function appendCappedRunId(array $evidence, mixed $runId): array
    {
        $existing = (array) ($evidence['agent_run_ids'] ?? []);
        $existing[] = $runId;

        return array_values(array_slice($existing, -self::RUN_IDS_CAP));
    }

    /**
     * Phase 8 RunAgentJob normalises tool inputs to a JSON string (see
     * extractToolCallsFromSteps). Defensive: tolerate already-array form
     * in case a future Prism version returns native arrays.
     *
     * @return array<string, mixed>
     */
    private function decodeArgs(mixed $inputs): array
    {
        if (is_array($inputs)) {
            return $inputs;
        }

        if (! is_string($inputs) || $inputs === '') {
            return [];
        }

        $decoded = json_decode($inputs, true);

        return is_array($decoded) ? $decoded : [];
    }
}
