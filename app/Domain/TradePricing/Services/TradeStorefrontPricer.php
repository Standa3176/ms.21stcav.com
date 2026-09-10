<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Services;

use App\Domain\Pricing\Exceptions\SupplierPriceUnusableException;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Products\Models\Product;

/**
 * Quick task 260910-lwv — the price a logged-in trade customer sees on the
 * storefront.
 *
 * ── WHY THIS IS NOT TradeRuleResolver ───────────────────────────────────────
 *
 * TradeRuleResolver (Phase 9) resolves a MARGIN and is right for the ops-side
 * quote flow, where a human reads the number before it goes out. It is wrong
 * for the storefront, because retail here is not rule-priced:
 * pricing:undercut-competitors sets it from competitor data. Measured over the
 * 188 newest products on 2026-09-06 — 121 undercut, 42 floored, 18 margin.
 * 163 of 188 were competitor-driven, and for 159 the rule price sat 10-27%
 * ABOVE the live price. A lower trade margin would therefore have quoted trade
 * customers MORE than the public pays, across most of the catalogue.
 *
 * So the retail price is a hard ceiling here, not an input to ignore.
 *
 * ── THE CALCULATION (operator, 2026-09-09/10) ───────────────────────────────
 *
 *   cost    = buy_price x (1 - trade_cost_adjustment)   feed cost, trade-only
 *   target  = cost + 15%    max trade margin
 *   floor   = cost + 6%     min trade margin — never sell below this
 *   ceiling = retail - 1p   never at or above the public price
 *
 *   price   = min(target, ceiling),  suppressed when that lands below floor
 *
 * Suppression is a real outcome, not a failure: on a product retail has already
 * floored at 6% there is genuinely nothing to give away, and B2BKing falls back
 * to the retail price. Publishing below the floor would sell at a loss;
 * publishing retail-minus-a-token would be dishonest.
 *
 * VAT is delegated entirely to PriceCalculator, which takes ex-VAT cost pennies
 * and returns VAT-INCLUSIVE pennies. No VAT arithmetic lives here, deliberately:
 * the one near-miss on this codebase (PublishProductJob, 260905) was a double
 * strip introduced by a second place doing its own maths.
 */
final class TradeStorefrontPricer
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly TradeCostAdjustmentResolver $adjustments,
    ) {}

    public function price(Product $product): TradePrice
    {
        $feedCostPennies = (int) round(((float) ($product->buy_price ?? 0)) * 100);
        $retailPennies = (int) round(((float) ($product->sell_price ?? 0)) * 100);

        if ($feedCostPennies <= 0) {
            return new TradePrice(null, 'suppressed', 'no_cost');
        }

        if ($retailPennies <= 0) {
            return new TradePrice(null, 'suppressed', 'no_retail_price');
        }

        $adjustment = $this->adjustments->fractionFor($product);
        $costPennies = (int) round($feedCostPennies * (1 - $adjustment));

        if ($costPennies <= 0) {
            // A 100%+ adjustment is a data error, not a free product.
            return new TradePrice(null, 'suppressed', 'adjustment_exceeds_cost', $adjustment);
        }

        try {
            $target = $this->calculator->compute($costPennies, $this->maxMarginBps());
            $floor = $this->calculator->compute($costPennies, $this->minMarginBps());
        } catch (SupplierPriceUnusableException) {
            return new TradePrice(null, 'suppressed', 'cost_unusable', $adjustment);
        }

        $ceiling = $retailPennies - 1;
        $price = min($target, $ceiling);

        if ($price < $floor) {
            return new TradePrice(null, 'suppressed', 'no_headroom_below_retail', $adjustment);
        }

        return new TradePrice(
            $price,
            $price === $target ? 'target' : 'retail_capped',
            null,
            $adjustment,
        );
    }

    private function minMarginBps(): int
    {
        return (int) config('b2b.storefront.min_margin_bps', 600);
    }

    private function maxMarginBps(): int
    {
        return (int) config('b2b.storefront.max_margin_bps', 1500);
    }
}
