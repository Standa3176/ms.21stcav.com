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
 * 2026-06-13 INCIDENT (260613-pzc) — slug-collision handling rewrite.
 * The previous unconditional `-brand` suffix fallback silently produced
 * 11 duplicate brand pairs on prod ({brand} + {brand}-brand) over time:
 * every time WP refused a clean-slug product_brand create because a
 * `product_tag` already owned that slug, the resolver blindly retried
 * with `{slug}-brand` which succeeded, then later code created the
 * clean-slug brand → duplicate pair. Cleanup arc tracked in memory
 * `meetingstore-brand-cleanup-followups`.
 *
 * Slug-collision handling is now strategy-driven via
 * `config('services.woo.brand_slug_collision_strategy')`:
 *
 *   - 'skip-creation' (default, safe): pre-flight checks
 *     `wp/v2/product_tag?slug={primary}`; on collision, logs warning +
 *     returns null. The `-brand` suffix is NEVER created — operator
 *     intervention required (delete the colliding tag, or flip strategy).
 *   - 'auto-delete-empty-colliding-tag' (aggressive, opt-in): same
 *     pre-flight; on collision with count=0, deletes the empty tag via
 *     260613-plo's WAF-tunnelled DELETE (WpRestClient::delete) and
 *     retries the clean slug. Tags with attached products fall through
 *     to skip-creation behaviour.
 *   - 'force-suffix' (DEPRECATED escape hatch): replicates old
 *     pre-260613-pzc behaviour — bypasses pre-flight, creates the
 *     `-brand` suffixed term with a warning surfacing the duplicate-
 *     pair risk on every invocation.
 *
 * Pre-flight probe failure is defensive: a transient WP-REST error on
 * the GET probe returns null from checkProductTagCollision (NOT an
 * exception), so brand creation gracefully falls back to the legacy
 * 2-attempt behaviour rather than blocking forever on a probe blip.
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
     * Create a product_brand term with pre-flight slug-collision detection.
     *
     * 2026-06-13 INCIDENT — the old `-brand` suffix fallback (without pre-
     * flight) silently created 11 duplicate brand pairs on prod
     * ({brand} + {brand}-brand). Root cause: tryCreate(primary) fails
     * because WP refuses cross-taxonomy slug collisions; the unconditional
     * retry with `{slug}-brand` succeeded → operator (or later code path)
     * created the clean-slug brand later → duplicate pair. See memory
     * `meetingstore-brand-cleanup-followups` for the cleanup arc.
     *
     * Strategy (config: services.woo.brand_slug_collision_strategy):
     *   - 'skip-creation'                   (default, safe)  log + return null
     *   - 'auto-delete-empty-colliding-tag' (aggressive)     delete empty tag, retry
     *   - 'force-suffix'                    (DEPRECATED)     old behaviour, last resort
     */
    private function createTerm(string $brandName): ?int
    {
        $primarySlug = Str::slug($brandName);

        // Attempt 1: clean primary slug (always tried first regardless of
        // strategy). Succeeds for brands without a name-colliding
        // product_tag (e.g. brand-new brands like "DTEN" if no dten
        // product_tag exists). Common case at scale once tags and brands
        // diverge.
        $id = $this->tryCreate($brandName, $primarySlug);
        if ($id !== null) {
            return $id;
        }

        $strategy = (string) config('services.woo.brand_slug_collision_strategy', 'skip-creation');

        // force-suffix branch: bypass pre-flight entirely, preserve OLD
        // behaviour with an explicit warning that surfaces the duplicate-
        // pair risk to operators on every invocation.
        if ($strategy === 'force-suffix') {
            Log::warning('product_brand.force_suffix_strategy_in_use', [
                'brand' => $brandName,
                'risk' => 'duplicate brand pair if clean-slug term created later',
            ]);
            $id = $this->tryCreate($brandName, $primarySlug.'-brand');
            if ($id !== null) {
                return $id;
            }
            Log::warning('product_brand.create_failed_force_suffix', [
                'brand' => $brandName,
                'tried_slugs' => [$primarySlug, $primarySlug.'-brand'],
            ]);

            return null;
        }

        // skip-creation + auto-delete-empty-colliding-tag share the
        // pre-flight probe against product_tag.
        $collision = $this->checkProductTagCollision($primarySlug);

        if ($collision === null) {
            // No collision detected (OR pre-flight errored — defensive).
            // Fall back to the OLD 2-attempt pattern: something OTHER than
            // a known slug collision caused the primary failure (5xx, auth
            // blip). The `-brand` suffix is a reasonable last resort here
            // because there's no IDENTIFIED colliding tag, so we cannot
            // create the duplicate-pair pathology by name. This path also
            // covers the "probe failed" sanity case (260613-pzc Case F).
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

        // Collision detected. Auto-delete branch fires ONLY when strategy
        // opts in AND the colliding tag has zero products attached.
        if ($strategy === 'auto-delete-empty-colliding-tag' && ($collision['count'] ?? 1) === 0) {
            try {
                $this->wp->delete('wp/v2/product_tag/'.$collision['id'].'?force=true');
                Log::info('product_brand.auto_deleted_empty_colliding_tag', [
                    'brand' => $brandName,
                    'tag_id' => $collision['id'],
                ]);

                // Retry primary slug — now unblocked.
                $id = $this->tryCreate($brandName, $primarySlug);
                if ($id !== null) {
                    return $id;
                }
            } catch (\Throwable $e) {
                Log::warning('product_brand.auto_delete_failed', [
                    'brand' => $brandName,
                    'tag_id' => $collision['id'],
                    'error' => $e->getMessage(),
                ]);
                // Fall through to the skip-creation warning + null return.
            }
        }

        // skip-creation (default) OR auto-delete with non-empty tag OR
        // auto-delete failure path. NEVER create the suffixed duplicate.
        Log::warning('product_brand.tag_slug_collision', [
            'brand' => $brandName,
            'colliding_tag_id' => $collision['id'],
            'colliding_tag_count' => $collision['count'] ?? null,
            'strategy' => $strategy,
            'reason' => ($strategy === 'auto-delete-empty-colliding-tag' && ($collision['count'] ?? 1) > 0)
                ? 'tag not empty'
                : 'strategy=skip-creation',
            'operator_action' => 'Delete the empty colliding product_tag in wp-admin, OR set WOO_BRAND_SLUG_COLLISION_STRATEGY=auto-delete-empty-colliding-tag',
        ]);

        return null;
    }

    /**
     * Pre-flight check: does a product_tag with this slug already exist?
     * Returns ['id' => N, 'count' => M] on collision, null otherwise (no
     * collision OR transient WP-REST error — defensive null so brand
     * creation doesn't block forever on a probe blip).
     *
     * @return array{id:int,count:int}|null
     */
    private function checkProductTagCollision(string $slug): ?array
    {
        try {
            $result = $this->wp->get('wp/v2/product_tag', ['slug' => $slug]);
            if ($result === []) {
                return null;
            }
            $first = $result[0] ?? null;
            if (! is_array($first)) {
                return null;
            }
            $id = $first['id'] ?? null;
            $count = $first['count'] ?? 0;
            if (! is_numeric($id) || (int) $id <= 0) {
                return null;
            }

            return [
                'id' => (int) $id,
                'count' => (int) $count,
            ];
        } catch (\Throwable $e) {
            Log::warning('product_brand.tag_collision_probe_failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
