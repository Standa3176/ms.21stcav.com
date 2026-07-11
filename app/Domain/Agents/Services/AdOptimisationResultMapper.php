<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Suggestions\Models\Suggestion;

/**
 * Phase 15 Plan 15b-01 — bundled-Suggestion writer for the advice-only
 * AdOptimisationAgent (mirrors SeoAgentResultMapper's bundled shape).
 *
 * Architectural exemption: this is a sanctioned writer in app/Domain/Agents/
 * (alongside the Phase 10/12 mappers + RunAgentJob family).
 * Tests/Architecture/AgentsWriteOnlyViaSuggestionsTest exempts this file.
 *
 * Post-run, reads `AgentRun.tool_calls[]` where tool_name='propose_marketing_action'
 * and creates ONE Suggestion of kind `ad_optimisation` carrying every proposal
 * in payload.proposals[]. ADVICE-ONLY — the Suggestion is acknowledgement-only;
 * no SuggestionApplier is registered for kind 'ad_optimisation' and approving
 * it fires no apply path (all closed-loop actioning is 15c).
 *
 * Idempotent by agent_run_id: re-mapping the same AgentRun returns the existing
 * Suggestion rather than double-writing (keyed on payload.agent_run_id).
 * Returns null when the run proposed nothing.
 *
 * Respects the Suggestion NOT-NULL defaults (payload/correlation_id booted()
 * from 260708-gy0) by always supplying explicit values.
 */
final class AdOptimisationResultMapper
{
    /** Cap on rationale text stored per proposal. */
    private const RATIONALE_CAP_CHARS = 1024;

    /** Cap on supporting_metrics text stored per proposal. */
    private const METRICS_CAP_CHARS = 2048;

    /** The action_type values the agent may propose (mirrors the tool enum). */
    private const VALID_ACTION_TYPES = [
        'shift_budget',
        'increase_investment',
        'reduce_spend',
        'pause_target',
        'add_coverage',
    ];

    /**
     * Bundle every propose_marketing_action call into ONE ad_optimisation
     * Suggestion. Returns null when no valid proposals were made.
     */
    public function createBundledSuggestion(AgentRun $run): ?Suggestion
    {
        // Idempotency — don't double-write if this run already produced one.
        $existing = Suggestion::query()
            ->where('kind', 'ad_optimisation')
            ->where('payload->agent_run_id', (string) $run->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $toolCalls = (array) ($run->tool_calls ?? []);

        $proposeCalls = array_values(array_filter(
            $toolCalls,
            fn ($call) => is_array($call) && (($call['tool_name'] ?? '') === 'propose_marketing_action'),
        ));

        if ($proposeCalls === []) {
            $this->markNoProposalsState($run);

            return null;
        }

        $proposals = [];
        foreach ($proposeCalls as $call) {
            $args = $this->decodeArgs($call['inputs'] ?? null);

            $actionType = (string) ($args['action_type'] ?? '');
            if (! in_array($actionType, self::VALID_ACTION_TYPES, true)) {
                continue;
            }

            $target = trim((string) ($args['target'] ?? ''));
            $rationale = trim((string) ($args['rationale'] ?? ''));
            if ($target === '' || $rationale === '') {
                continue;
            }

            $confidence = (string) ($args['confidence'] ?? '');
            $supportingMetrics = $args['supporting_metrics'] ?? '';
            if (is_array($supportingMetrics)) {
                $supportingMetrics = (string) json_encode($supportingMetrics);
            }

            $proposals[] = [
                'action_type' => $actionType,
                'target' => mb_substr($target, 0, 500),
                'rationale' => mb_substr($rationale, 0, self::RATIONALE_CAP_CHARS),
                'supporting_metrics' => mb_substr((string) $supportingMetrics, 0, self::METRICS_CAP_CHARS),
                'confidence' => in_array($confidence, ['low', 'medium', 'high'], true) ? $confidence : 'low',
            ];
        }

        if ($proposals === []) {
            $this->markNoProposalsState($run);

            return null;
        }

        return Suggestion::create([
            'kind' => 'ad_optimisation',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => (string) ($run->triggering_correlation_id ?? ''),
            'payload' => [
                'proposals' => $proposals,
                'agent_reasoning' => mb_substr((string) ($run->agent_reasoning_summary ?? ''), 0, self::RATIONALE_CAP_CHARS),
                'agent_run_id' => (string) $run->id,
                'correlation_id' => (string) ($run->triggering_correlation_id ?? ''),
            ],
            'evidence' => [
                'agent_kind' => 'ad_optimisation',
                'supporting_metrics' => array_map(
                    fn (array $p): string => $p['supporting_metrics'],
                    $proposals,
                ),
                'cost_pence' => (int) ($run->cost_pence ?? 0),
            ],
            'proposed_by_type' => AgentRun::class,
            'proposed_by_id' => $run->id,
            'proposed_at' => now(),
        ]);
    }

    /**
     * Annotate AgentRun.agent_reasoning_summary so the AgentRunResource detail
     * view shows why no Suggestion was created (mirrors SeoAgentResultMapper).
     */
    private function markNoProposalsState(AgentRun $run): void
    {
        $existing = (string) ($run->agent_reasoning_summary ?? '');
        $run->update([
            'agent_reasoning_summary' => trim($existing."\n\n[mapper: no_proposals]"),
        ]);
    }

    /**
     * RunAgentJob normalises tool inputs to a JSON string. Tolerate already-array
     * form defensively.
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
