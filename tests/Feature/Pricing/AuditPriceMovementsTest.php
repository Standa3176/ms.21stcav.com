<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;

/*
|--------------------------------------------------------------------------
| 260824-p3f — auditing a day's sell_price movements
|--------------------------------------------------------------------------
|
| "Prices moved" is not "prices moved correctly". 587 products repriced on
| 2026-08-23 and nothing had ever checked them against the pricing contract.
|
| The audit's load-bearing assertion is the minimum-margin floor, because it is
| the only one that survives the passage of time: competitor prices are
| overwritten in place, so yesterday's inputs are gone and a branch mismatch has
| an innocent explanation (the competitor moved). A snapshot row carries
| buy_price NEXT TO the sell_price recorded the same day, so "was this at or
| above cost + the agreed margin?" is answerable exactly, for that day, forever.
|
| Floor: 6% (config competitor.min_margin_floor_bps, operator decision
| 2026-05-24). PriceCalculator::compute treats margin as markup ON cost, then
| adds VAT — so £100 cost at the 6% floor is £100 × 1.06 × 1.2 = £127.20.
*/

beforeEach(function (): void {
    config([
        'competitor.min_margin_floor_bps' => 600,
        'competitor.max_margin_ceiling_bps' => 5000,
        'competitor.beat_by_pennies' => 1,
        'pricing.vat_basis_points' => 2000,
    ]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 4000,
    ]);
});

/** Two daily snapshots for one product: yesterday's price, then today's. */
function snapshotMovement(string $sku, float $cost, float $wasSell, float $nowSell): Product
{
    $product = Product::factory()->create([
        'sku' => $sku,
        'type' => 'simple',
        'buy_price' => $cost,
        'sell_price' => $nowSell,
    ]);

    foreach ([[now()->subDay(), $wasSell], [now(), $nowSell]] as [$day, $price]) {
        ProductPriceSnapshot::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'woo_status' => 'publish',
            'sell_price' => $price,
            'buy_price' => $cost,
            'stock_quantity' => 5,
            'recorded_at' => $day->toDateString(),
        ]);
    }

    return $product;
}

it('passes a movement that respects cost and the minimum-margin floor', function (): void {
    // £100 cost, floor price £127.20 — landing exactly on the floor is legal.
    snapshotMovement('FLOOR-OK', 100.00, 150.00, 127.20);

    $this->artisan('pricing:audit-movements')
        ->expectsOutputToContain('PASS')
        ->assertExitCode(0);
});

it('fails the run when a product is priced below the minimum-margin floor', function (): void {
    // £120.00 against a £127.20 floor — a 4.29% margin where 6% was agreed.
    snapshotMovement('FLOOR-BREACH', 100.00, 150.00, 120.00);

    $this->artisan('pricing:audit-movements')
        ->expectsOutputToContain('BELOW FLOOR')
        ->expectsOutputToContain('FAIL')
        ->assertExitCode(1);
});

it('separates below-cost from merely-below-floor', function (): void {
    // £110 inc VAT strips to £91.67 ex VAT against a £100 cost — a LOSS, which
    // is a different and worse fault than a thin margin, and is named as such.
    snapshotMovement('LOSS-MAKER', 100.00, 150.00, 110.00);

    $this->artisan('pricing:audit-movements')
        ->expectsOutputToContain('BELOW COST')
        ->assertExitCode(1);
});

it('flags a margin above the ceiling without failing the run', function (): void {
    // 2026-08-09 incident shape: a feed error inflating the price. Worth seeing,
    // but it is not money lost, so it must not mask a genuine floor breach.
    snapshotMovement('CEILING', 100.00, 150.00, 300.00);

    $this->artisan('pricing:audit-movements')
        ->expectsOutputToContain('over ceiling')
        ->assertExitCode(0);
});

it('ignores products whose price did not move', function (): void {
    snapshotMovement('MOVED', 100.00, 150.00, 127.20);

    // Same price both days — not a movement, so it is not part of the audit even
    // though its price would breach the floor.
    snapshotMovement('STATIC', 100.00, 120.00, 120.00);
    ProductPriceSnapshot::where('sku', 'STATIC')->update(['sell_price' => 120.00]);

    $this->artisan('pricing:audit-movements')
        ->doesntExpectOutputToContain('STATIC')
        ->assertExitCode(0);
});

it('audits every movement when the sample limit exceeds the population', function (): void {
    foreach (range(1, 5) as $i) {
        snapshotMovement("BULK-{$i}", 100.00, 150.00, 127.20);
    }

    $this->artisan('pricing:audit-movements --limit=100')
        ->expectsOutputToContain('Audited 5 movement(s)')
        ->assertExitCode(0);
});

it('reports a clean sample without printing a table of nothing', function (): void {
    snapshotMovement('CLEAN', 100.00, 150.00, 127.20);

    $this->artisan('pricing:audit-movements')
        ->expectsOutputToContain('No pricing-contract violations in the sample.')
        ->assertExitCode(0);
});
