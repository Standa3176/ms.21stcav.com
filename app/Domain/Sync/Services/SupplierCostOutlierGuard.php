<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

/**
 * Quick task 261027-duz — automatic supplier-cost outlier guard (pure).
 *
 * Refuses to adopt a cheapest price that is less than 1/ratio of the LOWER
 * median of the OTHER suppliers' prices for the same match key. The classic
 * cause is a supplier SKU collision: Midwich's code 1301222 is an unrelated
 * £7.20 item, while Nimans' 1301222 is the real £88.20 Yealink T74U.
 *
 * WHY THE LOWER MEDIAN: with an even number of others we take the lower of the
 * two middle values, `$others[intdiv(count - 1, 2)]`. That keeps the harmless
 * "cheap price agreed by another supplier, one expensive odd-one-out" pattern
 * from firing — e.g. NIMANS 5.00 chosen, others [5.50, 50.00]: lower median =
 * 5.50, and 5.00 is not < 5.50/3, so nothing fires. Only a cheapest price that
 * NO other supplier comes near trips the guard.
 *
 * No container, no DB — every input is passed in. Callers decide what to do
 * with a hit (supplier:db-sync holds the existing buy_price + raises an
 * ImportIssue; hydrate-from-offers leaves buy_price untouched).
 */
final class SupplierCostOutlierGuard
{
    /**
     * @param  array<string, float|int|string>  $pricesBySupplier  supplier_id => that supplier's lowest price for the key (chosen included)
     * @return array{chosen_supplier_id:string, chosen_price:float, median_of_others:float, ratio:float, others:array<string, float>}|null
     */
    public static function detect(array $pricesBySupplier, string $chosenSupplierId, float $ratio): ?array
    {
        if ($ratio <= 1.0) {
            return null;
        }

        $prices = [];
        foreach ($pricesBySupplier as $sid => $price) {
            $p = (float) $price;
            if ($p > 0) {
                $prices[(string) $sid] = $p;
            }
        }

        if (! isset($prices[$chosenSupplierId])) {
            return null;
        }

        $chosenPrice = $prices[$chosenSupplierId];
        $others = $prices;
        unset($others[$chosenSupplierId]);

        if ($others === []) {
            return null;
        }

        asort($others);
        $sorted = array_values($others);
        $median = $sorted[intdiv(count($sorted) - 1, 2)];

        if (! ($chosenPrice < $median / $ratio)) {
            return null;
        }

        return [
            'chosen_supplier_id' => $chosenSupplierId,
            'chosen_price' => $chosenPrice,
            'median_of_others' => $median,
            'ratio' => $ratio,
            'others' => $others,
        ];
    }
}
