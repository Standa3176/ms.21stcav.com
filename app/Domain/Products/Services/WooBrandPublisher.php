<?php

declare(strict_types=1);

namespace App\Domain\Products\Services;

use App\Domain\ProductAutoCreate\Services\ProductBrandTermResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * 260709-gj2 — assigns a product's brand as the product_brand taxonomy term on its
 * live Woo product (the storefront Brand link) + bumps local woo_brand_count so the
 * Woo Maintenance missing-brand gap clears. Brand-only (no price/tag side-effects,
 * unlike Resync). Returns 'no_term' when the brand isn't in the product_brand
 * taxonomy yet (needs creating first — reported, not auto-created here).
 */
final class WooBrandPublisher
{
    public function __construct(private readonly ProductBrandTermResolver $brands) {}

    /** @return 'published'|'no_term'|'skipped' */
    public function publish(Product $product, ?string $brandName): string
    {
        $brandName = trim((string) $brandName);
        $wooId = (int) ($product->woo_product_id ?? 0);
        if ($wooId <= 0 || $brandName === '') {
            return 'skipped';
        }

        $termId = $this->brands->getTermIdForName($brandName);
        if ($termId === null) {
            Log::info('WooBrandPublisher: no product_brand term for brand', [
                'product_id' => $product->id, 'brand' => $brandName,
            ]);

            return 'no_term';
        }

        if (! $this->brands->assignToProduct($wooId, [$termId])) {
            return 'skipped';
        }

        $product->forceFill(['woo_brand_count' => 1])->saveQuietly();

        Log::info('WooBrandPublisher: published product_brand to Woo', [
            'product_id' => $product->id, 'woo_product_id' => $wooId, 'brand' => $brandName, 'term_id' => $termId,
        ]);

        return 'published';
    }
}
