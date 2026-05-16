<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Seo;

use App\Domain\Agents\Services\Tools\Tool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 12 Plan 01 — STUB for SEOAGT-02 read_product_draft tool.
 *
 * Per RESEARCH §Tool 1: returns the current AutoCreate draft's text fields
 * (name / short_description / long_description / meta_description) plus
 * completeness metadata so the SeoAgent has the "current state" to patch
 * against during the Prism tool-loop.
 *
 * Plan 12-01 ships compile-time STUBS only — Plan 12-02 will replace the
 * `using()` callable body with real Product::query()->where('sku', ...)
 * lookup + 4096-char per-field cap (matches AgentRun.tool_calls output cap).
 *
 * No TruncatingTool extension — single Product row payload stays under
 * the 3 KB cap once per-field truncation is applied in Plan 12-02 (max
 * 16 KB worst-case which is reduced to ~12 KB after the per-field
 * mb_substr(4096) caps documented in RESEARCH).
 *
 * Stub-marker payload: `{"stub":true}` — Plan 12-02 contract test asserts
 * the body changes shape once the real implementation lands.
 */
final class ReadProductDraftTool extends Tool
{
    public function name(): string
    {
        return 'read_product_draft';
    }

    public function description(): string
    {
        return 'Read the current AutoCreate draft state (name, short_description, long_description, meta_description, completeness metadata) for the given SKU. Use ONCE per agent run as the baseline before proposing patches.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withStringParameter('sku', 'The SKU of the AutoCreate draft to read')
            ->using(fn (string $sku): string => json_encode(['stub' => true], JSON_THROW_ON_ERROR));
    }
}
