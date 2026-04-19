<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Listeners;

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
use App\Domain\Pricing\Exceptions\SupplierPriceUnusableException;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Events\SupplierPriceChanged;
use App\Domain\Sync\Models\ImportIssue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 Plan 02 Task 2 — subscribes to Phase 2's SupplierPriceChanged and
 * owns the price-recompute pipeline.
 *
 * Pipeline:
 *   SupplierPriceChanged
 *     → load Product (or ProductVariant for variant-scoped events)
 *     → guard: buy_price ≤ 0 → ImportIssue(missing_cost_price), return (D-10)
 *     → RuleResolver::resolve() → PricingResolution
 *     → PriceCalculator::compute(buy_pennies, marginBasisPoints) → newPennies
 *     → oldPennies = stored sell_price × 100 (integer compare only — D-13)
 *     → if newPennies !== oldPennies: forceFill+saveQuietly, dispatch ProductPriceChanged
 *     → if equal: noop (no event — D-13 fire-on-diff only)
 *
 * Queue choice (D — Claude's Discretion in 03-CONTEXT.md): `default`. The
 * `sync-woo-push` queue is reserved for the Phase 2 Woo PUT emitted in
 * response to ProductPriceChanged — putting the recompute there would
 * saturate the Woo 100/min rate limit with pricing math that belongs off-path.
 *
 * saveQuietly + forceFill pattern is the Phase 2 convention — it skips the
 * LogsActivity trait's model-event subscribers so sync-driven sell_price
 * changes don't pollute audit_log (only admin Filament edits should).
 *
 * Idempotency on ImportIssue (D-11): updateOrCreate matches on
 * (sku, woo_product_id, woo_variation_id, issue_type, resolved_at IS NULL)
 * so a daily zero-price sync bumps last_seen_at instead of piling up rows.
 *
 * NoPricingRuleMatchedException (rare — default tiers not seeded OR
 * catalogue buy_price outside all tier ranges AND no brand/category rule):
 * logged at ERROR and returns without touching sell_price. The
 * DefaultPricingTierSeeder seeds the £500+ open-ended tier specifically so
 * this path is effectively unreachable under normal operations.
 */
final class RecomputePriceListener implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    public function handle(SupplierPriceChanged $event): void
    {
        // Thread correlation_id onto the current Context so any child events
        // dispatched from here (ProductPriceChanged via DomainEvent::__construct
        // reads Context::get('correlation_id')) inherit the same CID.
        Context::add('correlation_id', $event->correlationId);

        // ── Locate the target row (variant takes precedence when present) ────
        $variant = $event->wooVariationId !== null
            ? ProductVariant::query()->where('woo_variation_id', $event->wooVariationId)->first()
            : null;

        $product = $variant?->product
            ?? Product::query()->where('woo_product_id', $event->wooProductId)->first();

        if ($product === null) {
            Log::warning('RecomputePriceListener: product not found — skipping', [
                'woo_product_id' => $event->wooProductId,
                'woo_variation_id' => $event->wooVariationId,
                'sku' => $event->sku,
                'correlation_id' => $event->correlationId,
            ]);

            return;
        }

        // ── D-10 zero-price guard (first layer — short-circuit before calculator) ──
        $buyPrice = $variant?->buy_price ?? $product->buy_price;
        $buyPennies = $buyPrice === null ? 0 : (int) round(((float) $buyPrice) * 100);

        if ($buyPennies <= 0) {
            $this->logImportIssue($event, $buyPennies);

            return;
        }

        // ── Resolve margin + compute new price ───────────────────────────────
        try {
            $resolution = $this->resolver->resolve($product);
            $newPennies = $this->calculator->compute($buyPennies, $resolution->marginBasisPoints);
        } catch (SupplierPriceUnusableException) {
            // D-10 second layer — calculator guard. Belt + braces.
            $this->logImportIssue($event, $buyPennies);

            return;
        } catch (NoPricingRuleMatchedException $e) {
            Log::error('RecomputePriceListener: no pricing rule matched', [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'sku' => $event->sku,
                'correlation_id' => $event->correlationId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        // ── D-13 integer-pennies equality gate — fire ONLY on diff ───────────
        $target = $variant ?? $product;
        $oldPennies = $target->sell_price === null
            ? 0
            : (int) round(((float) $target->sell_price) * 100);

        if ($oldPennies === $newPennies) {
            return;
        }

        // forceFill + saveQuietly keeps activity_log clean for sync-driven writes.
        $target->forceFill([
            'sell_price' => number_format($newPennies / 100, 4, '.', ''),
        ])->saveQuietly();

        ProductPriceChanged::dispatch(
            $product->id,
            $variant?->id,
            $event->sku,
            $oldPennies,
            $newPennies,
            $resolution->marginBasisPoints,
            $resolution->source,
        );
    }

    /**
     * D-10 + D-11 — idempotent missing_cost_price logging. Primary matching
     * tuple includes resolved_at=null so a re-triaged-then-re-broken cycle
     * creates a new row instead of mutating an already-closed one.
     */
    private function logImportIssue(SupplierPriceChanged $event, int $buyPennies): void
    {
        ImportIssue::updateOrCreate(
            [
                'sku' => $event->sku,
                'woo_product_id' => $event->wooProductId,
                'woo_variation_id' => $event->wooVariationId,
                'issue_type' => ImportIssue::TYPE_MISSING_COST_PRICE,
                'resolved_at' => null,
            ],
            [
                'detected_at' => now(),
                'last_seen_at' => now(),
                'notes' => "Supplier buy_price is {$buyPennies} pennies (zero/null/negative) — recompute skipped (D-10)",
                'correlation_id' => $event->correlationId,
            ],
        );
    }
}
