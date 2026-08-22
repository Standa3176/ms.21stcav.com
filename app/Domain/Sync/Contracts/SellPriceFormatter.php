<?php

declare(strict_types=1);

namespace App\Domain\Sync\Contracts;

use App\Domain\Products\Models\Product;

/**
 * 260822-rmo — how a local sell_price becomes a Woo `regular_price` string.
 *
 * WHY AN INTERFACE IN Sync RATHER THAN JUST CALLING PriceCalculator:
 * the VAT basis is a PRICING rule, but the layer graph forbids
 * Sync → Pricing (`Sync: [Foundation, Products, Alerting, Integrations]` in
 * deptrac.yaml / depfile.yaml). The permitted arrow is Pricing → Sync.
 *
 * So Sync declares the contract it needs and Pricing implements it
 * (App\Domain\Pricing\Services\WooRegularPriceFormatter), bound in
 * AppServiceProvider. Sync depends only on its own abstraction, Pricing keeps
 * ownership of the VAT rule, and there is exactly ONE definition of the
 * mapping — duplicating the ex-VAT maths inside Sync to dodge the boundary
 * would be a silent 20% price error waiting to drift apart.
 */
interface SellPriceFormatter
{
    /**
     * The Woo `regular_price` string for this product, or null when it has no
     * usable local price (null / zero / negative — pushing "0.00" over a live
     * price would be worse than doing nothing).
     */
    public function formatForProduct(Product $product): ?string;
}
