<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\ProductAutoCreate\Concerns\NormalisesBrandNames;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260702-qd8 — find-or-create a WC-native /products/brands term for
 * a manufacturer name. Shared by CreateWooProductJob + DraftFromSuggestionsCommand
 * (and available to BrandsToAddPage). Normalises + junk-guards so it never
 * re-pollutes the taxonomy the 260702-om7 brands-to-add cleanup fixed.
 *
 * NEVER throws to callers — returns null on any failure (blank/junk/shadow/error)
 * so the caller falls back to the existing skip/park behaviour.
 */
class WooBrandCreator
{
    use NormalisesBrandNames;

    public function __construct(
        private readonly WooClient $woo,
        private readonly TaxonomyResolver $taxonomy,
    ) {}

    /**
     * @return int|null the Woo brand term id (existing or newly created); null
     *                  when the name is blank/junk, writes are disabled/shadowed,
     *                  or creation failed (caller then falls back to skip/park).
     */
    public function ensureBrandTermId(?string $rawName): ?int
    {
        $name = $this->normaliseBrandName((string) ($rawName ?? ''));
        if ($name === '' || $this->isJunkBrand($name)) {
            return null;
        }

        // Already on Woo (case-insensitive)? return its id, no POST.
        $existing = $this->findExistingId($name);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $resp = $this->woo->post('products/brands', ['name' => $name]);
        } catch (\Throwable $e) {
            if ($this->isTermExists($e)) {
                Cache::forget('taxonomy.brands');

                return $this->findExistingId($name); // created concurrently / race
            }
            Log::warning('woo_brand_creator.create_failed', ['brand' => $name, 'error' => $e->getMessage()]);

            return null;
        }

        // Shadow mode (WOO_WRITE_ENABLED=false) → no real id was minted.
        if ((bool) ($resp['shadow_mode'] ?? false)) {
            return null;
        }
        $id = (int) ($resp['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        Cache::forget('taxonomy.brands'); // so TaxonomyResolver::allBrands re-reads incl. the new term

        return $id;
    }

    /**
     * Find an EXISTING Woo brand id for this (normalised) name, or null. Uses the
     * same fuzzy matcher the per-row create path trusts (TaxonomyResolver::resolveBrand
     * → bestMatchId, FUZZY_THRESHOLD 0.85) so a more-specific feed name like
     * 'Barco Clickshare' reuses the existing 'Barco' term instead of spawning a
     * near-duplicate. resolveBrand normalises + reads the cached allBrands() list.
     */
    private function findExistingId(string $name): ?int
    {
        $id = $this->taxonomy->resolveBrand($name);

        return ($id !== null && $id > 0) ? $id : null;
    }

    /** Mirror BrandsToAddPage::isTermExists — WC surfaces a term-exists error on duplicate names. */
    private function isTermExists(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());

        return str_contains($m, 'term_exists') || str_contains($m, 'already exists');
    }
}
