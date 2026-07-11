<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Marketing;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 15 Plan 15b-01 — propose_marketing_action (advice-only AdOptimisationAgent).
 *
 * Structured-contract output sink, NOT a writer (mirrors Phase 10
 * ProposeMarginBandTool per CONTEXT D-06). The using() callable is a no-op
 * that returns `{"acknowledged":true}`. AdOptimisationResultMapper (Task 5)
 * extracts every propose_marketing_action call from `agent_run.tool_calls[]`
 * post-loop and materialises them into ONE bundled Suggestion of kind
 * `ad_optimisation`.
 *
 * ADVICE-ONLY: the recorded proposal has NO external side effect. Approving
 * the resulting Suggestion is acknowledgement only — no Google Ads write, no
 * ApplySuggestionJob apply path (all closed-loop actioning is deferred to
 * 15c).
 *
 * The 5 strongly-typed parameters ARE the contract surface the LLM structures
 * its recommendation against:
 *   - action_type (enum) — the kind of recommendation. The enum is validated
 *     at the Prism schema level; the model cannot emit an out-of-set value.
 *   - target       — channel / campaign / SKU / free-text the action applies to
 *   - rationale    — evidence-backed justification citing the read_* tools
 *   - supporting_metrics — the concrete numbers behind the call (string/JSON)
 *   - confidence (enum low|medium|high)
 */
final class ProposeMarketingActionTool extends Tool
{
    /** The five sanctioned advisory action types (Prism enum schema). */
    public const ACTION_TYPES = [
        'shift_budget',
        'increase_investment',
        'reduce_spend',
        'pause_target',
        'add_coverage',
    ];

    /** Confidence buckets (Prism enum schema). */
    public const CONFIDENCE_LEVELS = ['low', 'medium', 'high'];

    public function name(): string
    {
        return 'propose_marketing_action';
    }

    public function description(): string
    {
        return 'Record ONE concrete, evidence-backed marketing recommendation after analysing the read_* tools. Advice-only — the system records your call and a human reviews it; nothing is actioned automatically. Call once per distinct recommendation, then respond with one short sentence and stop.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withEnumParameter('action_type', 'The recommendation type', self::ACTION_TYPES)
            ->withStringParameter('target', 'What the action applies to — a channel group, campaign name, SKU, or short free-text scope')
            ->withStringParameter('rationale', 'Why — cite specific figures from the read_* tools (≥20 chars)')
            ->withStringParameter('supporting_metrics', 'The concrete numbers behind this call as a short string or JSON (e.g. sessions, transactions, revenue, margin, competitor position)')
            ->withEnumParameter('confidence', 'Your confidence in this recommendation', self::CONFIDENCE_LEVELS)
            ->using(fn (...$args): string => json_encode(['acknowledged' => true], JSON_THROW_ON_ERROR));
    }
}
