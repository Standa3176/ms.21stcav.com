<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Services;

use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\TradeCostAdjustment;

/**
 * Quick task 260910-lwv, extended by 260910-rsv — resolves the cost the trade
 * pricer should use, which is not always the cost the feed reports.
 *
 * Most specific wins: an explicit SKU row beats a brand row, even when the SKU
 * row is the LESS generous of the two — a per-SKU entry is a deliberate
 * override, not an accident.
 *
 * Within one scope the cheapest resulting cost wins. Two overlapping brand
 * rows means somebody negotiated twice, and the buyer should get the better of
 * them rather than whichever row happens to sort first.
 *
 * An `absolute_cost` row (a manufacturer price list) and an `adjustment_pct`
 * row (a "N% off whatever they quote" arrangement) compete on the same footing
 * once both are reduced to pennies, so a price list and a blanket percentage
 * can coexist without either needing to know about the other.
 *
 * Memoised per instance: the sync walks thousands of products while the
 * adjustment set is tiny, so the whole in-force set loads once.
 */
final class TradeCostAdjustmentResolver
{
    /** @var array<int, TradeCostAdjustment>|null */
    private ?array $loaded = null;

    /**
     * The cost to price trade from, given what the feed says.
     *
     * Returns the feed cost untouched when nothing applies — the common case,
     * and the reason retail is never affected by any of this.
     */
    public function resolve(Product $product, int $feedCostPennies): TradeCost
    {
        if ($feedCostPennies <= 0) {
            return new TradeCost($feedCostPennies, 'feed');
        }

        $sku = strtolower(trim((string) $product->sku));
        $brandId = $product->brand_id === null ? null : (int) $product->brand_id;

        $bySku = null;
        $byBrand = null;

        foreach ($this->all() as $row) {
            $pennies = $this->pennies($row, $feedCostPennies);
            if ($pennies === null) {
                continue;
            }

            if ($sku !== '' && $row->sku !== null && strtolower(trim($row->sku)) === $sku) {
                $bySku = $this->cheaper($bySku, [$pennies, $row]);

                continue;
            }

            if ($brandId !== null && $row->brand_id === $brandId) {
                $byBrand = $this->cheaper($byBrand, [$pennies, $row]);
            }
        }

        $winner = $bySku ?? $byBrand;
        if ($winner === null) {
            return new TradeCost($feedCostPennies, 'feed');
        }

        [$pennies, $row] = $winner;

        // A row that makes trade cost MORE than the feed is a data error, not a
        // negotiation. Ignore it rather than quietly pricing trade above retail.
        if ($pennies >= $feedCostPennies) {
            return new TradeCost($feedCostPennies, 'feed');
        }

        return new TradeCost(
            $pennies,
            $row->absolute_cost !== null ? 'price_list' : 'percentage',
            ($feedCostPennies - $pennies) / $feedCostPennies,
        );
    }

    /** Drop the memo so a later call re-reads the table. */
    public function flush(): void
    {
        $this->loaded = null;
    }

    /** Resolve one row to ex-VAT pennies, or null when it carries neither figure. */
    private function pennies(TradeCostAdjustment $row, int $feedCostPennies): ?int
    {
        if ($row->absolute_cost !== null) {
            $p = (int) round(((float) $row->absolute_cost) * 100);

            return $p > 0 ? $p : null;
        }

        if ($row->adjustment_pct !== null) {
            $fraction = ((float) $row->adjustment_pct) / 100;

            // A 100%+ discount is a data error, not a free product.
            if ($fraction <= 0 || $fraction >= 1) {
                return null;
            }

            return (int) round($feedCostPennies * (1 - $fraction));
        }

        return null;
    }

    /**
     * @param  array{0:int,1:TradeCostAdjustment}|null  $current
     * @param  array{0:int,1:TradeCostAdjustment}  $candidate
     * @return array{0:int,1:TradeCostAdjustment}
     */
    private function cheaper(?array $current, array $candidate): array
    {
        return ($current === null || $candidate[0] < $current[0]) ? $candidate : $current;
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
