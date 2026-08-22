<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Contracts\SellPriceFormatter;

/**
 * 260822-rmo — the ONE definition of "local sell_price → Woo regular_price".
 *
 * Product.sell_price is VAT-INCLUSIVE. Woo receives it inc-VAT by default and
 * ex-VAT only when the store is configured that way
 * (services.woo.push_prices_ex_vat), matching what PushPriceChangeToWoo and
 * PublishProductJob::buildCreatePayload have always done. A divergent basis
 * between the event-driven push and the nightly reconciler would be a silent
 * 20% error on every reconciled product — hence one implementation, injected
 * into both via the Sync-owned SellPriceFormatter contract (the layer graph
 * allows Pricing → Sync, never the reverse).
 */
final class WooRegularPriceFormatter implements SellPriceFormatter
{
    public function __construct(private readonly PriceCalculator $calculator) {}

    public function formatForProduct(Product $product): ?string
    {
        if ($product->sell_price === null) {
            return null;
        }

        $pennies = (int) round(((float) $product->sell_price) * 100);

        if ($pennies <= 0) {
            return null;
        }

        return $this->fromPennies($pennies);
    }

    /**
     * VAT-inclusive pennies → the Woo `regular_price` string.
     *
     * Used directly by PushPriceChangeToWoo, which works from the event's
     * newPennies rather than re-reading the product.
     */
    public function fromPennies(int $vatInclusivePennies): string
    {
        $pennies = (bool) config('services.woo.push_prices_ex_vat', false)
            ? $this->calculator->stripVat($vatInclusivePennies)
            : $vatInclusivePennies;

        return number_format($pennies / 100, 2, '.', '');
    }
}
