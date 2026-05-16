<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Seo;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 12 Plan 01 — SEOAGT-02 propose_content_patch tool (NO-OP WRITER).
 *
 * Per CONTEXT D-03 + RESEARCH §Tool 4: structured-contract output sink,
 * invoked 1-4 times per agent run (one per field the agent thinks needs
 * patching — title / short_description / long_description / meta_description).
 *
 * Mirrors Phase 10's ProposeMarginBandTool no-op pattern verbatim — Plan
 * 12-04's SeoAgentResultMapper extracts ALL propose_content_patch calls
 * from `agent_run.tool_calls[]` post-loop, deduplicates by `field`
 * (last-wins per-field), and bundles every distinct field's last call
 * into ONE Suggestion of kind `seo_content_patch`.
 *
 * Why no-op (Phase 8 AgentsWriteOnlyViaSuggestionsTest invariant):
 *   - Direct DB writes from app/Domain/Agents/Tools/** are forbidden by
 *     the architecture suite. Mapper-as-writer keeps the persistence
 *     side-effect testable independent of the LLM call.
 *   - Agent may call propose_content_patch multiple times per field
 *     during reasoning; per-field dedup happens in the mapper.
 *
 * Plan 12-01 ships this body VERBATIM — Plan 12-02 does NOT touch it
 * (real "implementation" lives entirely in the Plan 12-04 mapper).
 *
 * KEY MAPPING NOTE (RESEARCH critical correction): the user-facing `field`
 * value `'title'` maps to `Product.name` column at the applier layer.
 * The Product model has NO `title` column. Plan 12-04 SeoContentPatchApplier
 * carries the title→name translation.
 *
 * Why string `field` not enum: Prism v0.100.1's withStringParameter is
 * the documented surface; enum-typed tool params were added in v0.110+.
 * Field validation against the 4 allowed names happens in
 * SeoAgentResultMapper (Plan 12-04). Defence in depth.
 */
final class ProposeContentPatchTool extends Tool
{
    public function name(): string
    {
        return 'propose_content_patch';
    }

    public function description(): string
    {
        return 'Propose a content patch for ONE field on the product draft. Call this 1-4 times per product (once per field you want to patch — title, short_description, long_description, or meta_description). After your final propose_content_patch call, respond with a brief acknowledgement and stop.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'Exact SKU string from the input')
            ->withStringParameter('field', 'One of: title, short_description, long_description, meta_description')
            ->withStringParameter('before', 'The CURRENT value of the field (copy verbatim from read_product_draft)')
            ->withStringParameter('after', 'The PROPOSED new value')
            ->withStringParameter('reasoning', 'Brief justification citing brand voice rules and/or similar products (≥20 chars)')
            ->using(fn (...$args): string => json_encode(['acknowledged' => true], JSON_THROW_ON_ERROR));
    }
}
