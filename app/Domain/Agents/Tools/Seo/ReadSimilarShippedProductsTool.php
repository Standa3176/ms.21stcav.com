<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Seo;

use App\Domain\Agents\Tools\TruncatingTool;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 12 Plan 01 — STUB for SEOAGT-02 read_similar_shipped_products tool.
 *
 * Per RESEARCH §Tool 3: finds recently-shipped products in the same category
 * to serve as voice/structure examples for the agent's patch proposals.
 *
 * Plan 12-01 ships compile-time STUBS only — Plan 12-02 will replace the
 * `using()` body with Product::query()->where('status', 'publish')
 *   ->where(completeness_score >= 85 OR NULL) — Option B per RESEARCH (covers
 * Phase 2-synced manual products AND AutoCreate-published rows).
 *
 * Extends TruncatingTool (the SHARED Tools/ parent, NOT the deprecated
 * Tools/Pricing/ one) because the response includes 5 products' full text
 * fields which can exceed 3 KB. Plan 12-02 will implement reduceLargestArray
 * to halve the per-product long_description_first_500_chars on cap pressure.
 * For the stub, an empty reducer returning the payload as-is satisfies the
 * abstract contract until Plan 12-02 ships the real body.
 *
 * Stub-marker payload: `{"stub":true}` — Plan 12-02 contract test asserts
 * the body changes shape.
 */
final class ReadSimilarShippedProductsTool extends TruncatingTool
{
    public function name(): string
    {
        return 'read_similar_shipped_products';
    }

    public function description(): string
    {
        return 'Find recently-shipped products in the same category as voice/structure examples. Returns up to `limit` products (default 5) with short_description, long_description (first 500 chars), and meta_description. Use to anchor the patch voice to MeetingStore precedent.';
    }

    public function asPrismTool(): \Prism\Prism\Tool
    {
        return PrismToolFacade::as($this->name())
            ->for($this->description())
            ->withNumberParameter('category', 'Category ID — products in this category will be returned')
            ->withNumberParameter('limit', 'Max products to return (1–10; default 5)')
            ->using(fn (int $category, int $limit = 5): string => json_encode(['stub' => true], JSON_THROW_ON_ERROR));
    }

    /**
     * Stub reducer — Plan 12-02 ships the real per-product trim logic.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        return $payload;
    }
}
