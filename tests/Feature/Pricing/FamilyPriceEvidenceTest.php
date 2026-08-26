<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\ProductPriceSnapshot;

/*
|--------------------------------------------------------------------------
| 260826-fpe — did this margin get CHOSEN, or did it just survive?
|--------------------------------------------------------------------------
|
| Screen International splits into RAPT/MPCT/MPC/COM/TT near 98.9% and
| MJR/MJRT/GTHC/CHC/COMT near 22%, with DFT at 10% — one brand, one category,
| one supplier, and several families containing BOTH.
|
| That looks less like policy than like the Woo import revert loop: products the
| engine repriced fell to the 22% tier, products whose push was lost kept their
| original price. If so, 98.9% is a legacy list price and 29 permanent overrides
| would enshrine an accident at layer 0.
|
| ProductPriceSnapshot settles it, because it stores buy_price and sell_price
| together per day. A STEP is a reprice; a flat line is a product line.
*/

beforeEach(function (): void {
    config(['pricing.vat_basis_points' => 2000]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2200,
    ]);
});

function evidenceProduct(string $sku, string $status = 'pending'): Product
{
    return Product::factory()->create([
        'sku' => $sku,
        'name' => $sku,
        'buy_price' => 1000.00,
        'sell_price' => 2388.00,
        'status' => $status,
        'brand_id' => null,
        'category_id' => null,
    ]);
}

function snapshotOn(Product $product, string $date, float $cost, float $sell): void
{
    ProductPriceSnapshot::create([
        'product_id' => $product->id,
        'sku' => (string) $product->sku,
        'woo_status' => 'pending',
        'buy_price' => $cost,
        'sell_price' => $sell,
        'stock_quantity' => 0,
        'recorded_at' => $date,
    ]);
}

it('detects a reprice step from ~99% down to the default tier', function (): void {
    // The signature that would mean 98.9% is a LEGACY price, not a policy.
    $p = evidenceProduct('GTHC450X281');
    snapshotOn($p, '2026-08-01', 1000.00, 2388.00);   // 99%
    snapshotOn($p, '2026-08-02', 1000.00, 2388.00);
    snapshotOn($p, '2026-08-03', 1000.00, 1464.00);   // 22% — repriced

    $this->artisan('pricing:family-evidence --skus=GTHC450X281')
        ->expectsOutputToContain('STEP on 2026-08-03')
        ->expectsOutputToContain('LEGACY LIST PRICE')
        ->assertExitCode(0);
});

it('reports a family that has always run its margin as stable', function (): void {
    // The signature that would mean RAPT/MPCT are genuinely a different line.
    $p = evidenceProduct('RAPT350X265');
    snapshotOn($p, '2026-08-01', 1000.00, 2388.00);
    snapshotOn($p, '2026-08-02', 1000.00, 2388.00);
    snapshotOn($p, '2026-08-03', 1000.00, 2388.00);

    $this->artisan('pricing:family-evidence --skus=RAPT350X265')
        ->expectsOutputToContain('No reprice step detected')
        ->expectsOutputToContain('STABLE')
        ->assertExitCode(0);
});

it('shows whether a competitor existed when the step happened', function (): void {
    // A step WITH a competitor is the market pricing the product; a step with
    // none is the pricing rule. Price history alone cannot tell them apart.
    $competitor = Competitor::factory()->create(['name' => 'Ballicom']);
    $p = evidenceProduct('TT400X225');

    snapshotOn($p, '2026-08-01', 1000.00, 2388.00);
    snapshotOn($p, '2026-08-03', 1000.00, 1464.00);

    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => 'TT400X225',
        'price_pennies_gross' => 146500,
        'recorded_at' => '2026-08-02',
        'is_price_anomaly' => false,
    ]);

    $this->artisan('pricing:family-evidence --skus=TT400X225')
        ->expectsOutputToContain('1,465.00')
        ->assertExitCode(0);
});

it('reports no competitor when the step had none — the case to worry about', function (): void {
    $p = evidenceProduct('MJRT400X300');
    snapshotOn($p, '2026-08-01', 1000.00, 2388.00);
    snapshotOn($p, '2026-08-03', 1000.00, 1464.00);

    $this->artisan('pricing:family-evidence --skus=MJRT400X300')
        ->expectsOutputToContain('none')
        ->assertExitCode(0);
});

it('reports current status, taxonomy and resolved rule alongside the history', function (): void {
    $p = evidenceProduct('MPCT650X488');
    snapshotOn($p, '2026-08-01', 1000.00, 2388.00);

    // ONE assertion: Laravel consumes expectsOutputToContain in order, so
    // several of them against the SAME line silently fail after the first.
    $this->artisan('pricing:family-evidence --skus=MPCT650X488')
        ->expectsOutputToContain('status=pending   brand=none   category=none   resolved rule=22.0%')
        ->assertExitCode(0);
});

it('says so when a SKU has no history rather than implying stability', function (): void {
    evidenceProduct('NOHISTORY');

    $this->artisan('pricing:family-evidence --skus=NOHISTORY')
        ->expectsOutputToContain('no price history recorded')
        ->assertExitCode(0);
});

it('says so when a SKU does not exist', function (): void {
    $this->artisan('pricing:family-evidence --skus=GHOST-SKU')
        ->expectsOutputToContain('no local product with that SKU')
        ->assertExitCode(0);
});

it('requires something to investigate', function (): void {
    $this->artisan('pricing:family-evidence')->assertExitCode(1);
});

it('writes absolutely nothing', function (): void {
    $p = evidenceProduct('RAPT350X265');
    snapshotOn($p, '2026-08-01', 1000.00, 2388.00);
    $snapshots = ProductPriceSnapshot::count();

    $this->artisan('pricing:family-evidence --skus=RAPT350X265')->assertExitCode(0);

    expect((float) $p->fresh()->sell_price)->toBe(2388.00)
        ->and($p->fresh()->brand_id)->toBeNull()
        ->and(ProductPriceSnapshot::count())->toBe($snapshots);
});
