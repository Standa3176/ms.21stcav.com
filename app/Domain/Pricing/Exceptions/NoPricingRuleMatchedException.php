<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use RuntimeException;

/**
 * Phase 3 Plan 02 — thrown by RuleResolver when NO rule matches across every
 * layer (brand_category → category → brand → default_tier).
 *
 * In practice this means the catalogue is incomplete: the default_tier seeder
 * was not run OR the product's buy_price falls outside every seeded tier's
 * (tier_min_pennies, tier_max_pennies) window AND no brand/category mapping
 * applies. Both conditions are rare and operational — the catch path in
 * RecomputePriceListener (Plan 02 Task 2) logs the incident and bails out
 * without touching sell_price.
 */
final class NoPricingRuleMatchedException extends RuntimeException
{
    public static function forProduct(int $productId): self
    {
        return new self(
            "No PricingRule matched product_id={$productId} — default tiers may be missing or buy_price out of all tier ranges"
        );
    }
}
