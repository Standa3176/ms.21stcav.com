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
 * The ceiling additionally enforces a MINIMUM discount off standard
 * (b2b.storefront.min_discount_pct), so a trade price is either worth having
 * or is not published at all — see the note at the ceiling calculation.
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

        $cost = $this->adjustments->resolve($product, $feedCostPennies);
        $costPennies = $cost->pennies;
        $adjustment = $cost->fraction;

        if ($costPennies <= 0) {
            return new TradePrice(null, 'suppressed', 'cost_unusable', $adjustment);
        }

        try {
            $target = $this->calculator->compute($costPennies, $this->maxMarginBps());
            $floor = $this->calculator->compute($costPennies, $this->minMarginBps());
        } catch (SupplierPriceUnusableException) {
            return new TradePrice(null, 'suppressed', 'cost_unusable', $adjustment);
        }

        // 260910-rsv — the trade price must be MEANINGFULLY below standard.
        //
        // Without this, every product where cost+15% exceeds retail landed on
        // `retail - 1p` by construction — all 458 of them on the first live
        // preview. A trade customer shown GBP 2,787.60 against a public
        // GBP 2,787.61 is being insulted, not served, and it is worse than
        // showing no trade price at all: B2BKing falls back to retail, which
        // is the honest outcome.
        $ceiling = min(
            $retailPennies - 1,
            (int) floor($retailPennies * (1 - $this->minDiscountFraction())),
        );

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

    /**
     * Smallest discount off standard worth publishing, as a fraction.
     *
     * Set to 0 to restore the pre-260910-rsv behaviour, where a trade price
     * could be a single penny below standard.
     */
    private function minDiscountFraction(): float
    {
        $pct = (float) config('b2b.storefront.min_discount_pct', 2.0);

        return max(0.0, min(0.9, $pct / 100));
    }
}
