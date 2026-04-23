<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Sync\Services\WooClient;

/**
 * Phase 6 Plan 03 — resolve supplier-supplied brand + category strings against
 * Woo's existing taxonomy via the REST API.
 *
 * Strategy:
 *   - Null / empty input → null (caller flags needs_brand_or_category_assignment).
 *   - Query Woo via WooClient::get() with `search` parameter.
 *   - Match on case-insensitive trimmed equality against returned term names.
 *   - Any Woo failure (network / 4xx) → null + swallow exception (WooClient
 *     already logs to integration_events so the operator sees the failure).
 *
 * Taxonomy slugs are configurable via config('product_auto_create.brand_taxonomy')
 * (default 'pa_brand') and 'category_taxonomy' (default 'product_cat'); categories
 * use Woo's dedicated endpoint `/products/categories` while brands use the generic
 * attribute terms endpoint `/products/attributes/{taxonomy}/terms`.
 */
final class TaxonomyResolver
{
    public function __construct(private WooClient $woo) {}

    public function resolveBrand(?string $brandName): ?int
    {
        if ($brandName === null || trim($brandName) === '') {
            return null;
        }

        $taxonomy = (string) config('product_auto_create.brand_taxonomy', 'pa_brand');

        try {
            $terms = $this->woo->get("/products/attributes/{$taxonomy}/terms", [
                'search' => $brandName,
                'per_page' => 10,
            ]);
        } catch (\Throwable) {
            return null;
        }

        return $this->matchTermId($terms, $brandName);
    }

    public function resolveCategory(?string $categoryName): ?int
    {
        if ($categoryName === null || trim($categoryName) === '') {
            return null;
        }

        try {
            $terms = $this->woo->get('/products/categories', [
                'search' => $categoryName,
                'per_page' => 10,
            ]);
        } catch (\Throwable) {
            return null;
        }

        return $this->matchTermId($terms, $categoryName);
    }

    /**
     * Case-insensitive trimmed exact-match over `name` field.
     *
     * @param  array<int|string, mixed>  $terms
     */
    private function matchTermId(array $terms, string $needle): ?int
    {
        $trimmedNeedle = trim($needle);

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $name = (string) ($term['name'] ?? '');
            if (strcasecmp(trim($name), $trimmedNeedle) === 0) {
                $id = $term['id'] ?? null;
                if (is_numeric($id)) {
                    return (int) $id;
                }
            }
        }

        return null;
    }
}
