<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorMatchExclusion;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Events\ProductPriceChanged;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| 260825-h2r — SKU homonyms must not price each other
|--------------------------------------------------------------------------
|
| CP4 is two products:
|   ours          Unicol / AVM ceiling mount, cost £24.96 (Unicol) / £30.28
|                 (Northamber) — both feeds found under `cp4`, no alias involved
|   AVITDirect's  Crestron CP4 control processor, ~£1,748
|
| Neither feed is wrong. The string collides. Before the 2026-08-09 margin
| ceiling, the undercut logic matched them and set our £25 mount to £1,517.99 —
| exactly AVITDirect's £1,518.00 less the 1p beat_by_pennies, which is how the
| cause was identified.
|
| The ceiling has blocked every attempt since (5,737%), so the guard works. This
| stops the match arising at all, and deliberately does NOT use is_price_anomaly:
| that flag means the feed's price is wrong, and AVITDirect's is right.
*/

beforeEach(function (): void {
    config([
        'competitor.beat_by_pennies' => 1,
        'competitor.min_margin_floor_bps' => 600,
        'competitor.max_margin_ceiling_bps' => 5000,
        'pricing.vat_basis_points' => 2000,
    ]);

    PricingRule::factory()->defaultTier()->create([
        'tier_min_pennies' => 0,
        'tier_max_pennies' => null,
        'margin_basis_points' => 3500,
    ]);

    CompetitorMatchExclusion::forgetCache();
});

function homonymSetup(): array
{
    $avit = Competitor::factory()->create(['name' => 'AVITDirect']);
    $other = Competitor::factory()->create(['name' => 'Ballicom']);

    $product = Product::factory()->create([
        'sku' => 'CP4',
        'type' => 'simple',
        'buy_price' => 24.96,
        'sell_price' => 1517.99,
        'status' => 'publish',
    ]);

    // The Crestron listing, correct for THEIR product.
    CompetitorPrice::factory()->create([
        'competitor_id' => $avit->id,
        'sku' => 'CP4',
        'price_pennies_gross' => 174840,
        'recorded_at' => now()->subDay(),
        'is_price_anomaly' => false,
    ]);

    return [$avit, $other, $product];
}

it('excludes only the named competitor, and only for that key', function (): void {
    [$avit] = homonymSetup();

    CompetitorMatchExclusion::create([
        'competitor_id' => $avit->id,
        'match_key' => 'CP4',
        'reason' => 'AVITDirect CP4 is a Crestron control processor; ours is a Unicol mount.',
    ]);

    expect(CompetitorMatchExclusion::excludes((int) $avit->id, 'CP4'))->toBeTrue()
        ->and(CompetitorMatchExclusion::excludes((int) $avit->id, 'SOMETHING-ELSE'))->toBeFalse()
        ->and(CompetitorMatchExclusion::excludes(999, 'CP4'))->toBeFalse();
});

it('normalises the key so an exclusion cannot miss on casing', function (): void {
    [$avit] = homonymSetup();

    CompetitorMatchExclusion::create([
        'competitor_id' => $avit->id,
        'match_key' => '  Cp4  ',
        'reason' => 'homonym',
    ]);

    expect(CompetitorMatchExclusion::excludes((int) $avit->id, 'CP4'))->toBeTrue()
        ->and(CompetitorMatchExclusion::excludes((int) $avit->id, 'cp4'))->toBeTrue();
});

it('supports an all-competitors exclusion for a hopelessly generic string', function (): void {
    [$avit, $other] = homonymSetup();

    CompetitorMatchExclusion::create([
        'competitor_id' => null,
        'match_key' => 'CP4',
        'reason' => 'too generic to match anyone safely',
    ]);

    expect(CompetitorMatchExclusion::excludes((int) $avit->id, 'CP4'))->toBeTrue()
        ->and(CompetitorMatchExclusion::excludes((int) $other->id, 'CP4'))->toBeTrue();
});

it('stops the excluded row pricing our product at all', function (): void {
    [$avit, , $product] = homonymSetup();

    // Before: the Crestron row drives the decision and the ceiling blocks it.
    $this->artisan('pricing:undercut-competitors --skus=CP4')
        ->expectsOutputToContain('BLOCKED')
        ->assertExitCode(0);

    CompetitorMatchExclusion::create([
        'competitor_id' => $avit->id,
        'match_key' => 'CP4',
        'reason' => 'Crestron there, Unicol mount here',
    ]);
    CompetitorMatchExclusion::forgetCache();

    // After: no competitor at all, so it prices cost-plus. £24.96 x 1.35 x 1.2.
    $this->artisan('pricing:undercut-competitors --skus=CP4')
        ->doesntExpectOutputToContain('BLOCKED')
        ->expectsOutputToContain('40.44')
        ->assertExitCode(0);

    // Dry-run by default — the projected price is reported, never written.
    expect((float) $product->fresh()->sell_price)->toBe(1517.99);
});

it('leaves other competitors free to price the same SKU', function (): void {
    [$avit, $other] = homonymSetup();

    // A legitimate competitor for the actual mount.
    CompetitorPrice::factory()->create([
        'competitor_id' => $other->id,
        'sku' => 'CP4',
        'price_pennies_gross' => 4500,
        'recorded_at' => now()->subDay(),
        'is_price_anomaly' => false,
    ]);

    CompetitorMatchExclusion::create([
        'competitor_id' => $avit->id,
        'match_key' => 'CP4',
        'reason' => 'Crestron there, Unicol mount here',
    ]);
    CompetitorMatchExclusion::forgetCache();

    // Ballicom's £45 is now the lowest, so we undercut to £44.99 — proving the
    // exclusion removed one competitor rather than disabling matching wholesale.
    $this->artisan('pricing:undercut-competitors --skus=CP4')
        ->expectsOutputToContain('44.99')
        ->assertExitCode(0);
});

it('never touches is_price_anomaly, which means something else', function (): void {
    [$avit] = homonymSetup();

    CompetitorMatchExclusion::create([
        'competitor_id' => $avit->id,
        'match_key' => 'CP4',
        'reason' => 'homonym',
    ]);

    // The row is untouched: its price is CORRECT for AVITDirect's product, and
    // flagging it would corrupt the meaning of every future anomaly reading.
    expect(CompetitorPrice::where('competitor_id', $avit->id)->first()->is_price_anomaly)->toBeFalse();
});

// ── the operator command ──────────────────────────────────────────────────

it('writes nothing without --apply', function (): void {
    homonymSetup();

    $this->artisan('competitor:exclude-match --sku=CP4 --competitor=AVITDirect --reason=homonym')
        ->assertExitCode(0);

    expect(CompetitorMatchExclusion::count())->toBe(0);
});

it('refuses to add an exclusion with no stated reason', function (): void {
    homonymSetup();

    // An exclusion silently removes a competitor from pricing forever; an
    // undocumented one is indistinguishable from a mistake a year later.
    $this->artisan('competitor:exclude-match --sku=CP4 --competitor=AVITDirect --apply')
        ->assertExitCode(1);

    expect(CompetitorMatchExclusion::count())->toBe(0);
});

it('warns loudly when the exclusion removes the last competitor', function (): void {
    homonymSetup();

    // Nobody should learn from the storefront that a price dropped 97%.
    $this->artisan('competitor:exclude-match --sku=CP4 --competitor=AVITDirect --reason=homonym')
        ->expectsOutputToContain('LAST competitor')
        ->expectsOutputToContain('40.44')
        ->assertExitCode(0);
});

it('adds, is idempotent, and removes', function (): void {
    homonymSetup();

    $this->artisan('competitor:exclude-match --sku=CP4 --competitor=AVITDirect --reason=homonym --apply')
        ->assertExitCode(0);
    $this->artisan('competitor:exclude-match --sku=CP4 --competitor=AVITDirect --reason=homonym --apply')
        ->assertExitCode(0);

    expect(CompetitorMatchExclusion::count())->toBe(1);

    $this->artisan('competitor:exclude-match --sku=CP4 --competitor=AVITDirect --remove --apply')
        ->assertExitCode(0);

    expect(CompetitorMatchExclusion::count())->toBe(0);
});

it('rejects an unknown competitor rather than excluding everyone', function (): void {
    homonymSetup();

    $this->artisan('competitor:exclude-match --sku=CP4 --competitor=NotARealCompetitor --reason=x --apply')
        ->assertExitCode(1);

    expect(CompetitorMatchExclusion::count())->toBe(0);
});

// ── 260825-h2r — the live repair path, because CP4 is on the storefront ───

it('writes cost-plus and dispatches the Woo push once the homonym is excluded', function (): void {
    Event::fake([ProductPriceChanged::class]);
    [$avit, , $product] = homonymSetup();

    CompetitorMatchExclusion::create([
        'competitor_id' => $avit->id,
        'match_key' => 'CP4',
        'reason' => 'Crestron there, Unicol mount here',
    ]);
    CompetitorMatchExclusion::forgetCache();

    $this->artisan('pricing:undercut-competitors --skus=CP4 --live')->assertExitCode(0);

    // £24.96 x 1.35 x 1.2 = £40.44. The mount's real price.
    expect((float) $product->fresh()->sell_price)->toBe(40.44);

    // The local write alone is NOT the repair: woo:import-products overwrites
    // sell_price from Woo at 03:00, so an unpushed correction is reverted within
    // hours. The dispatched event is what carries it to the storefront.
    Event::assertDispatched(ProductPriceChanged::class);
});

it('leaves the price untouched if the exclusion is ever removed', function (): void {
    // Reversibility check: the exclusion is the only thing standing between our
    // mount and a Crestron's price, so removing it must visibly restore the old
    // (wrong) behaviour rather than fail quietly.
    [$avit] = homonymSetup();

    CompetitorMatchExclusion::create([
        'competitor_id' => $avit->id,
        'match_key' => 'CP4',
        'reason' => 'homonym',
    ]);
    CompetitorMatchExclusion::forgetCache();

    $this->artisan('pricing:undercut-competitors --skus=CP4')
        ->expectsOutputToContain('40.44')
        ->assertExitCode(0);

    CompetitorMatchExclusion::removeFor((int) $avit->id, 'CP4');

    $this->artisan('pricing:undercut-competitors --skus=CP4')
        ->expectsOutputToContain('BLOCKED')
        ->assertExitCode(0);
});
