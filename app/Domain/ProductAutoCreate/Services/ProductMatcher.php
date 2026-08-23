<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductSupplierSku;

/**
 * Phase 6 Plan 01 — ProductMatcher (AUTO-08 v1 + D-05 slug-collision gate).
 *
 * v1 scope: casing + trailing-whitespace normalisation only. Fuzzy MPN matching
 * is deferred (Phase 5 research C.2 + CONTEXT §Deferred).
 *
 *   existsNormalised($sku)           → LOWER(TRIM(sku)) = LOWER(TRIM(candidate))
 *   existsCaseInsensitiveSlug($slug) → LOWER(TRIM(slug)) = LOWER(TRIM(candidate))
 *     (used by ProductSlugGenerator + CompletenessScorer for slug uniqueness)
 *
 * MySQL `LOWER(TRIM(...))` index-miss note: the whereRaw runs a full-table
 * scan on the products.sku index (single-column BTREE). For <100k rows this
 * is fine; for 1M+ rows Plan 06-03 can add a functional index on
 * `(LOWER(TRIM(sku)))` in a follow-up migration. v1 doesn't need this.
 */
final class ProductMatcher
{
    public function existsNormalised(string $sku): bool
    {
        $needle = strtolower(trim($sku));

        $onProducts = Product::query()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$needle])
            ->exists();

        if ($onProducts) {
            return true;
        }

        // Quick task 260823-clp — an ALTERNATIVE supplier code counts as
        // "already stocked". This is the AUTO-08 gate immediately before a Woo
        // POST, so it is the last line of defence against the duplicate class
        // the 2026-08-09 TODO describes: supplier B lists the same physical
        // part under its own code, nothing links it, and the pipeline creates
        // a second Woo product for a part already on the storefront.
        return ProductSupplierSku::query()
            ->where('normalised_sku', ProductSupplierSku::normalise($sku))
            ->exists();
    }

    public function existsCaseInsensitiveSlug(string $slug, ?int $excludeProductId = null): bool
    {
        $needle = strtolower(trim($slug));
        $q = Product::query()
            ->whereRaw('LOWER(TRIM(slug)) = ?', [$needle]);

        if ($excludeProductId !== null) {
            $q->whereKeyNot($excludeProductId);
        }

        return $q->exists();
    }
}
