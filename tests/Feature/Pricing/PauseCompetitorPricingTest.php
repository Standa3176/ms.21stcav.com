<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| 260826-cpp — pause one competitor from pricing, until a date
|--------------------------------------------------------------------------
|
| screenmoove stopped publishing on 2026-07-19 holding 176,132 of ~271,000
| competitor rows — 65% of everything. The pricing lookup only reads rows inside
| the 30-day window, so on 2026-08-18 every screenmoove row aged out AT ONCE and
| every product whose only competitor was screenmoove moved to cost-plus.
|
| So pausing a silent feed changes nothing today. It earns its place when the
| feed is REPAIRED: fresh rows re-enter the window instantly and prices snap
| back to undercut with nobody reviewing them.
|
| The date is mandatory because every temporary measure here that relied on
| being remembered is still in place.
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
        'margin_basis_points' => 2200,
    ]);
});

function pausedSetup(string $sku = 'RAPT350X265'): array
{
    $competitor = Competitor::factory()->create(['name' => 'screenmoove']);

    $product = Product::factory()->create([
        'sku' => $sku,
        'name' => $sku,
        'type' => 'simple',
        'buy_price' => 2134.96,
        'sell_price' => 5095.71,
        'status' => 'pending',
    ]);

    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $sku,
        'price_pennies_gross' => 509572,
        'recorded_at' => now()->subDay(),
        'is_price_anomaly' => false,
    ]);

    return [$competitor, $product];
}

it('removes a paused competitor from the pricing decision', function (): void {
    [$competitor] = pausedSetup();

    // Fresh competitor row: the product is undercut at 1p below.
    $this->artisan('pricing:undercut-competitors --skus=RAPT350X265')
        ->expectsOutputToContain('undercut')
        ->assertExitCode(0);

    $competitor->forceFill(['pricing_paused_until' => now()->addDays(10)->toDateString()])->save();
    Cache::flush();

    // Paused: no competitor at all, so it prices cost-plus on the 22% tier.
    $this->artisan('pricing:undercut-competitors --skus=RAPT350X265')
        ->expectsOutputToContain('margin')
        ->assertExitCode(0);
});

it('lets the competitor price again once the date has passed', function (): void {
    // The whole point of a date: forgetting means the competitor RETURNS, not
    // that it stays invisible for good.
    [$competitor] = pausedSetup();

    $competitor->forceFill(['pricing_paused_until' => now()->subDay()->toDateString()])->save();
    Cache::flush();

    expect($competitor->fresh()->pricingPaused())->toBeFalse();

    $this->artisan('pricing:undercut-competitors --skus=RAPT350X265')
        ->expectsOutputToContain('undercut')
        ->assertExitCode(0);
});

it('says plainly when a pause changes nothing because the feed is already stale', function (): void {
    // screenmoove's real state: silent since 2026-07-19, every row long outside
    // the window. Pretending the pause fixed something would let someone believe
    // a problem had been dealt with.
    $competitor = Competitor::factory()->create(['name' => 'screenmoove']);

    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => 'RAPT350X265',
        'price_pennies_gross' => 509572,
        'recorded_at' => now()->subDays(45),
        'is_price_anomaly' => false,
    ]);

    $this->artisan('competitor:pause-pricing --competitor=screenmoove --until='.now()->addDays(10)->toDateString())
        ->expectsOutputToContain('This changes NOTHING today')
        ->assertExitCode(0);
});

it('warns when a pause WOULD move prices because rows are still fresh', function (): void {
    pausedSetup();

    $this->artisan('competitor:pause-pricing --competitor=screenmoove --until='.now()->addDays(10)->toDateString())
        ->expectsOutputToContain('INSIDE the window')
        ->assertExitCode(0);
});

it('refuses a pause with no expiry date', function (): void {
    pausedSetup();

    $this->artisan('competitor:pause-pricing --competitor=screenmoove')
        ->expectsOutputToContain('--until is required')
        ->assertExitCode(1);
});

it('refuses an expiry date in the past', function (): void {
    pausedSetup();

    $this->artisan('competitor:pause-pricing --competitor=screenmoove --until='.now()->subDay()->toDateString())
        ->assertExitCode(1);
});

it('writes nothing without --apply', function (): void {
    [$competitor] = pausedSetup();

    $this->artisan('competitor:pause-pricing --competitor=screenmoove --until='.now()->addDays(10)->toDateString())
        ->assertExitCode(0);

    expect($competitor->fresh()->pricing_paused_until)->toBeNull();
});

it('pauses, lists and resumes', function (): void {
    [$competitor] = pausedSetup();
    $until = now()->addDays(10)->toDateString();

    $this->artisan('competitor:pause-pricing --competitor=screenmoove --until='.$until.' --reason=feed-broken --apply')
        ->assertExitCode(0);

    expect($competitor->fresh()->pricingPaused())->toBeTrue();

    $this->artisan('competitor:pause-pricing --list')
        ->expectsOutputToContain('screenmoove')
        ->assertExitCode(0);

    $this->artisan('competitor:pause-pricing --competitor=screenmoove --resume --apply')->assertExitCode(0);

    expect($competitor->fresh()->pricing_paused_until)->toBeNull();
});

it('rejects an unknown competitor rather than pausing nothing quietly', function (): void {
    $this->artisan('competitor:pause-pricing --competitor=NotReal --until='.now()->addDays(5)->toDateString().' --apply')
        ->assertExitCode(1);
});

it('leaves other competitors pricing normally', function (): void {
    [$screenmoove, $product] = pausedSetup();

    $other = Competitor::factory()->create(['name' => 'Ballicom']);
    CompetitorPrice::factory()->create([
        'competitor_id' => $other->id,
        'sku' => 'RAPT350X265',
        'price_pennies_gross' => 400000,
        'recorded_at' => now()->subDay(),
        'is_price_anomaly' => false,
    ]);

    $screenmoove->forceFill(['pricing_paused_until' => now()->addDays(10)->toDateString()])->save();
    Cache::flush();

    // Ballicom's £4,000 is now the only competitor, so we undercut to £3,999.99.
    $this->artisan('pricing:undercut-competitors --skus=RAPT350X265')
        ->expectsOutputToContain('3,999.99')
        ->assertExitCode(0);
});
