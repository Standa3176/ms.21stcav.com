<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Pricing\Services\RuleResolver;
use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| 260824-w9k — freeze a product at the margin it already runs
|--------------------------------------------------------------------------
|
| 1,954 products carry neither brand_id nor category_id, so RuleResolver cannot
| reach the brand/category layers and drops to the generic default_tier. 245 are
| published; 15 of those run materially above that tier, including lines at
| 99.5%, 58% and 50% that look deliberate.
|
| This buys time without changing a price: read each product's CURRENT effective
| margin from its own cost and price, write exactly that as a ProductOverride,
| and the engine then computes the price the product already has.
|
| The guard rail matters as much as the feature. SKU BT6010/Z carries a £21.37
| cost against a £4,240.78 price — 16,437%. Freezing that would cement a data
| error into layer 0 of the resolver, where it beats every rule permanently.
*/

beforeEach(function (): void {
    config(['pricing.vat_basis_points' => 2000]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2200,
    ]);
});

it('writes an override at the margin the product already runs', function (): void {
    // £857.21 cost, £1,625.99 price → ex-VAT £1,354.99 → 58.07% margin.
    Product::factory()->create([
        'sku' => 'CIP-SRH',
        'buy_price' => 857.21,
        'sell_price' => 1625.99,
        'brand_id' => null,
        'category_id' => null,
    ]);

    $this->artisan('pricing:protect-margin --skus=CIP-SRH --apply')->assertExitCode(0);

    $override = ProductOverride::first();
    expect($override)->not->toBeNull()
        ->and($override->margin_basis_points)->toBeGreaterThan(5700)
        ->and($override->margin_basis_points)->toBeLessThan(5900)
        ->and($override->reason)->toContain('260824-w9k');
});

it('changes no price — the frozen margin reproduces the price already set', function (): void {
    $product = Product::factory()->create([
        'sku' => 'NO-CHANGE',
        'buy_price' => 1263.94,
        'sell_price' => 2276.23,
        'brand_id' => null,
        'category_id' => null,
    ]);

    $this->artisan('pricing:protect-margin --skus=NO-CHANGE --apply')->assertExitCode(0);

    // The whole point: protection is a description of today, not a decision.
    expect((float) $product->fresh()->sell_price)->toBe(2276.23);

    $resolved = app(RuleResolver::class)->resolve($product->fresh());
    $recomputed = app(PriceCalculator::class)->compute(
        (int) round(1263.94 * 100),
        $resolved->marginBasisPoints,
        2000,
    );

    // Within a penny of where it already sits — so the engine proposes nothing.
    expect(abs($recomputed - 227623))->toBeLessThanOrEqual(100);
});

it('refuses to cement an implausible margin', function (): void {
    // The real BT6010/Z: £21.37 cost, £4,240.78 price = 16,437%.
    Product::factory()->create([
        'sku' => 'BT6010/Z',
        'buy_price' => 21.37,
        'sell_price' => 4240.78,
        'brand_id' => null,
        'category_id' => null,
    ]);

    $this->artisan('pricing:protect-margin --skus=BT6010/Z --apply')
        ->expectsOutputToContain('REFUSED')
        ->assertExitCode(0);

    expect(ProductOverride::count())->toBe(0);
});

it('refuses a product priced below cost', function (): void {
    Product::factory()->create([
        'sku' => 'LOSS',
        'buy_price' => 100.00,
        'sell_price' => 90.00,
        'brand_id' => null,
        'category_id' => null,
    ]);

    $this->artisan('pricing:protect-margin --skus=LOSS --apply')
        ->expectsOutputToContain('REFUSED')
        ->assertExitCode(0);

    expect(ProductOverride::count())->toBe(0);
});

it('writes nothing without --apply', function (): void {
    Product::factory()->create([
        'sku' => 'DRY',
        'buy_price' => 100.00,
        'sell_price' => 180.00,
        'brand_id' => null,
        'category_id' => null,
    ]);

    $this->artisan('pricing:protect-margin --skus=DRY')
        ->expectsOutputToContain('would freeze')
        ->assertExitCode(0);

    expect(ProductOverride::count())->toBe(0);
});

it('refuses to run without an explicit SKU list', function (): void {
    // Freezing margins in bulk is how a pricing system stops being explicable —
    // the command must never be pointable at a whole catalogue.
    $this->artisan('pricing:protect-margin')->assertExitCode(1);
});

it('reports a SKU that does not exist rather than failing the batch', function (): void {
    Product::factory()->create([
        'sku' => 'REAL',
        'buy_price' => 100.00,
        'sell_price' => 180.00,
        'brand_id' => null,
        'category_id' => null,
    ]);

    $this->artisan('pricing:protect-margin --skus=REAL,GHOST --apply')
        ->expectsOutputToContain('NOT FOUND')
        ->assertExitCode(0);

    expect(ProductOverride::count())->toBe(1);
});

it('updates rather than duplicates when re-run for the same product', function (): void {
    Product::factory()->create([
        'sku' => 'RERUN',
        'buy_price' => 100.00,
        'sell_price' => 180.00,
        'brand_id' => null,
        'category_id' => null,
    ]);

    $this->artisan('pricing:protect-margin --skus=RERUN --apply')->assertExitCode(0);
    $this->artisan('pricing:protect-margin --skus=RERUN --apply')->assertExitCode(0);

    expect(ProductOverride::count())->toBe(1);
});
