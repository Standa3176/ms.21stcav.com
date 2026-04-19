<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Phase 3 Plan 03 Task 3 — readonly DTO produced by SimulatedImpactCalculator.
 *
 * Carries one row of "what WOULD change if this PricingRule were saved" —
 * consumed by the Filament Simulated Impact page Table and (in Plan 04) by
 * the `pricing:recompute --all` bulk command's CSV report.
 *
 * Primitives only: integer pennies + strings. No Eloquent, no float, no
 * percentage representation (consumers format basis-points to % at the UI
 * boundary).
 */
final readonly class SimulatedImpactRow
{
    /**
     * @param  int  $productId       products.id
     * @param  int|null  $variantId  product_variants.id — null for simple-product rows
     * @param  string  $sku          product_variants.sku when variant row, else products.sku
     * @param  int  $currentPennies  stored products.sell_price × 100 (or 0 if null)
     * @param  int  $proposedPennies PriceCalculator::compute() output under the proposed rule
     * @param  int  $deltaPennies    proposedPennies − currentPennies (signed)
     * @param  string  $resolutionSource  'override' | 'brand_category' | 'category' | 'brand' | 'default_tier'
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public string $sku,
        public int $currentPennies,
        public int $proposedPennies,
        public int $deltaPennies,
        public string $resolutionSource,
    ) {}
}
