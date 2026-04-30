<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Pricing;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 10 Plan 01 — STUB for PRCAGT-02 propose_margin_band tool.
 *
 * Per CONTEXT D-06: this is a structured-contract output sink, NOT a writer.
 * The using() callable is intentionally a no-op writer that returns
 * `'{"acknowledged":true}'`. Plan 10-04's PricingAgentResultMapper extracts
 * the FINAL invocation's args from `agent_run.tool_calls[]` post-loop and
 * merges into `Suggestion.evidence.agent_proposed_band_*` /
 * `Suggestion.evidence.agent_reasoning` /
 * `Suggestion.evidence.agent_confidence_0_to_100`.
 *
 * Why no-op:
 *   - AgentsWriteOnlyViaSuggestionsTest (Phase 8 Plan 01) forbids any direct
 *     DB write from `app/Domain/Agents/Tools/**`. Mapper-as-writer keeps the
 *     persistence side-effect testable independent of the LLM call.
 *   - Agent may call `propose_margin_band` multiple times during reasoning;
 *     only the final call wins (logged history retained on agent_run).
 *
 * Plan 10-01 ships the no-op acknowledgement body verbatim — Plan 10-02
 * does NOT need to touch this tool (real impl lives entirely in the
 * mapper). The 6 strongly-typed parameters (sku, proposed_bps, reasoning,
 * confidence_0_to_100, band_min_bps, band_max_bps) ARE part of the contract
 * surface and ship in this plan so the LLM gets the schema it needs to
 * structure its proposal.
 */
final class ProposeMarginBandTool extends Tool
{
    public function name(): string
    {
        return 'propose_margin_band';
    }

    public function description(): string
    {
        return 'Propose a margin band for the SKU after analysing the read_* tools. Call this exactly once with your final proposal. The system records your call; you do not need to act on the response. After calling, respond with one short sentence and stop.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'Exact SKU string from the input')
            ->withNumberParameter('proposed_bps', 'Your central margin estimate in basis points (integer)')
            ->withStringParameter('reasoning', 'Why this band — cite specific tool outputs (≥40 chars)')
            ->withNumberParameter('confidence_0_to_100', 'Per the rubric — LOW 0-30 / MODERATE 31-70 / HIGH 71-100')
            ->withNumberParameter('band_min_bps', 'Minimum of your confidence band (≤ proposed_bps)')
            ->withNumberParameter('band_max_bps', 'Maximum of your confidence band (≥ proposed_bps)')
            ->using(fn (...$args): string => json_encode(['acknowledged' => true], JSON_THROW_ON_ERROR));
    }
}
