<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Products\Models\Product;
use Illuminate\Support\Str;

/**
 * Phase 6 Plan 01 — ProductSlugGenerator (D-05, AUTO-09).
 *
 * Deterministic slug uniqueness:
 *   1. base  = Str::slug($title)
 *   2. if Product::where('slug', $base)->exists() → base + '-' + strtolower($sku)
 *   3. if still collides → base + '-' + $productId  (or Str::random(6) when
 *      $productId is null — factory/fixture scenarios pre-insert)
 *
 * Woo-side slug reconciliation (Pitfall P6-G) is owned by CreateWooProductJob
 * Plan 06-03, which persists the final $response['slug'] from the Woo REST
 * POST back onto Product.slug. This service produces the BEST CANDIDATE slug
 * for the POST body; Woo may still disambiguate further.
 */
final class ProductSlugGenerator
{
    public function generate(string $title, string $sku, ?int $productId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            // Extreme fallback when title stringifies to empty (no Latin chars).
            $base = 'product-'.strtolower(trim($sku));
        }

        if (! $this->slugExists($base, $productId)) {
            return $base;
        }

        $candidate = $base.'-'.strtolower(trim($sku));
        if (! $this->slugExists($candidate, $productId)) {
            return $candidate;
        }

        $suffix = $productId !== null ? (string) $productId : Str::random(6);

        return $base.'-'.$suffix;
    }

    /**
     * Exists-check that IGNORES the passed-in product's own id — so
     * regenerating the slug for product 42 doesn't trip on its own existing row.
     */
    private function slugExists(string $slug, ?int $excludeProductId): bool
    {
        $q = Product::query()->where('slug', $slug);
        if ($excludeProductId !== null) {
            $q->whereKeyNot($excludeProductId);
        }

        return $q->exists();
    }
}
