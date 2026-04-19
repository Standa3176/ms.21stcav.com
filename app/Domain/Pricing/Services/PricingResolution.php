<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

/**
 * Phase 3 Plan 02 — readonly DTO returned by RuleResolver::resolve().
 *
 * Carries the resolved margin (basis points), the layer it came from (so the
 * Filament rule explorer in Plan 03 can badge "brand_category / category /
 * brand / default_tier / override"), the originating rule or override id for
 * drill-down, and the full ordered chain of layers that were attempted —
 * used to render the "brand+cat → brand → cat → default" trail.
 *
 * Primitives only: no Eloquent models, no collections, no closures. The
 * pricing manager's UI only needs to know "why did this price come out this
 * way"; that answer is the margin + source string + chain array.
 */
final readonly class PricingResolution
{
    /**
     * @param  int  $marginBasisPoints  e.g. 2200 = 22.00% — fed unchanged into PriceCalculator::compute()
     * @param  string  $source  'override' | 'brand_category' | 'category' | 'brand' | 'default_tier'
     * @param  int|null  $matchedRuleId  pricing_rules.id that won; null when $source === 'override'
     * @param  int|null  $overrideId  product_overrides.id that won; non-null only when $source === 'override'
     * @param  array<int, string>  $chain  ordered candidate layers attempted, matching resolver walk order
     */
    public function __construct(
        public int $marginBasisPoints,
        public string $source,
        public ?int $matchedRuleId,
        public ?int $overrideId,
        public array $chain,
    ) {}
}
