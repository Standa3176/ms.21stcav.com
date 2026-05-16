<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Models\AgentRun;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;

/**
 * Phase 12 Plan 04 — variable-cardinality bundled-Suggestion writer (RESEARCH §Pattern 2).
 *
 * Architectural exemption: this is the FIFTH sanctioned writer in app/Domain/Agents/
 * (alongside Models/AgentRun.php, Services/AgentSuggestionWriter.php,
 * Services/AgentRunGdprScrubber.php, Services/PricingAgentResultMapper.php,
 * Jobs/RunAgentJob.php, Jobs/RunPricingAgentJob.php, Jobs/RunSeoAgentJob.php,
 * Console/Commands/AgentsPruneArchiveCommand.php).
 * Tests/Architecture/AgentsWriteOnlyViaSuggestionsTest exempts this file via Finder->notPath().
 *
 * Why mapper-as-writer (mirrors Phase 10 D-06):
 *   - ProposeContentPatchTool is a no-op writer (returns {acknowledged:true})
 *   - Mapper-as-writer keeps persistence side-effects testable independently
 *     of the LLM round-trip
 *   - Multiple propose_content_patch invocations during reasoning — last
 *     call wins per-field (P12-A: UNCONDITIONAL $patchesByField[$field] =
 *     assignment, no isset guard).
 *
 * Two write paths:
 *
 *   createBundledSuggestion($run, $product) — happy path. Extracts every
 *   propose_content_patch from $run->tool_calls[], deduplicates per-field
 *   with LAST-WINS semantics, caps before/after at 4096 chars + reasoning
 *   at 1024 chars, skips invalid field names + no-op patches. Writes ONE
 *   Suggestion of kind 'seo_content_patch' with payload.patches[] (1-4
 *   entries). Returns null if zero valid patches; AgentRun.agent_reasoning_summary
 *   is annotated with "[mapper: no_patches]" via markNoPatchesState() so
 *   admin can see why no Suggestion was created.
 *
 *   createGuardrailBlockedSuggestion($run, $product, $failedPatternKey,
 *   $matchedExcerpt) — P12-B mitigation path. Called by RunSeoAgentJob's
 *   catch(GuardrailViolationException) BEFORE rethrowing. Writes ONE
 *   Suggestion of kind 'agent_guardrail_blocked' carrying the pattern key
 *   + excerpt for audit forensics. The kind is NOT registered with
 *   SuggestionApplierResolver — admin cannot approve it; Plan 12-05 filters
 *   it out of the default Filament Suggestions list.
 *
 * Trust-boundary defence (P12-A LAST-WINS):
 *   The per-field assignment is UNCONDITIONAL — NO presence guard before
 *   overwriting. A "first-wins" guard would let an early forbidden patch
 *   escape detection because a later on-brand patch never overwrites it.
 *   The SeoAgentResultMapperTest P12-A fixture and the plan's grep gate
 *   both fence against accidental regression here.
 */
final class SeoAgentResultMapper
{
    /** SEOAGT-02 — only these 4 fields are valid SEO targets. */
    public const VALID_FIELDS = ['title', 'short_description', 'long_description', 'meta_description'];

    /** Hard cap on stored patch text (matches AgentRun.tool_calls 4KB-per-field cap). */
    private const FIELD_TEXT_CAP_CHARS = 4096;

    /** Hard cap on reasoning text (1KB — enough for ≥150 words of justification). */
    private const REASONING_CAP_CHARS = 1024;

    /** Cap on matched_excerpt — bounds audit-row size for guardrail-blocked Suggestions. */
    private const EXCERPT_CAP_CHARS = 500;

    /**
     * Happy path — bundle all propose_content_patch calls into ONE Suggestion.
     * Returns null when no valid patches were proposed.
     */
    public function createBundledSuggestion(AgentRun $run, Product $product): ?Suggestion
    {
        $toolCalls = (array) ($run->tool_calls ?? []);

        $proposeCalls = array_values(array_filter(
            $toolCalls,
            fn ($call) => is_array($call) && (($call['tool_name'] ?? '') === 'propose_content_patch'),
        ));

        if ($proposeCalls === []) {
            $this->markNoPatchesState($run);

            return null;
        }

        // P12-A LAST-WINS — UNCONDITIONAL assignment. NO presence-guard before
        // overwriting; second call for the same field overwrites the first.
        // If a future PR adds a "first-wins" guard here, the
        // SeoAgentResultMapperTest P12-A fixture fails first.
        $patchesByField = [];
        foreach ($proposeCalls as $call) {
            $args = $this->decodeArgs($call['inputs'] ?? null);
            $field = (string) ($args['field'] ?? '');

            if (! in_array($field, self::VALID_FIELDS, true)) {
                continue;
            }

            $before = (string) ($args['before'] ?? '');
            $after = (string) ($args['after'] ?? '');
            $reasoning = (string) ($args['reasoning'] ?? '');

            // No-op patch — agent proposed the same text it read. Drop silently.
            if ($before === $after) {
                continue;
            }

            $patchesByField[$field] = [
                'field' => $field,
                'before' => mb_substr($before, 0, self::FIELD_TEXT_CAP_CHARS),
                'after' => mb_substr($after, 0, self::FIELD_TEXT_CAP_CHARS),
                'reasoning' => mb_substr($reasoning, 0, self::REASONING_CAP_CHARS),
                'applied_at' => null,
            ];
        }

        if ($patchesByField === []) {
            $this->markNoPatchesState($run);

            return null;
        }

        return Suggestion::create([
            'kind' => 'seo_content_patch',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => (string) ($run->triggering_correlation_id ?? ''),
            'payload' => [
                'product_id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'patches' => array_values($patchesByField),
                'agent_run_id' => (string) $run->id,
            ],
            'evidence' => [
                'agent_kind' => 'seo',
                'completeness_score_at_run' => (int) ($product->completeness_score ?? 0),
                'cost_pence' => (int) ($run->cost_pence ?? 0),
            ],
            'proposed_by_type' => AgentRun::class,
            'proposed_by_id' => $run->id,
            'proposed_at' => now(),
        ]);
    }

    /**
     * P12-B mitigation — write an audit Suggestion when SeoOutboundGuardrail
     * short-circuits a run. Called by RunSeoAgentJob's catch block BEFORE
     * rethrowing GuardrailViolationException. No applier is registered for
     * kind 'agent_guardrail_blocked' — admin cannot approve.
     */
    public function createGuardrailBlockedSuggestion(
        AgentRun $run,
        Product $product,
        string $failedPatternKey,
        string $matchedExcerpt,
    ): Suggestion {
        return Suggestion::create([
            'kind' => 'agent_guardrail_blocked',
            'status' => Suggestion::STATUS_PENDING,
            'correlation_id' => (string) ($run->triggering_correlation_id ?? ''),
            'payload' => [
                'product_id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'agent_kind' => 'seo',
                'failed_pattern_key' => $failedPatternKey,
                'matched_excerpt' => mb_substr($matchedExcerpt, 0, self::EXCERPT_CAP_CHARS),
            ],
            'evidence' => [
                'agent_run_id' => (string) $run->id,
            ],
            'proposed_by_type' => AgentRun::class,
            'proposed_by_id' => $run->id,
            'proposed_at' => now(),
        ]);
    }

    /**
     * Append "[mapper: no_patches]" to AgentRun.agent_reasoning_summary so
     * admin browsing the AgentRunResource detail view can see why no
     * Suggestion was created. Direct update on the framework's own AgentRun
     * row is permitted under the architecture exception (this file is
     * exempted from AgentsWriteOnlyViaSuggestionsTest).
     */
    private function markNoPatchesState(AgentRun $run): void
    {
        $existing = (string) ($run->agent_reasoning_summary ?? '');
        $run->update([
            'agent_reasoning_summary' => trim($existing . "\n\n[mapper: no_patches]"),
        ]);
    }

    /**
     * Phase 8 RunAgentJob normalises tool inputs to a JSON string (see
     * extractToolCallsFromSteps). Tolerate already-array form defensively
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
