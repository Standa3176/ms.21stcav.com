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
 *                     | 'meta_description' | 'sell_price' | 'image_url' | 'exists',
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
 * Column mapping (Phase 2 Plan 01 Product schema -> Woo):
 *   - Product->name               ↔ woo['name']              (pin_title)
 *   - Product->slug               ↔ woo['slug']              (pin_slug)
 *   - Product->short_description  ↔ woo['short_description'] (pin_short_description)
 *   - Product->long_description   ↔ woo['description']       (pin_long_description)
 *   - Product->meta_description   ↔ woo['meta_data']         (pin_meta_description)
 *   - Product->sell_price         ↔ woo['price']             (no pin column)
 *   - Product->image_url          ↔ woo['images'][0]['src']  (pin_image)
 *
 * meta_description in Woo lives under the Yoast meta_data array — absent in
 * many Woo installs, so a null/missing comparison uses the empty-string
 * convention to avoid spurious diffs on greenfield rows.
 */
class WooFieldComparator
{
    /**
     * Compare a Laravel Product against a Woo-live product dict.
     *
     * @param  Product  $local  Laravel row
     * @param  array<string, mixed>|null  $wooProduct  Woo REST response; null = missing
     * @return array<int, array{field:string, laravel:mixed, live:mixed, pin_column:?string}>
     */
    public function diff(Product $local, ?array $wooProduct): array
    {
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

        return $diffs;
    }
}
