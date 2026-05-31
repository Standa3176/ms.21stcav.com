<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Sync\Services\WpRestClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves a brand name (e.g. "Yealink") to its `product_brand` taxonomy
 * term id on meetingstore.co.uk. Creates the term if it doesn't exist.
 *
 * The `product_brand` taxonomy is the storefront's curated brand registry
 * (URL: /brand/<slug>/, REST: /wp/v2/product_brand). Distinct from the
 * WC native /products/brands endpoint (dormant on this storefront — see
 * memory meetingstore-brand-display) AND from product_tag (where brand
 * names ALSO commonly exist as tags, causing slug collisions on create).
 *
 * Lookup is name-based (case-insensitive) because our local Product.brand_id
 * points at WC's /products/brands term ids (a parallel taxonomy with
 * different ids than product_brand). The brand NAME is the only shared
 * identity across taxonomies.
 *
 * Slug-collision handling: WordPress's wp_insert_term refuses to create
 * a term whose slug already exists in ANY taxonomy (e.g. trying to create
 * product_brand slug=yealink fails with term_exists=3001 because the
 * product_tag Yealink already owns that slug). Fallback: retry with
 * slug variant `{slug}-brand` (URL becomes /brand/yealink-brand/ —
 * functional, slightly uglier).
 *
 * Brand-term list cached for 1h to avoid hammering the WP REST API on
 * bulk runs. Cache is busted by the resolver itself on successful term
 * create.
 */
// Non-final so tests can stub it (matches TaxonomyResolver pattern).
class ProductBrandTermResolver
{
    private const CACHE_KEY = 'product_brand.term_map';

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly WpRestClient $wp,
    ) {}

    /**
     * Resolve a brand NAME to its product_brand term id. Returns null on
     * any failure (logged) so callers can degrade gracefully — brand
     * display still works via tags + attributes spec row even when this
     * link fails.
     */
    public function getTermIdForName(?string $brandName): ?int
    {
        if ($brandName === null) {
            return null;
        }
        $brandName = trim($brandName);
        if ($brandName === '') {
            return null;
        }

        $key = $this->normaliseName($brandName);
        $map = $this->getCachedMap();

        if (isset($map[$key])) {
            return $map[$key];
        }

        // Term doesn't exist — create it.
        $created = $this->createTerm($brandName);
        if ($created !== null) {
            $map[$key] = $created;
            Cache::put(self::CACHE_KEY, $map, self::CACHE_TTL_SECONDS);
        }

        return $created;
    }

    /**
     * Assign a product_brand term to a product via WP REST API. Returns
     * true on success, false on any failure (logged). Idempotent — re-
     * posting the same term is a no-op on WC's side. Empty $termIds
     * CLEARS all brand assignments on the product.
     *
     * @param  array<int, int>  $termIds
     */
    public function assignToProduct(int $wooProductId, array $termIds): bool
    {
        try {
            $this->wp->post("wp/v2/product/{$wooProductId}", [
                'product_brand' => array_values(array_unique(array_map(
                    static fn ($id): int => (int) $id,
                    $termIds,
                ))),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('product_brand.assign_failed', [
                'woo_product_id' => $wooProductId,
                'term_ids' => $termIds,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Force a fresh fetch of the term map. Useful in tests + after
     * external term creation.
     */
    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Build (or load from cache) a map of normalised brand name → term id.
     * Filters to only terms whose taxonomy field is "product_brand" so we
     * skip the cross-taxonomy noise WP's search returns.
     *
     * @return array<string, int>
     */
    private function getCachedMap(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $map = [];
            try {
                $page = 1;
                do {
                    $batch = $this->wp->get('wp/v2/product_brand', [
                        'per_page' => 100,
                        'page' => $page,
                    ]);
                    if (! is_array($batch) || $batch === []) {
                        break;
                    }
                    foreach ($batch as $t) {
                        if (! is_array($t)) {
                            continue;
                        }
                        // Only count terms whose taxonomy IS product_brand
                        // (WP's REST sometimes returns cross-taxonomy matches
                        // — see brand-display memory for the gotcha).
                        if (($t['taxonomy'] ?? '') !== 'product_brand') {
                            continue;
                        }
                        $name = (string) ($t['name'] ?? '');
                        $id = $t['id'] ?? null;
                        if ($name !== '' && is_numeric($id)) {
                            $map[$this->normaliseName($name)] = (int) $id;
                        }
                    }
                    $page++;
                } while (count($batch) === 100 && $page <= 10);
            } catch (\Throwable $e) {
                Log::warning('product_brand.list_failed', ['error' => $e->getMessage()]);

                return [];
            }

            return $map;
        });
    }

    /**
     * Create a product_brand term, with slug-collision fallback. Returns
     * the new term id, or null on hard failure (logged).
     */
    private function createTerm(string $brandName): ?int
    {
        $primarySlug = Str::slug($brandName);

        // Attempt 1: plain slug. Succeeds for brands without a name-
        // colliding product_tag (e.g. brand-new brands like "DTEN" if no
        // dten product_tag exists). Common case at scale once tags
        // and brands diverge.
        $id = $this->tryCreate($brandName, $primarySlug);
        if ($id !== null) {
            return $id;
        }

        // Attempt 2: slug variant. WordPress refuses cross-taxonomy slug
        // collisions via REST — even though distinct taxonomies CAN share
        // slugs in the underlying DB. The "-brand" suffix sidesteps it.
        $id = $this->tryCreate($brandName, $primarySlug.'-brand');
        if ($id !== null) {
            return $id;
        }

        Log::warning('product_brand.create_failed_all_slugs', [
            'brand' => $brandName,
            'tried_slugs' => [$primarySlug, $primarySlug.'-brand'],
        ]);

        return null;
    }

    private function tryCreate(string $name, string $slug): ?int
    {
        try {
            $r = $this->wp->post('wp/v2/product_brand', [
                'name' => $name,
                'slug' => $slug,
            ]);
            $id = $r['id'] ?? null;
            if (is_numeric($id) && (int) $id > 0) {
                Log::info('product_brand.created', [
                    'brand' => $name,
                    'slug' => $slug,
                    'term_id' => (int) $id,
                ]);

                return (int) $id;
            }
        } catch (\Throwable $e) {
            // Term_exists collision is expected on the first attempt for
            // most brands (Yealink, Neat etc. already have product_tag
            // terms). Log at debug; the caller retries with slug variant.
            Log::debug('product_brand.create_attempt_failed', [
                'brand' => $name,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function normaliseName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
