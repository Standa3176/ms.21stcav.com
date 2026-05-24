<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 6 Plan 03 — resolve supplier-supplied brand + category strings against
 * Woo's existing taxonomy via the REST API.
 *
 * v2 (2026-05-24): exact-match-only proved too brittle (supplier/AI strings
 * rarely equal a Woo term verbatim), so resolveBrand/resolveCategory now
 * fuzzy-match against the FULL live term list (token-overlap + similar_text,
 * threshold-gated). The full lists are also exposed via allCategories() /
 * allBrands() + categoryIdByName() so callers (products:assign-taxonomy) can
 * let Claude pick the best-fit category from the real catalogue.
 *
 * Brand lookup fix: Woo's attribute-terms endpoint needs the NUMERIC attribute
 * id, not the slug — we resolve `pa_brand` → id first, and fall back to Woo's
 * native `/products/brands` taxonomy when the attribute is absent.
 *
 * Taxonomy slugs configurable via config('product_auto_create.brand_taxonomy')
 * (default 'pa_brand') + 'category_taxonomy' (default 'product_cat'). All Woo
 * failures are swallowed → null/empty (WooClient already logs to
 * integration_events). Term lists cached 1h.
 */
final class TaxonomyResolver
{
    private const CACHE_TTL_SECONDS = 3600;

    private const FUZZY_THRESHOLD = 0.55;

    public function __construct(private WooClient $woo) {}

    public function resolveBrand(?string $brandName): ?int
    {
        if ($brandName === null || trim($brandName) === '') {
            return null;
        }

        return $this->bestMatchId($this->allBrands(), $brandName);
    }

    public function resolveCategory(?string $categoryName): ?int
    {
        if ($categoryName === null || trim($categoryName) === '') {
            return null;
        }

        return $this->bestMatchId($this->allCategories(), $categoryName);
    }

    /**
     * Exact (case-insensitive) category-name → id lookup. Used after Claude has
     * picked a name VERBATIM from allCategories(), so no fuzzy needed.
     */
    public function categoryIdByName(string $name): ?int
    {
        $needle = $this->normalise($name);
        foreach ($this->allCategories() as $term) {
            if ($this->normalise($term['name']) === $needle) {
                return $term['id'];
            }
        }

        return null;
    }

    /**
     * All Woo product categories as [['id'=>int,'name'=>string], ...].
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function allCategories(): array
    {
        return Cache::remember('taxonomy.categories', self::CACHE_TTL_SECONDS, function (): array {
            return $this->paginate('/products/categories');
        });
    }

    /**
     * All Woo brand terms as [['id'=>int,'name'=>string], ...]. Resolves the
     * pa_brand attribute id first; falls back to the native /products/brands.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function allBrands(): array
    {
        return Cache::remember('taxonomy.brands', self::CACHE_TTL_SECONDS, function (): array {
            $taxonomy = (string) config('product_auto_create.brand_taxonomy', 'pa_brand');

            // 1) Brand-as-attribute: find the attribute id for the slug.
            $attributeId = $this->brandAttributeId($taxonomy);
            if ($attributeId !== null) {
                $terms = $this->paginate("/products/attributes/{$attributeId}/terms");
                if ($terms !== []) {
                    return $terms;
                }
            }

            // 2) Native Woo Brands taxonomy fallback.
            try {
                $terms = $this->paginate('/products/brands');
                if ($terms !== []) {
                    return $terms;
                }
            } catch (\Throwable) {
                // ignore
            }

            return [];
        });
    }

    private function brandAttributeId(string $slug): ?int
    {
        try {
            $attributes = $this->woo->get('/products/attributes', ['per_page' => 100]);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($attributes)) {
            return null;
        }
        foreach ($attributes as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $attrSlug = (string) ($attr['slug'] ?? '');
            // Woo prefixes custom attribute slugs with "pa_"; match either form.
            if ($attrSlug === $slug || $attrSlug === "pa_{$slug}" || "pa_{$attrSlug}" === $slug) {
                $id = $attr['id'] ?? null;

                return is_numeric($id) ? (int) $id : null;
            }
        }

        return null;
    }

    /**
     * Page through a Woo terms endpoint (per_page=100) into [id,name] rows.
     *
     * @return array<int, array{id:int, name:string}>
     */
    private function paginate(string $endpoint): array
    {
        $out = [];
        $page = 1;
        do {
            $batch = $this->woo->get($endpoint, ['per_page' => 100, 'page' => $page]);
            if (! is_array($batch) || $batch === []) {
                break;
            }
            foreach ($batch as $term) {
                if (! is_array($term)) {
                    continue;
                }
                $id = $term['id'] ?? null;
                $name = (string) ($term['name'] ?? '');
                if (is_numeric($id) && $name !== '') {
                    $out[] = ['id' => (int) $id, 'name' => $name];
                }
            }
            $page++;
        } while (count($batch) === 100 && $page <= 50); // hard cap: 5,000 terms

        return $out;
    }

    /**
     * Best fuzzy match over a term list; null when nothing clears the threshold.
     *
     * @param  array<int, array{id:int, name:string}>  $terms
     */
    private function bestMatchId(array $terms, string $needle): ?int
    {
        $bestId = null;
        $bestScore = 0.0;
        foreach ($terms as $term) {
            $score = $this->score($needle, $term['name']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $term['id'];
            }
        }

        return $bestScore >= self::FUZZY_THRESHOLD ? $bestId : null;
    }

    /** Similarity in [0,1]: max of token-overlap (Jaccard) and similar_text %. */
    private function score(string $a, string $b): float
    {
        $na = $this->normalise($a);
        $nb = $this->normalise($b);
        if ($na === '' || $nb === '') {
            return 0.0;
        }
        if ($na === $nb) {
            return 1.0;
        }
        // One fully containing the other is a strong signal (e.g. "Sony" ⊂ "Sony Professional").
        if (str_contains($na, $nb) || str_contains($nb, $na)) {
            return 0.9;
        }

        $ta = array_filter(explode(' ', $na));
        $tb = array_filter(explode(' ', $nb));
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        $jaccard = $union > 0 ? $inter / $union : 0.0;

        similar_text($na, $nb, $pct);

        return max($jaccard, $pct / 100);
    }

    private function normalise(string $s): string
    {
        $s = strtolower(trim($s));
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
