<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;

/**
 * Phase 12.1 Plan 03 (D-05) — read-only SKU -> stored buy_price (ex VAT) lookup.
 *
 * Lives in Pricing so the CRM domain can obtain product cost without importing
 * Products (CRM -> Products is banned by deptrac). Variants win over products.
 * Exactly two queries regardless of the number of SKUs.
 */
final class OrderLineCostLookup
{
    /**
     * @param  array<int, mixed>  $skus
     * @return array<string, ?string> sku => buy_price (decimal string) or null when unknown / null / <= 0
     */
    public function buyPricesForSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(
            array_map(fn ($s) => trim((string) $s), $skus),
            fn (string $s) => $s !== '',
        )));
        if ($skus === []) {
            return [];
        }

        $result = array_fill_keys($skus, null);

        $found = [];
        foreach (ProductVariant::query()->whereIn('sku', $skus)->get(['sku', 'buy_price']) as $v) {
            $found[(string) $v->sku] = true;
            $result[(string) $v->sku] = $this->usable($v->buy_price);
        }

        $remaining = array_values(array_diff($skus, array_keys($found)));
        if ($remaining !== []) {
            foreach (Product::query()->whereIn('sku', $remaining)->get(['sku', 'buy_price']) as $p) {
                $result[(string) $p->sku] = $this->usable($p->buy_price);
            }
        }

        return $result;
    }

    private function usable(mixed $buyPrice): ?string
    {
        if ($buyPrice === null || (float) $buyPrice <= 0) {
            return null;
        }

        return (string) $buyPrice;
    }
}
