<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Services;

/**
 * Phase 5 Plan 02 Task 1 — auto-detect SKU + price column indexes (COMP-02).
 *
 * Matches normalised (trim + strtolower) header tokens against two
 * precedence-ordered lists using CONTAINS semantics — e.g. the header
 * "Price GBP" normalises to "price gbp" which contains both the `price`
 * and `gbp` tokens.
 *
 *   SKU:   sku, mpn, part_no, part number, part_number, product code, product_code
 *   PRICE: price, rrp, cost, £, gbp, price_gbp, price_ex_vat, price_inc_vat
 *
 * Returns `['sku_column_index' => int, 'price_column_index' => int]` on a
 * successful match; `null` when either category has zero candidates — the
 * signal that triggers the D-04 quarantine flow (ambiguous mapping →
 * Filament manual resolve in Plan 05-04).
 *
 * Precedence within a category: the FIRST header in the CSV (left-to-right)
 * that contains ANY of the tokens wins. For `['sku', 'mpn', 'price']` we
 * pick index 0 for sku and index 2 for price.
 */
final class ColumnHeuristicDetector
{
    /** @var list<string> case + whitespace insensitive CONTAINS tokens */
    private const SKU_PATTERNS = [
        'sku', 'mpn', 'part_no', 'part number', 'part_number',
        'product code', 'product_code',
    ];

    /** @var list<string> */
    private const PRICE_PATTERNS = [
        'price', 'rrp', 'cost', '£', 'gbp',
        'price_gbp', 'price_ex_vat', 'price_inc_vat',
    ];

    /**
     * @param  array<int, string>  $headerRow
     * @return array{sku_column_index: int, price_column_index: int}|null
     */
    public function detect(array $headerRow): ?array
    {
        $skuIdx = null;
        $priceIdx = null;

        foreach ($headerRow as $index => $cell) {
            $norm = strtolower(trim((string) $cell));

            if ($skuIdx === null && $this->matchesAny($norm, self::SKU_PATTERNS)) {
                $skuIdx = $index;

                continue;      // same column can't match both
            }

            if ($priceIdx === null && $this->matchesAny($norm, self::PRICE_PATTERNS)) {
                $priceIdx = $index;
            }
        }

        if ($skuIdx === null || $priceIdx === null) {
            return null;                                    // D-04 quarantine trigger
        }

        return [
            'sku_column_index' => $skuIdx,
            'price_column_index' => $priceIdx,
        ];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $normalisedHeader, array $patterns): bool
    {
        if ($normalisedHeader === '') {
            return false;
        }
        foreach ($patterns as $pat) {
            if ($pat === '') {
                continue;
            }
            if (str_contains($normalisedHeader, $pat)) {
                return true;
            }
        }

        return false;
    }
}
