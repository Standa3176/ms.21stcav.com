<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Services;

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\PricingResolution;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;

/**
 * Phase 9 Plan 02 — TRDE-02 decorator over v1 RuleResolver.
 *
 * Resolution chain (terminal at first hit):
 *   0. ProductOverride                        — beats EVERY rule including trade (Pitfall 3 invariant).
 *   1. group + brand + category trade rule    — most specific group rule.
 *   2. group + category trade rule
 *   3. group + brand trade rule
 *   4. group default_tier rule                — flat margin for the group within tier bounds.
 *   5. delegate to base RuleResolver          — falls through to v1 retail behaviour byte-identical.
 *
 * Retail fast-path (when $customerGroupId is null OR 0): immediately delegate
 * to v1's RuleResolver without ANY group-aware work — preserves Phase 3 ship
 * gate byte-identical (Pitfall B1 4-quadrant NULL matrix). The override check
 * still runs in front of the fast-path so the override invariant holds for
 * BOTH retail and trade callers (mirrors v1's Layer-0 semantics).
 *
 * Within each layer the sort is `priority DESC, id ASC` mirroring v1.
 * Group-scoped rules default to `priority + 100` (D-03) so when a tied retail
 * rule and trade rule could apply, the trade rule wins via the priority bias —
 * but rules at the same group + scope still tiebreak by `priority DESC, id ASC`.
 *
 * Purity contract: NO config / clock / random / cache / auth reads. Two calls
 * on identical DB state MUST return PricingResolution instances with equal
 * field values (TradeRuleResolverPurityTest enforces).
 *
 * v1 callers (PriceRecomputer, SimulatedImpactCalculator, RuleExplorer,
 * ComputeMarginSuggestionJob, CreateWooProductJob) reach v1's RuleResolver
 * directly and stay completely ignorant of customer groups. Only NEW Phase 9
 * + Phase 11 (E2 Quote flow) callers reach for TradeRuleResolver — Phase 3
 * golden-fixture parity is preserved by definition.
 */
final class TradeRuleResolver
{
    public function __construct(
        private readonly RuleResolver $base,
    ) {}

    public function resolve(Product $product, ?int $customerGroupId = null): PricingResolution
    {
        // ── Layer 0 — ProductOverride beats EVERYTHING (Pitfall 3 invariant) ──
        $override = ProductOverride::query()
            ->where('product_id', $product->id)
            ->first();

        if ($override !== null) {
            return new PricingResolution(
                marginBasisPoints: (int) $override->margin_basis_points,
                source: 'override',
                matchedRuleId: null,
                overrideId: (int) $override->id,
                chain: ['override'],
            );
        }

        // ── Retail fast-path: delegate to v1 untouched (Pitfall B1) ───────────
        if ($customerGroupId === null || $customerGroupId === 0) {
            return $this->base->resolve($product);
        }

        $brandId = $product->getPricingBrandId();
        $categoryId = $product->getPricingCategoryId();
        $chain = [];

        // ── Layer 1 — group + brand + category ────────────────────────────────
        if ($brandId !== null && $categoryId !== null) {
            $chain[] = 'trade_brand_category';
            $rule = PricingRule::query()
                ->where('customer_group_id', $customerGroupId)
                ->where('scope', PricingRule::SCOPE_BRAND_CATEGORY)
                ->where('active', true)
                ->where('brand_id', $brandId)
                ->where('category_id', $categoryId)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->first();
            if ($rule !== null) {
                return new PricingResolution(
                    marginBasisPoints: (int) $rule->margin_basis_points,
                    source: 'trade_brand_category',
                    matchedRuleId: (int) $rule->id,
                    overrideId: null,
                    chain: $chain,
                );
            }
        }

        // ── Layer 2 — group + category ────────────────────────────────────────
        if ($categoryId !== null) {
            $chain[] = 'trade_category';
            $rule = PricingRule::query()
                ->where('customer_group_id', $customerGroupId)
                ->where('scope', PricingRule::SCOPE_CATEGORY)
                ->where('active', true)
                ->where('category_id', $categoryId)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->first();
            if ($rule !== null) {
                return new PricingResolution(
                    marginBasisPoints: (int) $rule->margin_basis_points,
                    source: 'trade_category',
                    matchedRuleId: (int) $rule->id,
                    overrideId: null,
                    chain: $chain,
                );
            }
        }

        // ── Layer 3 — group + brand ───────────────────────────────────────────
        if ($brandId !== null) {
            $chain[] = 'trade_brand';
            $rule = PricingRule::query()
                ->where('customer_group_id', $customerGroupId)
                ->where('scope', PricingRule::SCOPE_BRAND)
                ->where('active', true)
                ->where('brand_id', $brandId)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->first();
            if ($rule !== null) {
                return new PricingResolution(
                    marginBasisPoints: (int) $rule->margin_basis_points,
                    source: 'trade_brand',
                    matchedRuleId: (int) $rule->id,
                    overrideId: null,
                    chain: $chain,
                );
            }
        }

        // ── Layer 4 — group default_tier (tier_max NULL = open-ended upper) ───
        $buyPennies = $product->buy_price === null
            ? 0
            : (int) round(((float) $product->buy_price) * 100);

        $chain[] = 'trade_default_tier';
        $tierRule = PricingRule::query()
            ->where('customer_group_id', $customerGroupId)
            ->where('scope', PricingRule::SCOPE_DEFAULT_TIER)
            ->where('active', true)
            ->where('is_default_tier', true)
            ->where('tier_min_pennies', '<=', $buyPennies)
            ->where(function ($q) use ($buyPennies) {
                $q->whereNull('tier_max_pennies')
                    ->orWhere('tier_max_pennies', '>=', $buyPennies);
            })
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();
        if ($tierRule !== null) {
            return new PricingResolution(
                marginBasisPoints: (int) $tierRule->margin_basis_points,
                source: 'trade_default_tier',
                matchedRuleId: (int) $tierRule->id,
                overrideId: null,
                chain: $chain,
            );
        }

        // ── Layer 5 — fall through to v1 retail (byte-identical) ──────────────
        return $this->base->resolve($product);
    }
}
