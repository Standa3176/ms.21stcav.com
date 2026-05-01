<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Quotes\Exceptions\QuoteLineImmutableException;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Quotes\Services\QuoteLineWriter;
use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|==============================================================================
| Phase 11 — SHIP GATE — PinnedQuotePricesSurviveRuleEditTest (QUOT-02)
|==============================================================================
|
| The single load-bearing CI test for the entire Phase 11 quote-flow milestone.
| Per ROADMAP success criterion 1, every PR must run this assertion:
|
|   GIVEN a draft Quote with N lines snapshotted via QuoteLineWriter,
|   WHEN  the underlying PricingRule.margin_basis_points is mutated,
|   THEN  every line's unit_price_pence_at_quote remains BYTE-IDENTICAL,
|   AND   pricing chain captured at quote-creation time stays unchanged,
|   AND   transitioning quote to status=sent + attempting to mutate
|         line.unit_price_pence_at_quote throws QuoteLineImmutableException.
|
| If this test ever fails, the Phase 11 immutability invariant has been
| broken — the QuoteLine snapshot is no longer immune to PricingRule edits
| and we are violating the customer-facing promise that "the price you saw
| on the quote PDF is the price we will honour."
|
| Skip-on-MySQL-offline parity with Phase 9 / Plan 11-01.
*/

function skipIfMySqlOfflineShipGate(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineShipGate();
    config(['pricing.rounding_mode' => PHP_ROUND_HALF_UP]);
});

it('PHASE 11 SHIP GATE — QuoteLine prices survive PricingRule mutation byte-identical', function (): void {
    // ── Setup ─────────────────────────────────────────────────────────────
    $group = CustomerGroup::factory()->create();

    // 3 SKUs covering 3 different brand+price combinations so the test
    // exercises the resolver path more than once.
    $skus = ['SHIP-001', 'SHIP-002', 'SHIP-003'];
    $brandIds = [1001, 1002, 1003];
    $buyPrices = [100.00, 250.00, 75.50];

    foreach ($skus as $i => $sku) {
        Product::factory()->create([
            'sku' => $sku,
            'buy_price' => $buyPrices[$i],
            'brand_id' => $brandIds[$i],
        ]);
    }

    // Two competing brand rules per brand — priority 200 wins, priority 100
    // is a noise rule that becomes "bait" for the SECOND assertion (mutating
    // the priority-200 winner must NOT cascade into the snapshot).
    $winningRules = [];
    foreach ($brandIds as $i => $brandId) {
        PricingRule::factory()->create([
            'scope' => PricingRule::SCOPE_BRAND,
            'customer_group_id' => $group->id,
            'brand_id' => $brandId,
            'margin_basis_points' => 1500,
            'priority' => 100,
            'active' => true,
        ]);
        $winningRules[$i] = PricingRule::factory()->create([
            'scope' => PricingRule::SCOPE_BRAND,
            'customer_group_id' => $group->id,
            'brand_id' => $brandId,
            'margin_basis_points' => 2000,  // 20% margin
            'priority' => 200,
            'active' => true,
        ]);
    }

    // ── Step 1: Create a draft Quote ──────────────────────────────────────
    $quote = Quote::factory()->create([
        'customer_group_id' => $group->id,
        'status' => Quote::STATUS_DRAFT,
    ]);

    // ── Step 2: Add 3 lines with quantities 1, 5, 10 ──────────────────────
    $writer = app(QuoteLineWriter::class);
    $writer->add($quote, $skus[0], 1);
    $writer->add($quote, $skus[1], 5);
    $writer->add($quote, $skus[2], 10);

    // ── Step 3: CAPTURE the snapshotted prices + chains BEFORE mutation ───
    $quote->refresh();
    $captured = $quote->lines->mapWithKeys(fn ($l) => [$l->sku => [
        'unit' => $l->unit_price_pence_at_quote,
        'total' => $l->line_total_pence_at_quote,
        'matched_rule_id' => $l->product_snapshot['matched_rule_id'] ?? null,
        'chain' => $l->product_snapshot['resolution_chain'] ?? [],
    ]])->all();

    // Sanity — captured prices are non-trivial integers.
    foreach ($captured as $sku => $data) {
        expect($data['unit'])->toBeInt()->toBeGreaterThan(0);
        expect($data['matched_rule_id'])->toBeInt()->toBeGreaterThan(0);
    }

    // ── Step 4: MUTATE every winning PricingRule margin by +500bps ───────
    // After this, if the Quote re-resolved prices live, every line's
    // unit_price_pence_at_quote would jump (5% margin increase).
    foreach ($winningRules as $rule) {
        $rule->margin_basis_points = $rule->margin_basis_points + 500;
        $rule->save();
    }

    // ── Step 5: ASSERT every line's snapshot is byte-identical to capture ─
    $quote->refresh();
    $quote->load('lines');

    foreach ($quote->lines as $line) {
        $original = $captured[$line->sku];

        expect($line->unit_price_pence_at_quote)->toBe(
            $original['unit'],
            sprintf(
                'PHASE 11 SHIP GATE FAILED — line %s unit_price_pence_at_quote drifted from %d to %d after PricingRule edit. QUOT-02 immutability invariant broken.',
                $line->sku,
                $original['unit'],
                $line->unit_price_pence_at_quote,
            ),
        );

        expect($line->line_total_pence_at_quote)->toBe(
            $original['total'],
            sprintf(
                'PHASE 11 SHIP GATE FAILED — line %s line_total_pence_at_quote drifted from %d to %d after PricingRule edit.',
                $line->sku,
                $original['total'],
                $line->line_total_pence_at_quote,
            ),
        );

        expect($line->product_snapshot['matched_rule_id'])->toBe(
            $original['matched_rule_id'],
            'PHASE 11 SHIP GATE FAILED — product_snapshot.matched_rule_id mutated for line '.$line->sku,
        );

        expect($line->product_snapshot['resolution_chain'])->toBe(
            $original['chain'],
            'PHASE 11 SHIP GATE FAILED — product_snapshot.resolution_chain mutated for line '.$line->sku,
        );
    }

    // ── Step 6: After status=sent, direct mutation MUST throw ─────────────
    // Proves the immutability observer also blocks the post-draft mutation
    // path even if a future bulk-import or hand-rolled DB write tries it.
    $quote->status = Quote::STATUS_SENT;
    $quote->sent_at = now();
    $quote->saveQuietly();

    $line = $quote->lines->first();
    $line->unit_price_pence_at_quote = 1;  // attempt forbidden mutation

    expect(fn () => $line->save())->toThrow(
        QuoteLineImmutableException::class,
        'unit_price_pence_at_quote',  // assert the column name appears in the message
    );
})->uses(RefreshDatabase::class);
