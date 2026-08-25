<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| 260825-mpr — the exact margin exception a family would need
|--------------------------------------------------------------------------
|
| For RAPT (21 SKUs) and MPCT (8): 98.9% margin, 0.0% spread, all unpublished.
| Making that explicit BEFORE publication is the point — otherwise the first
| thing that reprices them cuts 38.66%, because 1.220 / 1.989 = 0.6134.
|
| The test of a good exception here is that NOTHING MOVES. The family already
| charges this; the rule only stops something else deciding otherwise.
*/

beforeEach(function (): void {
    config(['pricing.vat_basis_points' => 2000]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2200,
    ]);
});

function screenProduct(string $sku, float $cost, float $sell, array $attrs = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => $sku,
        'name' => $sku,
        'buy_price' => $cost,
        'sell_price' => $sell,
        'status' => 'draft',
        'brand_id' => null,
        'category_id' => null,
    ], $attrs));
}

it('calls the proposal safe when it records what the family already charges', function (): void {
    // Real RAPT shape: 98.9% across the family, so a 98.9% rule moves nothing.
    screenProduct('RAPT350X265', 2134.96, 5095.71);
    screenProduct('RAPT300X225', 2011.00, 4797.03);
    screenProduct('RAPT250X190', 1895.00, 4520.13);

    $this->artisan('pricing:propose-exception --prefix=RAPT')
        ->expectsOutputToContain('SAFE')
        ->assertExitCode(0);
});

it('refuses to call a price-moving proposal a pure record', function (): void {
    // A family that does NOT agree with itself: any single margin moves someone,
    // and that is new policy rather than recorded policy.
    screenProduct('MIX-1', 100.00, 146.40);   // 22%
    screenProduct('MIX-2', 100.00, 292.80);   // 144%

    $this->artisan('pricing:propose-exception --prefix=MIX')
        ->expectsOutputToContain('NOT A PURE RECORD')
        ->assertExitCode(0);
});

it('leads with assigning a brand rather than a pile of overrides', function (): void {
    // The families have no taxonomy, so only overrides work TODAY — but
    // ProductOverride is layer 0 and beats every rule permanently. 29 of them is
    // how a pricing system becomes one nobody can reason about.
    screenProduct('RAPT350X265', 2134.96, 5095.71);
    screenProduct('RAPT300X225', 2011.00, 4797.03);

    $this->artisan('pricing:propose-exception --prefix=RAPT')
        ->expectsOutputToContain('RECOMMENDED')
        ->expectsOutputToContain('assign-taxonomy')
        ->expectsOutputToContain('FALLBACK')
        ->assertExitCode(0);
});

it('recommends a single brand rule once the family carries a brand', function (): void {
    screenProduct('RAPT350X265', 2134.96, 5095.71, ['brand_id' => 4242]);
    screenProduct('RAPT300X225', 2011.00, 4797.03, ['brand_id' => 4242]);

    $this->artisan('pricing:propose-exception --prefix=RAPT')
        ->expectsOutputToContain('one brand-scope rule')
        ->assertExitCode(0);
});

it('warns when only some of the family carries a brand', function (): void {
    // A rule that reaches half a family applies the exception unevenly, which is
    // worse than not applying it.
    screenProduct('RAPT350X265', 2134.96, 5095.71, ['brand_id' => 4242]);
    screenProduct('RAPT300X225', 2011.00, 4797.03);

    $this->artisan('pricing:propose-exception --prefix=RAPT')
        ->expectsOutputToContain('would MISS the rest')
        ->assertExitCode(0);
});

it('accepts an explicit margin instead of the family median', function (): void {
    screenProduct('RAPT350X265', 2134.96, 5095.71);
    screenProduct('RAPT300X225', 2011.00, 4797.03);

    $this->artisan('pricing:propose-exception --prefix=RAPT --margin-bps=5000')
        ->expectsOutputToContain('50.0%')
        ->assertExitCode(0);
});

it('handles two families in one run', function (): void {
    screenProduct('RAPT350X265', 2134.96, 5095.71);
    screenProduct('RAPT300X225', 2011.00, 4797.03);
    screenProduct('MPCT650X488', 3240.29, 7733.92);
    screenProduct('MPCT550X413', 2700.00, 6443.39);

    $this->artisan('pricing:propose-exception --prefix=RAPT,MPCT')
        ->expectsOutputToContain('── RAPT ──')
        ->expectsOutputToContain('── MPCT ──')
        ->assertExitCode(0);
});

it('says so plainly when a prefix matches nothing', function (): void {
    $this->artisan('pricing:propose-exception --prefix=NOTHINGHERE')
        ->expectsOutputToContain('No products')
        ->assertExitCode(0);
});

it('requires a prefix rather than scanning the catalogue', function (): void {
    $this->artisan('pricing:propose-exception')->assertExitCode(1);
});

it('writes absolutely nothing', function (): void {
    $product = screenProduct('RAPT350X265', 2134.96, 5095.71);
    $rules = PricingRule::count();

    $this->artisan('pricing:propose-exception --prefix=RAPT')->assertExitCode(0);

    expect((float) $product->fresh()->sell_price)->toBe(5095.71)
        ->and(PricingRule::count())->toBe($rules)
        ->and(ProductOverride::count())->toBe(0);
});
