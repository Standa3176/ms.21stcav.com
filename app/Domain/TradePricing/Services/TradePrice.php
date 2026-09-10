<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Services;

/**
 * Quick task 260910-lwv — outcome of one trade-price calculation.
 *
 * `source` is carried because "what price" is never the whole question — the
 * operator needs to know WHY. A product at `retail_capped` is one where the
 * competitor-driven retail price already sits below cost+15%; a suppressed one
 * publishes nothing and falls back to retail on the storefront.
 */
final readonly class TradePrice
{
    /**
     * @param  int|null  $pennies  VAT-INCLUSIVE, matching products.sell_price and
     *                             Woo's regular_price. Null when suppressed.
     * @param  string  $source  'target' | 'retail_capped' | 'suppressed'
     * @param  string|null  $reason  set only when suppressed
     * @param  float  $costAdjustment  fraction applied to feed cost (0.0 = none)
     */
    public function __construct(
        public ?int $pennies,
        public string $source,
        public ?string $reason = null,
        public float $costAdjustment = 0.0,
    ) {}

    public function isPublishable(): bool
    {
        return $this->pennies !== null && $this->pennies > 0;
    }
}
