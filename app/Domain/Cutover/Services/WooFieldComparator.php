<?php

declare(strict_types=1);

namespace App\Domain\Cutover\Services;

use App\Domain\Products\Models\Product;

/**
 * Phase 7 Plan 05 Task 1 — CUT-01 divergence scan field comparator.
 *
 * Given a local Product row and the Woo-live product dict (from WooClient::get),
 * emits a list of per-field diffs shaped:
 *
 *   [
 *     'field'      => 'name' | 'slug' | 'short_description' | 'long_description'
 *                     | 'meta_description' | 'sell_price' | 'image_url'
 *                     | 'stock_quantity' | 'stock_status' | 'buy_price'
 *                     | 'category_id' | 'brand_id' | 'ean' | 'exists',
 *     'laravel'    => mixed,
 *     'live'       => mixed,
 *     'pin_column' => 'pin_title' | 'pin_slug' | 'pin_short_description' | ...
 *                     | null       (when the field has no pin column — e.g. price)
 *   ]
 *
 * When the Woo-live dict is null or empty (no Woo row for this SKU), a single
 * 'exists' diff is emitted marking the product as missing-in-Woo so ops can
 * decide whether to create/delete.
 *
 * Pure function — no DB reads, no HTTP calls. Plan 07-05 DivergenceScanner
 * composes this with WooClient + SyncDiff writes.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Column mapping (Phase 2 Plan 01 Product schema -> Woo).
 *
 * 2026-06-10 quick task 260610-qc4 grew this from 7 fields to 13. The
 * 2026-05-30 cutover's shadow-mode divergence scan compared only the
 * original 7 and every missing field surfaced as a customer-first
 * incident weeks later (260609-nku phantom stock, 260607-v5g category
 * NULL, 260607-cgd brand+EAN NULL).
 *
 * Original 7 (Phase 7 Plan 05):
 *   - Product->name               ↔ woo['name']                                    (pin_title)
 *   - Product->slug               ↔ woo['slug']                                    (pin_slug)
 *   - Product->short_description  ↔ woo['short_description']                       (pin_short_description)
 *   - Product->long_description   ↔ woo['description']                             (pin_long_description)
 *   - Product->meta_description   ↔ woo['meta_data']._yoast_wpseo_metadesc        (pin_meta_description — silent skip when Yoast key absent)
 *   - Product->sell_price         ↔ woo['price']                                   (no pin column)
 *   - Product->image_url          ↔ woo['images'][0]['src']                        (pin_image)
 *
 * New 6 (260610-qc4):
 *   - Product->stock_quantity     ↔ woo['stock_quantity']                          (no pin column)
 *   - Product->stock_status       ↔ woo['stock_status']                            (no pin column)
 *   - Product->buy_price          ↔ woo['meta_data']._alg_wc_cog_cost              (no pin column — see CLAUDE.md WC COG plugin note)
 *   - Product->category_id        ↔ woo['categories'][0]['id']                     (no pin column)
 *   - Product->brand_id           ↔ woo['meta_data']._product_brand_id             (no pin column — pa_brand attribute fallback is deferred)
 *   - Product->ean                ↔ woo['meta_data'] walked over EAN_META_KEYS     (no pin column — see CLAUDE.md EAN provider note)
 *
 * Defensive contract — meta-only fields silent-skip when Woo lacks the key:
 *   buy_price (no _alg_wc_cog_cost), brand_id (no _product_brand_id),
 *   ean (no _global_unique_id / _ean / _alg_ean) — most Woo installs don't
 *   have the WC COG or EAN plugins. Emitting a diff for each absence would
 *   flood sync_diffs with thousands of false positives on the next live scan
 *   and bury the real divergences.
 *
 * Canonical Woo fields (stock_quantity, stock_status, category_id) DO emit
 * a diff when local has a value but Woo top-level key is absent — Woo
 * absence on a canonical column IS a divergence (the 260609-nku Ergotron
 * phantom-stock class).
 *
 * brand_id pa_brand-attribute fallback is DEFERRED — resolving pa_brand
 * NAME → id requires injecting TaxonomyResolver (HTTP dependency = breaks
 * the pure-function contract). Documented in 260610-qc4 PLAN as a follow-up.
 *
 * meta_description in Woo lives under the Yoast meta_data array — absent in
 * many Woo installs, so a null/missing comparison uses the empty-string
 * convention to avoid spurious diffs on greenfield rows.
 *
 * If you remove a field comparison from diff(), you MUST also remove it
 * from DivergenceComparatorCoverageTest::EXPECTED_FIELDS — both edits in
 * the same commit. The arch test fails the build otherwise. See
 * .planning/quick/260610-qc4-extend-woofieldcomparator-to-cover-6-mis/260610-qc4-PLAN.md
 * Drift-prevention contract.
 * ─────────────────────────────────────────────────────────────────────────
 */
class WooFieldComparator
{
    /**
     * Woo meta_key carrying Yoast SEO meta description.
     *
     * The Phase 7 Plan 05 docblock declared this mapping but never
     * implemented the comparison block; 260610-qc4 closes that pre-existing
     * gap. Confirmed write path in PublishProductJob.php:280.
     */
    public const META_DESCRIPTION_META_KEY = '_yoast_wpseo_metadesc';

    /**
     * Woo meta_key carrying the buy_price (cost of goods).
     *
     * The legacy meetingstore.co.uk Woo store uses the "Algoritmika WC Cost
     * of Goods" plugin which stores cost as meta_key=_alg_wc_cog_cost.
     * Confirmed in WooImportProductsCommand.php:136.
     */
    public const BUY_PRICE_META_KEY = '_alg_wc_cog_cost';

    /**
     * Woo meta_key carrying the brand term id (Priority 1 brand path).
     *
     * Per TaxonomyResolver — Priority 2 is the legacy `pa_brand` attribute
     * which carries the brand NAME rather than the term id. Resolving
     * pa_brand → id requires TaxonomyResolver (HTTP dependency), so the
     * comparator stays on Priority 1 only and silently skips brand_id
     * when this meta is absent. See Case G in WooFieldComparatorTest.
     */
    public const BRAND_ID_META_KEY = '_product_brand_id';

    /**
     * Woo meta_keys carrying the EAN, walked in priority order.
     *
     *   1. _global_unique_id  WC 9.x / Google Listings & Ads canonical slot
     *                         (PublishProductJob writes here post-Phase-6).
     *   2. _ean               Algol EAN generic plugin (legacy fallback).
     *   3. _alg_ean           Algol Numbers For WooCommerce (legacy fallback).
     *
     * Walked left-to-right; first non-empty wins. Older meetingstore.co.uk
     * data may live in any of the three slots.
     *
     * Kept `public const` so WooFieldComparatorTest can iterate the list
     * without reflection.
     */
    public const EAN_META_KEYS = ['_global_unique_id', '_ean', '_alg_ean'];

    /**
     * Compare a Laravel Product against a Woo-live product dict.
     *
     * @param  Product  $local  Laravel row
     * @param  array<string, mixed>|object|null  $wooProduct  Woo REST response; null = missing
     * @return array<int, array{field:string, laravel:mixed, live:mixed, pin_column:?string}>
     */
    public function diff(Product $local, array|object|null $wooProduct): array
    {
        // Woo SDK list responses (e.g. products?sku=…) return a stdClass per item,
        // not an array. Deep-normalise to an associative array so the array-access
        // comparisons below (including nested meta_data) behave uniformly.
        if (is_object($wooProduct)) {
            $wooProduct = json_decode((string) json_encode($wooProduct), true);
        }

        if ($wooProduct === null || $wooProduct === []) {
            return [[
                'field' => 'exists',
                'laravel' => true,
                'live' => false,
                'pin_column' => null,
            ]];
        }

        $diffs = [];

        if ((string) $local->name !== (string) ($wooProduct['name'] ?? '')) {
            $diffs[] = [
                'field' => 'name',
                'laravel' => $local->name,
                'live' => $wooProduct['name'] ?? null,
                'pin_column' => 'pin_title',
            ];
        }

        if ((string) ($local->slug ?? '') !== (string) ($wooProduct['slug'] ?? '')) {
            $diffs[] = [
                'field' => 'slug',
                'laravel' => $local->slug,
                'live' => $wooProduct['slug'] ?? null,
                'pin_column' => 'pin_slug',
            ];
        }

        if ((string) ($local->short_description ?? '') !== (string) ($wooProduct['short_description'] ?? '')) {
            $diffs[] = [
                'field' => 'short_description',
                'laravel' => $local->short_description,
                'live' => $wooProduct['short_description'] ?? null,
                'pin_column' => 'pin_short_description',
            ];
        }

        if ((string) ($local->long_description ?? '') !== (string) ($wooProduct['description'] ?? '')) {
            $diffs[] = [
                'field' => 'long_description',
                'laravel' => $local->long_description,
                'live' => $wooProduct['description'] ?? null,
                'pin_column' => 'pin_long_description',
            ];
        }

        // meta_description — Yoast SEO meta_key on Woo side. Meta-only field:
        // silent-skip when the Yoast key is absent (Yoast not installed on
        // this Woo, or no SEO description set yet). The Phase 7 Plan 05
        // docblock declared this mapping; 260610-qc4 closes the pre-existing
        // implementation gap.
        $liveMetaDescription = $this->extractMetaValue($wooProduct, self::META_DESCRIPTION_META_KEY);
        $localMetaDescription = $local->meta_description !== null && $local->meta_description !== ''
            ? (string) $local->meta_description
            : null;
        $liveMetaDescriptionStr = $liveMetaDescription !== null && $liveMetaDescription !== ''
            ? (string) $liveMetaDescription
            : null;
        if ($localMetaDescription !== null && $liveMetaDescriptionStr !== null && $localMetaDescription !== $liveMetaDescriptionStr) {
            $diffs[] = [
                'field' => 'meta_description',
                'laravel' => $localMetaDescription,
                'live' => $liveMetaDescriptionStr,
                'pin_column' => 'pin_meta_description',
            ];
        }

        // sell_price is a decimal column; Woo returns string "12.99". Compare as floats.
        $localPrice = $local->sell_price !== null ? (float) $local->sell_price : null;
        $livePrice = isset($wooProduct['price']) && $wooProduct['price'] !== ''
            ? (float) $wooProduct['price']
            : null;
        if ($localPrice !== null && $livePrice !== null && abs($localPrice - $livePrice) > 0.005) {
            $diffs[] = [
                'field' => 'sell_price',
                'laravel' => $localPrice,
                'live' => $livePrice,
                'pin_column' => null,
            ];
        }

        // Image URL — Woo ships images[] array of {id,src,...}; first entry is the primary.
        $liveImage = $wooProduct['images'][0]['src'] ?? null;
        if ($local->image_url !== null && $liveImage !== null
            && (string) $local->image_url !== (string) $liveImage) {
            $diffs[] = [
                'field' => 'image_url',
                'laravel' => $local->image_url,
                'live' => $liveImage,
                'pin_column' => 'pin_image',
            ];
        }

        // ── 260610-qc4 — 6 new comparisons ───────────────────────────────────────

        // stock_quantity — canonical Woo top-level column. Local int cast.
        // EMIT diff when local has a value AND either: Woo top-level key
        // absent (the 260609-nku Ergotron phantom-stock class — Woo absence
        // on a canonical column IS a divergence), OR Woo present and int
        // values differ. Single emit per field per the comparator contract.
        $localStockQty = $local->stock_quantity;
        $wooHasStockQtyKey = array_key_exists('stock_quantity', $wooProduct);
        $liveStockQty = $wooHasStockQtyKey && $wooProduct['stock_quantity'] !== null
            ? (int) $wooProduct['stock_quantity']
            : null;
        $stockQtyDiverges = $localStockQty !== null
            && (! $wooHasStockQtyKey || ($liveStockQty !== null && (int) $localStockQty !== $liveStockQty));
        if ($stockQtyDiverges) {
            $diffs[] = [
                'field' => 'stock_quantity',
                'laravel' => (int) $localStockQty,
                'live' => $liveStockQty,
                'pin_column' => null,
            ];
        }

        // stock_status — canonical Woo top-level column. Case-insensitive string compare.
        // EMIT when local has value AND either Woo top-level key absent
        // (canonical absence) or values differ after lowercase. Single emit per field.
        $localStockStatus = $local->stock_status !== null && $local->stock_status !== ''
            ? strtolower((string) $local->stock_status)
            : null;
        $wooHasStockStatusKey = array_key_exists('stock_status', $wooProduct);
        $liveStockStatusRaw = $wooHasStockStatusKey ? $wooProduct['stock_status'] : null;
        $liveStockStatus = $liveStockStatusRaw !== null && $liveStockStatusRaw !== ''
            ? strtolower((string) $liveStockStatusRaw)
            : null;
        $stockStatusDiverges = $localStockStatus !== null
            && (! $wooHasStockStatusKey || ($liveStockStatus !== null && $localStockStatus !== $liveStockStatus));
        if ($stockStatusDiverges) {
            $diffs[] = [
                'field' => 'stock_status',
                'laravel' => $local->stock_status,
                'live' => $liveStockStatusRaw,
                'pin_column' => null,
            ];
        }

        // buy_price — meta-only field via _alg_wc_cog_cost (Algoritmika WC COG plugin).
        // Silent-skip when Woo lacks the meta key (most installs don't have the
        // plugin — defensive contract prevents false-positive flood).
        $liveBuyPriceRaw = $this->extractMetaValue($wooProduct, self::BUY_PRICE_META_KEY);
        $localBuyPrice = $local->buy_price !== null ? (float) $local->buy_price : null;
        $liveBuyPrice = $liveBuyPriceRaw !== null && $liveBuyPriceRaw !== ''
            ? (float) $liveBuyPriceRaw
            : null;
        if ($localBuyPrice !== null && $liveBuyPrice !== null && abs($localBuyPrice - $liveBuyPrice) > 0.005) {
            $diffs[] = [
                'field' => 'buy_price',
                'laravel' => $localBuyPrice,
                'live' => $liveBuyPrice,
                'pin_column' => null,
            ];
        }

        // category_id — canonical Woo top-level via categories[0].id.
        // EMIT diff when local has a value AND either Woo categories[0] absent
        // (canonical absence — 260607-v5g 3,244/3,922 NULL class), OR Woo
        // present and ids differ. Single emit per field.
        $localCategoryId = $local->category_id !== null ? (int) $local->category_id : null;
        $liveCategoryId = isset($wooProduct['categories'][0]['id'])
            ? (int) $wooProduct['categories'][0]['id']
            : null;
        $categoryDiverges = $localCategoryId !== null
            && ($liveCategoryId === null || $localCategoryId !== $liveCategoryId);
        if ($categoryDiverges) {
            $diffs[] = [
                'field' => 'category_id',
                'laravel' => $localCategoryId,
                'live' => $liveCategoryId,
                'pin_column' => null,
            ];
        }

        // brand_id — meta-only via _product_brand_id (Priority 1 brand path).
        // Silent-skip when meta absent (Woo may carry brand via pa_brand attribute
        // only — deferred to follow-up quick because pa_brand→id resolution
        // requires TaxonomyResolver HTTP dependency).
        $liveBrandId = $this->extractBrandId($wooProduct);
        $localBrandId = $local->brand_id !== null ? (int) $local->brand_id : null;
        if ($localBrandId !== null && $liveBrandId !== null && $localBrandId !== $liveBrandId) {
            $diffs[] = [
                'field' => 'brand_id',
                'laravel' => $localBrandId,
                'live' => $liveBrandId,
                'pin_column' => null,
            ];
        }

        // ean — meta-only via EAN_META_KEYS walk (3 plugin conventions).
        // Silent-skip when none of the 3 keys present (Woo not yet backfilled).
        $liveEan = $this->extractEan($wooProduct);
        $localEan = $local->ean !== null && $local->ean !== '' ? trim((string) $local->ean) : null;
        if ($localEan !== null && $liveEan !== null && $localEan !== $liveEan) {
            $diffs[] = [
                'field' => 'ean',
                'laravel' => $localEan,
                'live' => $liveEan,
                'pin_column' => null,
            ];
        }

        return $diffs;
    }

    /**
     * Walk Woo meta_data for the first entry matching $key. Returns the
     * `value` (may be string|int|null) or null when not found.
     *
     * @param  array<string, mixed>  $wooProduct
     */
    private function extractMetaValue(array $wooProduct, string $key): mixed
    {
        foreach ($wooProduct['meta_data'] ?? [] as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            if (($meta['key'] ?? null) === $key) {
                return $meta['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * Pull brand term id from Woo meta_data._product_brand_id. Returns null
     * when the meta is absent or its value is non-numeric/empty.
     *
     * @param  array<string, mixed>  $wooProduct
     */
    private function extractBrandId(array $wooProduct): ?int
    {
        $val = $this->extractMetaValue($wooProduct, self::BRAND_ID_META_KEY);
        if ($val === null || $val === '' || ! is_numeric($val)) {
            return null;
        }

        return (int) $val;
    }

    /**
     * Walk Woo meta_data for the first non-empty EAN value across the
     * EAN_META_KEYS priority chain. Returns null when none of the 3 keys
     * have a non-empty value (most Woo installs not yet backfilled).
     *
     * @param  array<string, mixed>  $wooProduct
     */
    private function extractEan(array $wooProduct): ?string
    {
        foreach (self::EAN_META_KEYS as $eanKey) {
            $val = $this->extractMetaValue($wooProduct, $eanKey);
            if ($val === null) {
                continue;
            }
            $trimmed = trim((string) $val);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
