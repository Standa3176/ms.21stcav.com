<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Services;

use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\TradeCostAdjustment;

/**
 * Quick task 260910-lwv — resolves the trade-only discount off feed cost.
 *
 * Most specific wins: an explicit SKU row beats a brand row, even when the SKU
 * row is the SMALLER discount — a per-SKU entry is a deliberate override of the
 * brand arrangement, not an accident.
 *
 * Within one scope the LARGEST discount wins. Two overlapping brand rows means
 * somebody negotiated twice, and the buyer should get the better of them rather
 * than whichever row happens to sort first.
 *
 * Memoised per instance: the sync command walks thousands of products while the
 * adjustment set is tiny, so the whole in-force set loads once.
 */
final class TradeCostAdjustmentResolver
{
    /** @var array<int, TradeCostAdjustment>|null */
    private ?array $loaded = null;

    /** Fraction off feed cost, e.g. 0.125. Zero when nothing applies. */
    public function fractionFor(Product $product): float
    {
        $sku = strtolower(trim((string) $product->sku));
        $brandId = $product->brand_id === null ? null : (int) $product->brand_id;

        $bySku = null;
        $byBrand = 0.0;

        foreach ($this->all() as $row) {
            if ($sku !== '' && $row->sku !== null && strtolower(trim($row->sku)) === $sku) {
                $bySku = max($bySku ?? 0.0, $row->fraction());

                continue;
            }

            if ($brandId !== null && $row->brand_id === $brandId) {
                $byBrand = max($byBrand, $row->fraction());
            }
        }

        return $bySku ?? $byBrand;
    }

    /** Drop the memo so a later call re-reads the table. */
    public function flush(): void
    {
        $this->loaded = null;
    }

    /** @return array<int, TradeCostAdjustment> */
    private function all(): array
    {
        if ($this->loaded === null) {
            $this->loaded = TradeCostAdjustment::query()->inForce()->get()->all();
        }

        return $this->loaded;
    }
}
