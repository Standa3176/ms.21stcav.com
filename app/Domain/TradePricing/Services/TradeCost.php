<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Services;

/**
 * Quick task 260910-rsv — the cost the trade pricer should actually use.
 *
 * Carries WHERE the number came from, not just what it is. When a trade price
 * looks wrong the first question is always "which cost did this use" — the
 * feed's, a percentage arrangement, or a manufacturer price list — and that
 * has to be answerable from the preview output rather than by re-deriving it.
 */
final readonly class TradeCost
{
    /**
     * @param  int  $pennies  ex-VAT, matching products.buy_price
     * @param  string  $source  'feed' | 'percentage' | 'price_list'
     * @param  float  $fraction  effective discount off feed cost (0.0 when source is feed)
     */
    public function __construct(
        public int $pennies,
        public string $source,
        public float $fraction = 0.0,
    ) {}
}
