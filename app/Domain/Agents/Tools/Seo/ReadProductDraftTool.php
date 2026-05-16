<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Seo;

use App\Domain\Agents\Services\Tools\Tool;
use App\Domain\Agents\Support\BrandSlugResolver;
use App\Domain\Products\Models\Product;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 12 Plan 02 — SEOAGT-02 read_product_draft real implementation.
 *
 * Returns the current AutoCreate draft's text fields (name / short_description
 * / long_description / meta_description) plus completeness metadata so the
 * SeoAgent has the "current state" to patch against during the Prism tool-loop.
 *
 * Per RESEARCH §Tool 1:
 *   - Each STRING field capped to 4096 chars via mb_substr (T-12-02-03 DoS
 *     mitigation — prevents oversized supplier descriptions polluting the
 *     AgentRun.tool_calls JSON column).
 *   - No 3-KB overall cap — Product has bounded schema so per-field caps are
 *     sufficient (~12 KB worst case after 4096 caps).
 *   - Unknown SKU returns `{error: 'not_found', sku: '...'}` — never throws
 *     (P12-G-style sparse-data graceful path).
 *
 * Brand slug resolution (P12-C mitigation precursor):
 *   - Delegates to BrandSlugResolver which reads brands.slug (NOT brand.name).
 *   - Returns null when product.brand_id is null.
 *   - Falls back to (string) brand_id when no brands row matches.
 *
 * Schema returned:
 * {
 *   "sku": "LOGI-MEETUP",
 *   "name": "Logitech MeetUp Conference Camera",
 *   "short_description": "All-in-one ConferenceCam...",
 *   "long_description": "<≤ 4096 chars>",
 *   "meta_description": "<≤ 4096 chars>",
 *   "brand_id": 5,
 *   "brand_slug": "logitech",
 *   "category_id": 12,
 *   "completeness_score": 64,
 *   "completeness_missing_fields": ["long_description", "meta_description"]
 * }
 */
final class ReadProductDraftTool extends Tool
{
    private const FIELD_CAP_CHARS = 4096;

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
            ->using(fn (string $sku): string => $this->execute($sku));
    }

    private function execute(string $sku): string
    {
        $product = Product::query()->where('sku', $sku)->first();

        if ($product === null) {
            return json_encode([
                'error' => 'not_found',
                'sku' => $sku,
            ], JSON_THROW_ON_ERROR);
        }

        $brandId = $product->brand_id === null ? null : (int) $product->brand_id;
        $brandSlug = $brandId === null
            ? null
            : BrandSlugResolver::forBrandId($brandId);

        $missing = $product->completeness_missing_fields;
        if (! is_array($missing)) {
            $missing = [];
        }

        $payload = [
            'sku' => $product->sku,
            'name' => mb_substr((string) $product->name, 0, self::FIELD_CAP_CHARS),
            'short_description' => mb_substr((string) $product->short_description, 0, self::FIELD_CAP_CHARS),
            'long_description' => mb_substr((string) $product->long_description, 0, self::FIELD_CAP_CHARS),
            'meta_description' => mb_substr((string) $product->meta_description, 0, self::FIELD_CAP_CHARS),
            'brand_id' => $brandId,
            'brand_slug' => $brandSlug,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'completeness_score' => $product->completeness_score === null ? null : (int) $product->completeness_score,
            'completeness_missing_fields' => $missing,
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
