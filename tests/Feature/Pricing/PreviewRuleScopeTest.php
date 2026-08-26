<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| 260826-rsp — what would a rule at this scope actually reach?
|--------------------------------------------------------------------------
|
| RAPT (21) and MPCT (8) run 98.9% with a 0.0% spread; adopting that moves
| £0.69 across all 29. But they carry no brand and no category, so no rule can
| reach them until taxonomy is assigned — and the moment it IS assigned, the
| rule reaches everything else sharing that term.
|
| That is the danger this exists for. A brand rule at 98.9% is correct for a
| premium screen and catastrophic for a cable that happens to share the brand.
| --expect-prefix names the families the rule is FOR; anything else it catches
| is UNRELATED, and that is the check that turns "looks right" into "is right".
*/

beforeEach(function (): void {
    config(['pricing.vat_basis_points' => 2000]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2200,
    ]);
});

function scopedProduct(string $sku, float $cost, float $sell, array $attrs = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => $sku,
        'name' => $sku,
        'buy_price' => $cost,
        'sell_price' => $sell,
        'status' => 'pending',
        'brand_id' => null,
        'category_id' => null,
    ], $attrs));
}

it('passes when the scope reaches only the intended family and moves nothing', function (): void {
    // Real RAPT numbers: 98.9% already, so a 98.9% rule is a record, not a change.
    scopedProduct('RAPT350X265', 2134.96, 5095.72, ['brand_id' => 7001]);
    scopedProduct('RAPT300X225', 2012.15, 4802.60, ['brand_id' => 7001]);

    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --margin-bps=9890 --expect-prefix=RAPT')
        ->expectsOutputToContain('SAFE')
        ->assertExitCode(0);
});

it('fails when the scope catches a product outside the intended family', function (): void {
    // The whole point: a brand rule at 98.9% is right for a screen and
    // catastrophic for a cable that happens to share the brand.
    scopedProduct('RAPT350X265', 2134.96, 5095.72, ['brand_id' => 7001]);
    scopedProduct('CABLE-2M', 1.62, 2.63, ['brand_id' => 7001]);

    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --margin-bps=9890 --expect-prefix=RAPT')
        ->expectsOutputToContain('UNRELATED')
        ->expectsOutputToContain('NOT SAFE')
        ->assertExitCode(1);
});

it('fails when a published price would move', function (): void {
    // 29 unpublished screens is what makes this exception safe. A published
    // product moving is a customer-visible change and a different decision.
    scopedProduct('RAPT350X265', 2134.96, 5095.72, ['brand_id' => 7001]);
    scopedProduct('RAPT300X225', 2012.15, 3000.00, ['brand_id' => 7001, 'status' => 'publish']);

    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --margin-bps=9890 --expect-prefix=RAPT')
        ->expectsOutputToContain('NOT SAFE')
        ->expectsOutputToContain('PUBLISHED price(s) would move')
        ->assertExitCode(1);
});

it('fails when the worst move exceeds one percent', function (): void {
    scopedProduct('RAPT350X265', 2134.96, 5095.72, ['brand_id' => 7001]);
    scopedProduct('RAPT300X225', 2012.15, 4000.00, ['brand_id' => 7001]);

    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --margin-bps=9890 --expect-prefix=RAPT')
        ->expectsOutputToContain('exceeds the 1% tolerance')
        ->assertExitCode(1);
});

it('narrows correctly: brand+category can exclude what brand alone catches', function (): void {
    // The recommended escalation when a brand is too broad.
    scopedProduct('RAPT350X265', 2134.96, 5095.72, ['brand_id' => 7001, 'category_id' => 55]);
    scopedProduct('CABLE-2M', 1.62, 2.63, ['brand_id' => 7001, 'category_id' => 99]);

    // Brand alone sweeps the cable in.
    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --margin-bps=9890 --expect-prefix=RAPT')
        ->assertExitCode(1);

    // Brand + category reaches only the screen.
    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --category-id=55 --margin-bps=9890 --expect-prefix=RAPT')
        ->expectsOutputToContain('SAFE')
        ->assertExitCode(0);
});

it('names the resolver layer so the scope choice is explicit', function (): void {
    scopedProduct('RAPT350X265', 2134.96, 5095.72, ['brand_id' => 7001, 'category_id' => 55]);

    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --category-id=55 --margin-bps=9890 --expect-prefix=RAPT')
        ->expectsOutputToContain('layer 1')
        ->assertExitCode(0);
});

it('says plainly when the scope reaches nothing yet', function (): void {
    // Expected before taxonomy is assigned — and a rule written against an empty
    // scope is one whose effect nobody has seen.
    scopedProduct('RAPT350X265', 2134.96, 5095.72);

    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --margin-bps=9890')
        ->expectsOutputToContain('reaches NO products')
        ->assertExitCode(0);
});

it('requires a margin and a scope', function (): void {
    $this->artisan('pricing:preview-rule-scope --brand-id=7001')->assertExitCode(1);
    $this->artisan('pricing:preview-rule-scope --margin-bps=9890')->assertExitCode(1);
});

it('creates nothing', function (): void {
    $product = scopedProduct('RAPT350X265', 2134.96, 5095.72, ['brand_id' => 7001]);
    $rules = PricingRule::count();

    $this->artisan('pricing:preview-rule-scope --brand-id=7001 --margin-bps=9890 --expect-prefix=RAPT')
        ->assertExitCode(0);

    expect(PricingRule::count())->toBe($rules)
        ->and((float) $product->fresh()->sell_price)->toBe(5095.72)
        ->and($product->fresh()->brand_id)->toBe(7001);
});
