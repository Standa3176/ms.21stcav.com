<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;

/*
|--------------------------------------------------------------------------
| 260825-mpr — evidence for the brand/category margin decision
|--------------------------------------------------------------------------
|
| The catalogue has THREE rules, all default_tier. Layers 1-3 of RuleResolver
| are empty, so everything prices on a cost band regardless of what it is.
|
| The report groups products, reports what each group ALREADY earns, and shows
| what adopting that as a rule would move. It does not decide anything: the
| proposal is a description of current practice, because the safe default when
| the alternative is an unchosen 39% cut is "keep today's prices".
*/

beforeEach(function (): void {
    config(['pricing.vat_basis_points' => 2000]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2200,
    ]);
});

function policyProduct(string $sku, float $cost, float $sell, array $attrs = []): Product
{
    // name defaults to the SKU so grouping is DETERMINISTIC: the factory's
    // random product name would otherwise decide the group key, and each
    // fixture would land in a group of one.
    return Product::factory()->create(array_merge([
        'sku' => $sku,
        'name' => $sku,
        'buy_price' => $cost,
        'sell_price' => $sell,
        'status' => 'publish',
        'brand_id' => null,
        'category_id' => null,
    ], $attrs));
}

it('groups no-brand products by SKU prefix, which is what finds the screens', function (): void {
    // The projection-screen families have neither brand nor category, and their
    // names are not brand-led — the SKU prefix is the only signal that groups
    // RAPT350X265 with RAPT300X225.
    policyProduct('RAPT350X265', 2134.96, 5095.71, ['name' => '350x265 screen']);
    policyProduct('RAPT300X225', 2011.00, 4802.58, ['name' => '300x225 screen']);
    policyProduct('RAPT250X190', 1895.00, 4526.04, ['name' => '250x190 screen']);

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('sku:RAPT')
        ->assertExitCode(0);
});

it('proposes the margin the group already earns, not the default tier', function (): void {
    // All three run ~98.9%. The proposal must describe that, not the 22% tier —
    // proposing the tier is exactly the 38.66% cut nobody chose.
    policyProduct('RAPT350X265', 2134.96, 5095.71);
    policyProduct('RAPT300X225', 2011.00, 4802.58);
    policyProduct('RAPT250X190', 1895.00, 4526.04);

    $this->artisan('pricing:margin-policy-report --min-group=2 --format=csv')
        ->expectsOutputToContain('99.00')   // median 99.01% on the 0.5pp grid
        ->assertExitCode(0);
});

it('ranks an unpublished group that would be cut hardest first', function (): void {
    // Priority 1: no live exposure YET, and publishing is what makes it real.
    policyProduct('SCRN1', 2134.96, 5095.71, ['status' => 'draft']);
    policyProduct('SCRN2', 2011.00, 4802.58, ['status' => 'draft']);
    policyProduct('SCRN3', 1895.00, 4526.04, ['status' => 'draft']);

    $this->artisan('pricing:margin-policy-report --min-group=2 --format=csv')
        ->expectsOutputToContain('1,"sku:SCRN"')
        ->assertExitCode(0);
});

it('flags a group protected only by a holding override', function (): void {
    // Priority 2: the 260824-w9k protection expires the moment real rules land,
    // so these must be decided before the overrides are deleted.
    $a = policyProduct('HELD-1', 857.21, 1625.99);
    $b = policyProduct('HELD-2', 900.00, 1700.00);

    foreach ([$a, $b] as $p) {
        ProductOverride::create([
            'product_id' => $p->id,
            'margin_basis_points' => 5800,
            'reason' => '260824-w9k holding override — pending taxonomy',
        ]);
    }

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('HELD')
        ->assertExitCode(0);
});

it('grades a group that disagrees with itself as low confidence', function (): void {
    // Confidence measures whether the family HAS a norm — not whether the norm
    // is right. Wildly different margins mean no single number describes them.
    policyProduct('MIXED-1', 100.00, 130.00);   //  ~8%
    policyProduct('MIXED-2', 100.00, 250.00);   // ~108%
    policyProduct('MIXED-3', 100.00, 180.00);   //  ~50%
    policyProduct('MIXED-4', 100.00, 145.00);   //  ~21%

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('MEMBERS DISAGREE')
        ->assertExitCode(0);
});

it('says assign a brand first when no brand exists to hang a rule on', function (): void {
    policyProduct('NOBRAND-1', 100.00, 162.00);
    policyProduct('NOBRAND-2', 110.00, 178.20);

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('assign brand first')
        ->assertExitCode(0);
});

it('recommends a brand rule once every member carries that brand', function (): void {
    // brand_id is a WOO TERM ID; with no Woo reachable in tests the report
    // falls back to "#id", which still groups correctly.
    policyProduct('YL-1', 100.00, 162.00, ['brand_id' => 13479]);
    policyProduct('YL-2', 120.00, 194.40, ['brand_id' => 13479]);

    $this->artisan('pricing:margin-policy-report --min-group=2 --format=csv')
        ->expectsOutputToContain('brand:#13479')
        ->assertExitCode(0);
});

it('quarantines an absurd margin as a data question, not a policy one', function (): void {
    // A 200%+ margin on a real cost is a broken cost or a wrong identity.
    // Leaving it in would drag the group median toward a number nobody chose.
    policyProduct('BROKEN', 42.00, 1186.42);
    policyProduct('SANE-1', 100.00, 162.00);
    policyProduct('SANE-2', 110.00, 178.20);

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('DATA QUALITY FIRST')
        ->expectsOutputToContain('BROKEN')
        ->assertExitCode(0);
});

it('writes absolutely nothing', function (): void {
    $product = policyProduct('READONLY', 100.00, 500.00);
    $ruleCount = PricingRule::count();

    $this->artisan('pricing:margin-policy-report --min-group=1')->assertExitCode(0);

    expect((float) $product->fresh()->sell_price)->toBe(500.00)
        ->and((float) $product->fresh()->buy_price)->toBe(100.00)
        ->and(PricingRule::count())->toBe($ruleCount)
        ->and(ProductOverride::count())->toBe(0);
});

it('emits csv for taking into a commercial conversation', function (): void {
    policyProduct('CSV-1', 100.00, 162.00);
    policyProduct('CSV-2', 110.00, 178.20);

    $this->artisan('pricing:margin-policy-report --min-group=2 --format=csv')
        ->expectsOutputToContain('priority,group,basis,count')
        ->assertExitCode(0);
});

// ── calibration fixes found by the first live run ─────────────────────────

it('proposes no change for a group already sitting exactly on its tier', function (): void {
    // The first live run rounded to a 2.5pp grid and invented its own headline:
    // Samsung's median was 22.0% — its tier exactly — and a 22.5% proposal moved
    // 79 products for £69,397 of synthetic movement. A grid coarser than the
    // tiers cannot express "leave this group alone".
    policyProduct('TIER-1', 100.00, 146.40);   // 22.0%
    policyProduct('TIER-2', 200.00, 292.80);   // 22.0%
    policyProduct('TIER-3', 300.00, 439.20);   // 22.0%

    $this->artisan('pricing:margin-policy-report --min-group=2 --format=csv')
        ->expectsOutputToContain('22.00,0.00,22.00,22.00')   // median,spread,rule,proposed
        ->assertExitCode(0);
});

it('never proposes a margin below the minimum-margin floor', function (): void {
    // A group at 6.0% is being FLOORED by competitor undercut, not choosing 6%.
    // Proposing 5% describes a price the floor would refuse to set.
    config(['competitor.min_margin_floor_bps' => 600]);

    policyProduct('FLOORED-1', 100.00, 127.20);   // 6.0%
    policyProduct('FLOORED-2', 200.00, 254.40);   // 6.0%

    $this->artisan('pricing:margin-policy-report --min-group=2 --format=csv')
        ->expectsOutputToContain('6.00,0.00,22.00,6.00')   // proposed clamped UP to the floor
        ->assertExitCode(0);
});

// ── separating coarse name groups, and the C2G question ───────────────────

it('forces SKU-prefix grouping so the screen families stop hiding', function (): void {
    // Their names all begin "Screen…", so auto-grouping swept 139 heterogeneous
    // products into name:screen and reported a 28.0% median — burying families
    // running near 99%. --group-by=sku-prefix separates them.
    policyProduct('TT400X225', 1557.20, 3716.70, ['name' => 'Screen 400x225']);
    policyProduct('TT350X197', 1400.00, 3341.00, ['name' => 'Screen 350x197']);
    policyProduct('TT300X169', 1250.00, 2983.00, ['name' => 'Screen 300x169']);
    policyProduct('COM160X120', 500.00, 654.90, ['name' => 'Screen 160x120']);
    policyProduct('COM180X135', 550.00, 720.40, ['name' => 'Screen 180x135']);
    policyProduct('COM200X150', 600.00, 786.00, ['name' => 'Screen 200x150']);

    // Auto lumps them together under one name.
    $this->artisan('pricing:margin-policy-report --min-group=2 --format=csv')
        ->expectsOutputToContain('name:screen')
        ->assertExitCode(0);

    // Forced SKU grouping splits the families apart.
    $this->artisan('pricing:margin-policy-report --group-by=sku-prefix --min-group=2 --format=csv')
        ->expectsOutputToContain('sku:TT')
        ->expectsOutputToContain('sku:COM')
        ->doesntExpectOutputToContain('name:screen')
        ->assertExitCode(0);
});

it('breaks a mixed name group down by sub-family without being asked', function (): void {
    // Same data, default grouping: the report must SHOW the structure it is
    // hiding, or the median quietly describes nobody.
    policyProduct('TT400X225', 1557.20, 3716.70, ['name' => 'Screen 400x225']);
    policyProduct('TT350X197', 1400.00, 3341.00, ['name' => 'Screen 350x197']);
    policyProduct('TT300X169', 1250.00, 2983.00, ['name' => 'Screen 300x169']);
    policyProduct('COM160X120', 500.00, 654.90, ['name' => 'Screen 160x120']);
    policyProduct('COM180X135', 550.00, 720.40, ['name' => 'Screen 180x135']);
    policyProduct('COM200X150', 600.00, 786.00, ['name' => 'Screen 200x150']);

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('INSIDE THE MIXED GROUPS')
        ->assertExitCode(0);
});

it('drills into one group at product level for the C2G question', function (): void {
    // Four cheap cable SKUs at ~400%: internally consistent? competitor data?
    // would a brand rule preserve prices? The detail view answers all three.
    policyProduct('88501', 1.62, 15.33, ['brand_id' => 4242]);
    policyProduct('88502', 2.22, 16.49, ['brand_id' => 4242]);
    policyProduct('88503', 3.13, 18.64, ['brand_id' => 4242]);

    $this->artisan('pricing:margin-policy-report --detail=brand:#4242')
        ->expectsOutputToContain('DETAIL: brand:#4242')
        ->expectsOutputToContain('88501')
        ->expectsOutputToContain('Competitor data on 0 of 3')
        ->assertExitCode(0);
});

it('says so plainly when a detail key matches nothing', function (): void {
    policyProduct('ANY-1', 100.00, 162.00);

    $this->artisan('pricing:margin-policy-report --detail=brand:Nonexistent')
        ->expectsOutputToContain('No products in that group')
        ->assertExitCode(0);
});

it('rejects an unknown --group-by rather than silently using auto', function (): void {
    $this->artisan('pricing:margin-policy-report --group-by=nonsense')->assertExitCode(1);
});

it('groups every decision that needs a human under a named reason', function (): void {
    config(['competitor.min_margin_floor_bps' => 600]);

    // On the floor — being beaten, not choosing.
    policyProduct('FLOOR-1', 100.00, 127.20);
    policyProduct('FLOOR-2', 200.00, 254.40);

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('DECISION NEEDED')
        ->expectsOutputToContain('SITTING ON THE MARGIN FLOOR')
        ->assertExitCode(0);
});

it('leads with the headline that most brands are already tier-consistent', function (): void {
    policyProduct('TIER-1', 100.00, 146.40);
    policyProduct('TIER-2', 200.00, 292.80);

    $this->artisan('pricing:margin-policy-report --min-group=2')
        ->expectsOutputToContain('HEADLINE')
        ->expectsOutputToContain('already sit within 1pp of their tier')
        ->assertExitCode(0);
});
