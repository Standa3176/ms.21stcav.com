<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Exceptions\NoPricingRuleMatchedException;
use App\Domain\Pricing\Exceptions\SupplierPriceUnusableException;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductVariant;
use App\Domain\Sync\Models\ImportIssue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 Plan 04 Task 1 — shared "given a SKU, recompute its price" core.
 *
 * Extracted from Plan 02's RecomputePriceListener so both the listener AND
 * the bulk RecomputePriceJob (this plan) invoke ONE implementation — no
 * drift between event-driven and batch-driven paths.
 *
 * Call sites:
 *   - RecomputePriceListener::handle()   — persist=true, per SupplierPriceChanged event
 *   - RecomputePriceJob::handle()        — persist from command flag (Plan 04)
 *
 * D-12 dry-run gate (this plan):
 *   When $persist=false the core:
 *     - STILL writes ImportIssue on zero/null buy_price (data-quality fact
 *       exists regardless of dry-run — D-10 + D-11)
 *     - does NOT write products.sell_price / product_variants.sell_price
 *     - does NOT dispatch ProductPriceChanged
 *     - returns a RecomputeOutcome reporting what WOULD have changed so the
 *       bulk command can surface "would update N" in its report
 *
 * D-13 fire-on-diff gate (live path):
 *   Integer-pennies equality compare — no float compare, no percentage-floor.
 *   Identical inputs → identical pennies → Unchanged outcome, no event.
 *
 * Retry policy: this class does NOT retry. The JOB decides retries
 * (tries=3 at the RecomputePriceJob level). The listener also has no
 * retries — a listener job failure bubbles to Horizon's supervisor.
 *
 * Singleton-friendly: stateless, no per-call cache, safe under concurrency.
 * Registered as a singleton in AppServiceProvider for resolution-cost
 * savings during a 15k-SKU bulk batch.
 */
final class PriceRecomputer
{
    public function __construct(
        private readonly RuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * Recompute the final retail price for a single Woo product/variant.
     *
     * @param  int  $wooProductId       Woo's identity key (not the local
     *                                  Product.id — that is internal).
     * @param  int|null  $wooVariationId When non-null, work on the variant
     *                                  row; sell_price is written to
     *                                  product_variants.sell_price.
     * @param  string  $sku             SKU for ImportIssue row + logging.
     * @param  string  $correlationId   Threads into any dispatched event,
     *                                  ImportIssue row, and audit log.
     * @param  bool  $persist           true=live (writes sell_price + emits
     *                                  ProductPriceChanged on diff), false=dry-run
     *                                  (pricing math only; ImportIssue still
     *                                  written for data-quality issues).
     */
    public function recompute(
        int $wooProductId,
        ?int $wooVariationId,
        string $sku,
        string $correlationId,
        bool $persist,
    ): RecomputeOutcome {
        // Thread correlation_id onto the current Context so any child event
        // (ProductPriceChanged via DomainEvent::__construct reads Context::get)
        // inherits the same CID as the original listener/command.
        Context::add('correlation_id', $correlationId);

        // ── Locate the target row (variant takes precedence when present) ────
        $variant = $wooVariationId !== null
            ? ProductVariant::query()->where('woo_variation_id', $wooVariationId)->first()
            : null;

        $product = $variant?->product
            ?? Product::query()->where('woo_product_id', $wooProductId)->first();

        if ($product === null) {
            Log::warning('PriceRecomputer: product not found — skipping', [
                'woo_product_id' => $wooProductId,
                'woo_variation_id' => $wooVariationId,
                'sku' => $sku,
                'correlation_id' => $correlationId,
                'persist' => $persist,
            ]);

            return new RecomputeOutcome(
                kind: RecomputeOutcomeKind::ProductNotFound,
                productId: 0,
                variantId: null,
                oldPennies: null,
                newPennies: null,
                resolutionSource: null,
                marginBasisPoints: null,
            );
        }

        // ── D-10 zero-price guard (first layer — short-circuit before calculator) ──
        $buyPrice = $variant?->buy_price ?? $product->buy_price;
        $buyPennies = $buyPrice === null ? 0 : (int) round(((float) $buyPrice) * 100);

        if ($buyPennies <= 0) {
            // ImportIssue is written in BOTH dry-run and live modes — the issue
            // is a data-quality fact independent of the command flag.
            $this->logImportIssue($sku, $wooProductId, $wooVariationId, $buyPennies, $correlationId);

            return new RecomputeOutcome(
                kind: RecomputeOutcomeKind::ZeroPriceSkipped,
                productId: (int) $product->id,
                variantId: $variant !== null ? (int) $variant->id : null,
                oldPennies: null,
                newPennies: null,
                resolutionSource: null,
                marginBasisPoints: null,
            );
        }

        // ── Resolve margin + compute new price ───────────────────────────────
        try {
            $resolution = $this->resolver->resolve($product);
            $newPennies = $this->calculator->compute($buyPennies, $resolution->marginBasisPoints);
        } catch (SupplierPriceUnusableException) {
            // D-10 second layer — calculator guard. Belt + braces.
            $this->logImportIssue($sku, $wooProductId, $wooVariationId, $buyPennies, $correlationId);

            return new RecomputeOutcome(
                kind: RecomputeOutcomeKind::ZeroPriceSkipped,
                productId: (int) $product->id,
                variantId: $variant !== null ? (int) $variant->id : null,
                oldPennies: null,
                newPennies: null,
                resolutionSource: null,
                marginBasisPoints: null,
            );
        } catch (NoPricingRuleMatchedException $e) {
            Log::error('PriceRecomputer: no pricing rule matched', [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'sku' => $sku,
                'correlation_id' => $correlationId,
                'persist' => $persist,
                'exception' => $e->getMessage(),
            ]);

            return new RecomputeOutcome(
                kind: RecomputeOutcomeKind::NoRuleMatched,
                productId: (int) $product->id,
                variantId: $variant !== null ? (int) $variant->id : null,
                oldPennies: null,
                newPennies: null,
                resolutionSource: null,
                marginBasisPoints: null,
            );
        }

        // ── D-13 integer-pennies equality gate ───────────────────────────────
        $target = $variant ?? $product;
        $oldPennies = $target->sell_price === null
            ? 0
            : (int) round(((float) $target->sell_price) * 100);

        $productIdInt = (int) $product->id;
        $variantIdInt = $variant !== null ? (int) $variant->id : null;
        $marginBps = (int) $resolution->marginBasisPoints;

        if ($oldPennies === $newPennies) {
            // Unchanged — no write, no event in BOTH persist modes.
            return new RecomputeOutcome(
                kind: RecomputeOutcomeKind::Unchanged,
                productId: $productIdInt,
                variantId: $variantIdInt,
                oldPennies: $oldPennies,
                newPennies: $newPennies,
                resolutionSource: $resolution->source,
                marginBasisPoints: $marginBps,
            );
        }

        // Diff detected. In dry-run mode we REPORT the diff but DO NOT write
        // or dispatch — the whole point of D-12.
        if (! $persist) {
            return new RecomputeOutcome(
                kind: RecomputeOutcomeKind::Changed,
                productId: $productIdInt,
                variantId: $variantIdInt,
                oldPennies: $oldPennies,
                newPennies: $newPennies,
                resolutionSource: $resolution->source,
                marginBasisPoints: $marginBps,
            );
        }

        // Live mode — forceFill + saveQuietly keeps activity_log clean for
        // sync-driven writes (Phase 2 convention — only admin Filament edits
        // should land in audit_log for sell_price).
        $target->forceFill([
            'sell_price' => number_format($newPennies / 100, 4, '.', ''),
        ])->saveQuietly();

        ProductPriceChanged::dispatch(
            $productIdInt,
            $variantIdInt,
            $sku,
            $oldPennies,
            $newPennies,
            $marginBps,
            $resolution->source,
        );

        return new RecomputeOutcome(
            kind: RecomputeOutcomeKind::Changed,
            productId: $productIdInt,
            variantId: $variantIdInt,
            oldPennies: $oldPennies,
            newPennies: $newPennies,
            resolutionSource: $resolution->source,
            marginBasisPoints: $marginBps,
        );
    }

    /**
     * D-10 + D-11 — idempotent missing_cost_price logging. Primary matching
     * tuple includes resolved_at=null so a re-triaged-then-re-broken cycle
     * creates a new row instead of mutating an already-closed one.
     */
    private function logImportIssue(
        string $sku,
        int $wooProductId,
        ?int $wooVariationId,
        int $buyPennies,
        string $correlationId,
    ): void {
        ImportIssue::updateOrCreate(
            [
                'sku' => $sku,
                'woo_product_id' => $wooProductId,
                'woo_variation_id' => $wooVariationId,
                'issue_type' => ImportIssue::TYPE_MISSING_COST_PRICE,
                'resolved_at' => null,
            ],
            [
                'detected_at' => now(),
                'last_seen_at' => now(),
                'notes' => "Supplier buy_price is {$buyPennies} pennies (zero/null/negative) — recompute skipped (D-10)",
                'correlation_id' => $correlationId,
            ],
        );
    }
}
