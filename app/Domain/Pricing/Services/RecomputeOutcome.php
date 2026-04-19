<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Phase 3 Plan 04 — readonly DTO returned by PriceRecomputer::recompute().
 *
 * Carries enough information for the bulk command's CSV report row AND the
 * listener's audit log line:
 *   - $kind               — see RecomputeOutcomeKind
 *   - $productId          — local Product id (0 when product not found)
 *   - $variantId          — local ProductVariant id (null when parent-only)
 *   - $oldPennies         — pre-recompute sell_price in pennies (null when
 *                           product missing / zero-price skip)
 *   - $newPennies         — post-recompute sell_price in pennies (null on
 *                           zero-price / no-rule / product-not-found)
 *   - $resolutionSource   — 'override' | 'brand_category' | 'category' |
 *                           'brand' | 'default_tier' (null when no rule
 *                           resolved)
 *   - $marginBasisPoints  — margin used (null when no rule resolved)
 *
 * Primitives only — matches DomainEvent convention for cross-boundary
 * serialisation safety. No Eloquent models, no Carbon instances, no closures.
 */
final readonly class RecomputeOutcome
{
    public function __construct(
        public RecomputeOutcomeKind $kind,
        public int $productId,
        public ?int $variantId,
        public ?int $oldPennies,
        public ?int $newPennies,
        public ?string $resolutionSource,
        public ?int $marginBasisPoints,
    ) {}
}
