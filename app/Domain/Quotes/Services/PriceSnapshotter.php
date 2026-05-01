<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Services;

use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Models\Quote;
use App\Domain\TradePricing\Services\TradeRuleResolver;

/**
 * Phase 11 Plan 02 — Price snapshotter (QUOT-01 + QUOT-02).
 *
 * SOLE call site within app/Domain/Quotes/ for line-price resolution. Composes:
 *
 *   1. TradeRuleResolver::resolveForQuote(sku, customer_group_id) — Phase 9
 *      decorator returns the matched margin BPS + source + matched_rule_id +
 *      resolution chain. The decorator itself never produces an integer pence
 *      number; it returns a PricingResolution DTO with margin info.
 *
 *   2. PriceCalculator::compute(buyPricePence, marginBps) — Phase 3 ship-gate
 *      service produces the integer-pennies VAT-INCLUSIVE retail price (A1
 *      LOCKED — Phase 11 stores VAT-INCLUSIVE pence at the column level).
 *
 *   3. Constructs the immutable QuoteLine row data + `product_snapshot` JSON
 *      capturing name + brand_id + category_id + matched_rule_id + chain +
 *      snapshot_at timestamp for auditability. SUPPLIER PRICE IS NEVER
 *      SNAPSHOTTED (T-11-02-05 mitigation — PDF reads only this snapshot).
 *
 * Returned array shape is what `QuoteLine::create()` expects — the single
 * caller (QuoteLineWriter) passes it straight through. Plan 11-02's two
 * observers (immutability + total recompute) take it from there.
 *
 * Contract guarantees (locked by PriceSnapshotterTest):
 *   - unit_price_pence_at_quote IS int (Pitfall 1 — never decimal/float)
 *   - unit_price_pence_at_quote IS VAT-INCLUSIVE (A1 D-13)
 *   - line_total_pence_at_quote == unit_price_pence_at_quote * quantity_int
 *   - product_snapshot.matched_rule_id == resolution.matchedRuleId
 *   - product_snapshot.resolution_chain == resolution.chain (ordered)
 *   - customer_group_id null on Quote falls through TradeRuleResolver retail
 *     fast-path verbatim (Phase 9 Pitfall B1)
 */
final class PriceSnapshotter
{
    public function __construct(
        private readonly TradeRuleResolver $resolver,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * Build the immutable per-line data array for a fresh QuoteLine.
     *
     * NOTE on $sortOrder: the writer (QuoteLineWriter) computes the next
     * sort_order from MAX(quote_lines.sort_order)+10 and passes it in. The
     * snapshotter doesn't query the DB for sort order — keeps this service
     * single-responsibility and easier to unit-test.
     *
     * @return array{
     *   quote_id: string,
     *   sku: string,
     *   quantity_int: int,
     *   unit_price_pence_at_quote: int,
     *   line_total_pence_at_quote: int,
     *   product_snapshot: array<string, mixed>,
     *   sort_order: int,
     * }
     */
    public function buildLine(Quote $quote, string $sku, int $quantity, int $sortOrder = 0): array
    {
        // ── Resolve margin via Phase 9 decorator (additive resolveForQuote) ──
        $resolution = $this->resolver->resolveForQuote($sku, $quote->customer_group_id);

        // Need the product row again for buy_price + product_snapshot context.
        // The resolver already loaded it via firstOrFail — fetch fresh here so
        // the snapshot captures the canonical row state at quote-creation time
        // (and so this service stays composable without leaking the resolver's
        // internal product instance).
        $product = Product::query()->where('sku', $sku)->firstOrFail();

        // Buy price → pennies. Mirror Phase 9 TradeRuleResolver::resolve()
        // exactly so the calculator input is identical to what the resolver
        // saw when picking the tier rule (Layer 4) — no float drift.
        $buyPennies = $product->buy_price === null
            ? 0
            : (int) round(((float) $product->buy_price) * 100);

        // ── Compute integer-pennies VAT-INCLUSIVE retail (A1 LOCKED) ──
        $unitPriceIncVat = $this->calculator->compute(
            $buyPennies,
            $resolution->marginBasisPoints,
        );

        return [
            'quote_id' => $quote->id,
            'sku' => $sku,
            'quantity_int' => $quantity,
            'unit_price_pence_at_quote' => $unitPriceIncVat,
            'line_total_pence_at_quote' => $unitPriceIncVat * $quantity,
            'product_snapshot' => [
                'name' => $product->name,
                'brand_id' => $product->brand_id,
                'category_id' => $product->category_id,
                'matched_rule_id' => $resolution->matchedRuleId,
                'override_id' => $resolution->overrideId,
                'resolution_source' => $resolution->source,
                'resolution_chain' => $resolution->chain,
                'snapshot_at' => now()->toIso8601String(),
            ],
            'sort_order' => $sortOrder,
        ];
    }
}
