<?php

declare(strict_types=1);

namespace App\Domain\Agents\Tools\Seo;

use App\Domain\Agents\Tools\TruncatingTool;
use App\Domain\Products\Models\Product;
use Prism\Prism\Facades\Tool as PrismToolFacade;

/**
 * Phase 12 Plan 02 — SEOAGT-02 read_similar_shipped_products real implementation.
 *
 * Per RESEARCH §Tool 3: finds recently-shipped products in the same category
 * to serve as voice/structure examples for the SeoAgent's patch proposals.
 *
 * Eligibility query (Option B — RESEARCH §Tool 3 selected approach):
 *   - status='publish'
 *   - completeness_score >= 85 OR completeness_score IS NULL
 *     (the NULL clause covers the ~5000 Phase 2-synced manual products that
 *      pre-date AutoCreate — those are canonical MeetingStore voice examples)
 *   - whereNotNull('name') AND name != ''
 *
 * P12-G fallback: when the category filter returns zero rows, drops the
 * category filter and re-queries globally. The response carries
 * `_fallback: 'global'` so the agent knows the voice anchor is cross-category
 * (and can mention this in reasoning if a brand-specific Logitech term
 * appears in a global example from a different category).
 *
 * Cap logic (Phase 10 D-05 mirror):
 *   - Each product's long_description trimmed to first 500 chars via
 *     mb_substr.
 *   - short_description trimmed to 200 chars (typical < 300 in catalogue).
 *   - meta_description returned in full (≤ 160 chars by schema).
 *   - Overall response capped at 3072 bytes via TruncatingTool::capJson —
 *     reduceLargestArray halves the products array on cap pressure.
 *   - `_truncated:true` + `_total_available:N` hints appended when capped.
 *
 * Schema returned:
 * {
 *   "category_id": 12,
 *   "limit": 5,
 *   "products": [
 *     {
 *       "sku": "...",
 *       "name": "...",
 *       "short_description": "<≤ 200 chars>",
 *       "long_description_first_500_chars": "<≤ 500 chars>",
 *       "meta_description": "..."
 *     }
 *   ],
 *   "_fallback": "global" | absent,
 *   "_truncated": true | false,
 *   "_total_available": N
 * }
 */
final class ReadSimilarShippedProductsTool extends TruncatingTool
{
    private const SHORT_DESC_CAP = 200;

    private const LONG_DESC_CAP = 500;

    private const ELIGIBILITY_SCORE = 85;

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
            ->using(fn (int $category, int $limit = 5): string => $this->execute($category, $limit));
    }

    private function execute(int $categoryId, int $limit): string
    {
        $limit = max(1, min(10, $limit));

        // Primary query: products in the given category (Option B eligibility).
        $primary = $this->eligibilityBaseQuery()
            ->where('category_id', $categoryId)
            ->limit($limit)
            ->get();

        $fallbackUsed = false;
        $rows = $primary;

        if ($rows->isEmpty()) {
            // P12-G fallback: drop category filter, return global examples.
            $rows = $this->eligibilityBaseQuery()
                ->limit($limit)
                ->get();
            $fallbackUsed = true;
        }

        $totalAvailable = $this->eligibilityBaseQuery()
            ->when(! $fallbackUsed, fn ($q) => $q->where('category_id', $categoryId))
            ->count();

        $products = $rows->map(function (Product $p): array {
            return [
                'sku' => (string) $p->sku,
                'name' => mb_substr((string) $p->name, 0, 255),
                'short_description' => mb_substr((string) $p->short_description, 0, self::SHORT_DESC_CAP),
                'long_description_first_500_chars' => mb_substr((string) $p->long_description, 0, self::LONG_DESC_CAP),
                'meta_description' => (string) $p->meta_description,
            ];
        })->values()->all();

        $payload = [
            'category_id' => $categoryId,
            'limit' => $limit,
            'products' => $products,
        ];
        if ($fallbackUsed && $rows->isNotEmpty()) {
            $payload['_fallback'] = 'global';
        }

        return $this->capJson($payload, $totalAvailable);
    }

    /**
     * Option B eligibility query (RESEARCH §Tool 3). status='publish' AND
     * (completeness_score >= 85 OR NULL) — covers Phase 2-synced manual
     * products (NULL score) AND AutoCreate-published rows (score ≥ 85).
     */
    private function eligibilityBaseQuery()
    {
        return Product::query()
            ->where('status', 'publish')
            ->where(fn ($q) => $q
                ->where('completeness_score', '>=', self::ELIGIBILITY_SCORE)
                ->orWhereNull('completeness_score'))
            ->whereNotNull('name')
            ->where('name', '!=', '');
    }

    /**
     * Reduce the products array under cap pressure. Halves the count on
     * each invocation while preserving array shape so the agent gets fewer
     * but still complete examples (rather than truncated mid-field text).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function reduceLargestArray(array $payload, int $maxBytes): array
    {
        if (! isset($payload['products']) || ! is_array($payload['products'])) {
            return $payload;
        }
        $count = count($payload['products']);
        if ($count <= 1) {
            // Already at single product — last-resort: trim the long_description
            // to a smaller slice rather than dropping the row entirely.
            if ($count === 1 && isset($payload['products'][0]['long_description_first_500_chars'])) {
                $payload['products'][0]['long_description_first_500_chars'] = mb_substr(
                    (string) $payload['products'][0]['long_description_first_500_chars'],
                    0,
                    200,
                );
            }

            return $payload;
        }

        $newCount = max(1, (int) floor($count / 2));
        $payload['products'] = array_slice($payload['products'], 0, $newCount);

        return $payload;
    }
}
