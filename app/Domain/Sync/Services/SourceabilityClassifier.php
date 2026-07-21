<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

/**
 * Quick task 260719-mgp — PURE classification of "why isn't this on-Woo product
 * matched to supplier_sku_cache?".
 *
 * supplier_sku_cache holds EXACT lowercased+trimmed feed keys (LOWER(TRIM(mpn)) /
 * LOWER(TRIM(suppliersku)) — see SupplierSkuRegistry). A product is "not
 * sourceable" when its lowercased-trimmed SKU is absent from that set. This
 * classifier explains the miss for a single product, given its resolved
 * manufacturer + the feed rows already scoped to that manufacturer (supplied by
 * a {@see SupplierFeedReader} — no DB/network in this class):
 *
 *   (a) matching_gap             — norm(sku) equals norm(mpn)/norm(suppliersku) of
 *                                  some feed row → the supplier CARRIES it, just
 *                                  under a different format the exact-match cache
 *                                  missed. Fixable by the matcher.
 *   (b) brand_in_feed_item_absent — the manufacturer has feed rows, but none match
 *                                  even after normalisation → likely
 *                                  discontinued / lead-time.
 *   (c) not_in_feed              — no feed rows for the manufacturer at all →
 *                                  genuinely absent (no supplier lists the brand).
 *   (d) no_manufacturer          — the product has no brand/manufacturer to key
 *                                  on, so we can't scope a feed query.
 *
 * norm() = lowercase + strip every non-alphanumeric, so MR.JQU11.002,
 * MR-JQU11-002 and MRJQU11002 all collapse to "mrjqu11002". This is a DIAGNOSTIC
 * normalisation only — it is NOT applied to the live matcher (that stays exact
 * LOWER(TRIM)) until the split tells us it is worth it.
 */
final class SourceabilityClassifier
{
    public const MATCHING_GAP = 'matching_gap';

    public const BRAND_IN_FEED_ITEM_ABSENT = 'brand_in_feed_item_absent';

    public const NOT_IN_FEED = 'not_in_feed';

    public const NO_MANUFACTURER = 'no_manufacturer';

    /**
     * Diagnostic normalisation: lowercase + strip non-alphanumerics.
     */
    public function normalize(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($value));
    }

    /**
     * Classify one product against the feed rows already fetched for its
     * manufacturer.
     *
     * @param  array<int, array{mpn: string, suppliersku: string}>  $feedRows  rows for $manufacturer (empty ⇒ manufacturer absent)
     * @return array{bucket: string, matched_feed_key: ?string}
     */
    public function classify(?string $manufacturer, string $sku, array $feedRows): array
    {
        if ($manufacturer === null || trim($manufacturer) === '') {
            return ['bucket' => self::NO_MANUFACTURER, 'matched_feed_key' => null];
        }

        if ($feedRows === []) {
            return ['bucket' => self::NOT_IN_FEED, 'matched_feed_key' => null];
        }

        $skuNorm = $this->normalize($sku);
        if ($skuNorm !== '') {
            foreach ($feedRows as $row) {
                foreach (['mpn', 'suppliersku'] as $col) {
                    $feedValue = (string) ($row[$col] ?? '');
                    if ($feedValue !== '' && $this->normalize($feedValue) === $skuNorm) {
                        return ['bucket' => self::MATCHING_GAP, 'matched_feed_key' => $feedValue];
                    }
                }
            }
        }

        return ['bucket' => self::BRAND_IN_FEED_ITEM_ABSENT, 'matched_feed_key' => null];
    }
}
