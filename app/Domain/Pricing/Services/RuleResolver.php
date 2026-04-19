<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;

/**
 * Phase 3 Plan 02 Task 1 — D-07 most-specific-wins resolver (PRCE-02).
 *
 * Resolution chain (terminal at first hit):
 *   0. ProductOverride             — D-08, beats every rule
 *   1. brand_category rule         — brand_id AND category_id match
 *   2. category rule               — category_id match
 *   3. brand rule                  — brand_id match
 *   4. default_tier rule           — tier bounds enclose buy_price
 *
 * Within any layer the sort is priority DESC → id ASC (D-07). `active=false`
 * rules are skipped at the query level so they never pollute the sort.
 *
 * Purity contract (T-03-02-02 mitigation, enforced by
 * tests/Unit/Pricing/RuleResolverPurityTest.php):
 *   - NO config() reads — layer ordering and tier math live in the query plan.
 *   - NO clock / random / session / cache / auth reads — two calls on identical
 *     DB state MUST return identical PricingResolution instances.
 *   - DB reads only, via Eloquent queries.
 *
 * When no layer matches, NoPricingRuleMatchedException is thrown. The
 * RecomputePriceListener (Task 2) catches this and logs operationally without
 * touching sell_price (D-10 spirit — never leak a wrong price downstream).
 */
final class RuleResolver
{
    public function resolve(Product $product): PricingResolution
    {
        // ── Layer 0 — ProductOverride (D-08, beats everything) ────────────────
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

        $chain = [];
        $brandId = $product->getPricingBrandId();
        $categoryId = $product->getPricingCategoryId();
        $buyPennies = $product->buy_price === null
            ? 0
            : (int) round(((float) $product->buy_price) * 100);

        // ── Layer 1 — brand_category (both ids must match) ────────────────────
        if ($brandId !== null && $categoryId !== null) {
            $chain[] = 'brand_category';
            $rule = PricingRule::query()
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
                    source: 'brand_category',
                    matchedRuleId: (int) $rule->id,
                    overrideId: null,
                    chain: $chain,
                );
            }
        }

        // ── Layer 2 — category ────────────────────────────────────────────────
        if ($categoryId !== null) {
            $chain[] = 'category';
            $rule = PricingRule::query()
                ->where('scope', PricingRule::SCOPE_CATEGORY)
                ->where('active', true)
                ->where('category_id', $categoryId)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->first();
            if ($rule !== null) {
                return new PricingResolution(
                    marginBasisPoints: (int) $rule->margin_basis_points,
                    source: 'category',
                    matchedRuleId: (int) $rule->id,
                    overrideId: null,
                    chain: $chain,
                );
            }
        }

        // ── Layer 3 — brand ───────────────────────────────────────────────────
        if ($brandId !== null) {
            $chain[] = 'brand';
            $rule = PricingRule::query()
                ->where('scope', PricingRule::SCOPE_BRAND)
                ->where('active', true)
                ->where('brand_id', $brandId)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->first();
            if ($rule !== null) {
                return new PricingResolution(
                    marginBasisPoints: (int) $rule->margin_basis_points,
                    source: 'brand',
                    matchedRuleId: (int) $rule->id,
                    overrideId: null,
                    chain: $chain,
                );
            }
        }

        // ── Layer 4 — default_tier (tier_max NULL means open-ended upper) ─────
        $chain[] = 'default_tier';
        $tierRule = PricingRule::query()
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
                source: 'default_tier',
                matchedRuleId: (int) $tierRule->id,
                overrideId: null,
                chain: $chain,
            );
        }

        throw NoPricingRuleMatchedException::forProduct((int) $product->id);
    }
}
