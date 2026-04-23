<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Products\Models\Product;

/**
 * Phase 6 Plan 01 — CompletenessScorer (D-07, D-08).
 *
 * score(Product $p): array returns
 *   ['score' => int, 'missing_fields' => array, 'ready_to_publish' => bool]
 *
 * Weight bands (D-07 — sum = 100):
 *   title               15  (present + not template default)
 *   slug                 5  (set + not duplicated, IGNORING own row)
 *   meta_description    10  (100-160 chars — D-07 band)
 *   short_description   10  (≥3 <li> bullets)
 *   long_description    15  (all 4 <h2> markers present)
 *   brand_id            10  (not null)
 *   category_id         10  (not null)
 *   image               20  (image_url set AND not placeholder URL)
 *   price                5  (sell_price > 0)
 *   ────────────────────────────────────────
 *   total              100
 *
 * Publish gate = score >= config('product_auto_create.completeness_publish_threshold', 85).
 */
final class CompletenessScorer
{
    public const WEIGHT_TITLE = 15;

    public const WEIGHT_SLUG = 5;

    public const WEIGHT_META_DESC = 10;

    public const WEIGHT_SHORT_DESC = 10;

    public const WEIGHT_LONG_DESC = 15;

    public const WEIGHT_BRAND = 10;

    public const WEIGHT_CATEGORY = 10;

    public const WEIGHT_IMAGE = 20;

    public const WEIGHT_PRICE = 5;

    public function __construct(
        private readonly ProductMatcher $matcher,
    ) {}

    /**
     * @return array{score: int, missing_fields: array<int, string>, ready_to_publish: bool}
     */
    public function score(Product $product): array
    {
        $score = 0;
        $missing = [];

        // Title — present + not a template default placeholder (D-07).
        $title = (string) ($product->name ?? '');
        if ($title !== '' && ! str_contains($title, '{{')) {
            $score += self::WEIGHT_TITLE;
        } else {
            $missing[] = 'title';
        }

        // Slug — set + unique (excluding this product's own id).
        $slug = (string) ($product->slug ?? '');
        if ($slug !== '' && ! $this->matcher->existsCaseInsensitiveSlug($slug, $product->id)) {
            $score += self::WEIGHT_SLUG;
        } else {
            $missing[] = 'slug';
        }

        // Meta description — present and length in 100..160 inclusive (D-07).
        $meta = (string) ($product->meta_description ?? '');
        $metaLen = mb_strlen($meta);
        if ($metaLen >= 100 && $metaLen <= 160) {
            $score += self::WEIGHT_META_DESC;
        } else {
            $missing[] = 'meta_description';
        }

        // Short description — at least 3 <li> bullets.
        $shortDesc = (string) ($product->short_description ?? '');
        if (substr_count($shortDesc, '<li') >= 3) {
            $score += self::WEIGHT_SHORT_DESC;
        } else {
            $missing[] = 'short_description';
        }

        // Long description — all 4 <h2> section markers present (D-01).
        $longDesc = (string) ($product->long_description ?? '');
        if (
            str_contains($longDesc, '<h2>Overview')
            && str_contains($longDesc, '<h2>Key Features')
            && str_contains($longDesc, '<h2>Technical Specifications')
            && str_contains($longDesc, "<h2>What's in the Box")
        ) {
            $score += self::WEIGHT_LONG_DESC;
        } else {
            $missing[] = 'long_description';
        }

        // Brand + category.
        if ($product->brand_id !== null) {
            $score += self::WEIGHT_BRAND;
        } else {
            $missing[] = 'brand_id';
        }
        if ($product->category_id !== null) {
            $score += self::WEIGHT_CATEGORY;
        } else {
            $missing[] = 'category_id';
        }

        // Image — set AND not pointing at the placeholder URL.
        $imageUrl = (string) ($product->image_url ?? '');
        $placeholderUrl = (string) config('product_auto_create.placeholder_image_url', '');
        if ($imageUrl !== '' && $imageUrl !== $placeholderUrl) {
            $score += self::WEIGHT_IMAGE;
        } else {
            $missing[] = 'image';
        }

        // Price — sell_price > 0.
        $sellPrice = (float) ($product->sell_price ?? 0);
        if ($sellPrice > 0) {
            $score += self::WEIGHT_PRICE;
        } else {
            $missing[] = 'price';
        }

        $threshold = (int) config('product_auto_create.completeness_publish_threshold', 85);

        return [
            'score' => $score,
            'missing_fields' => $missing,
            'ready_to_publish' => $score >= $threshold,
        ];
    }
}
