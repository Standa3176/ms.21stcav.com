<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\TradePricing\Models\TradeCostAdjustment;
use App\Domain\TradePricing\Services\TradeStorefrontPricer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260910-lwv — trade storefront pricer
|--------------------------------------------------------------------------
|
| Policy (operator 2026-09-09/10): trade = cost + 15% max, floored at 6%,
| never at or above the retail price. Cost may carry a trade-only discount
| that retail never sees.
|
| The numbers below are real ones off the live catalogue, so a future reader
| can check them against the storefront rather than trusting the arithmetic.
*/

function tradePricer(): TradeStorefrontPricer
{
    // Resolved fresh so the resolver memo does not leak between cases.
    return app(TradeStorefrontPricer::class);
}

it('prices at cost + 15% when retail leaves room', function (): void {
    // 698.00 cost, 1836.25 retail — ViewSonic CDE7531-1C off the live catalogue.
    $p = Product::factory()->create(['buy_price' => 698.00, 'sell_price' => 1836.25]);

    $r = tradePricer()->price($p);

    // 698 x 1.15 x 1.2 = 963.24
    expect($r->source)->toBe('target')
        ->and($r->pennies)->toBe(96324)
        ->and($r->isPublishable())->toBeTrue();
});

it('caps below retail by the minimum discount, not by a single penny', function (): void {
    // 260910-rsv — the ceiling is now retail minus the minimum discount.
    // Cost 100.00 gives floor 127.20 and target 138.00 (both inc-VAT); retail
    // 140.00 sits above target, so at 2% the ceiling is 137.20 and binds.
    config()->set('b2b.storefront.min_discount_pct', 2.0);
    $p = Product::factory()->create(['buy_price' => 100.00, 'sell_price' => 140.00]);

    $r = tradePricer()->price($p);

    expect($r->source)->toBe('retail_capped')
        ->and($r->pennies)->toBe(13720);   // 140.00 x 0.98
});

it('SUPPRESSES rather than publishing a token penny off standard', function (): void {
    // The 458-product case from the first live preview: cost+15% exceeds
    // retail, so the old code published retail-minus-1p. With a 2% minimum
    // there is no room above the floor, so nothing publishes and B2BKing
    // falls back to the standard price.
    // Cost 100.00 gives a 127.20 floor. At retail 129.00 a 2% discount lands
    // at 126.42 — below the floor — so nothing can be published.
    config()->set('b2b.storefront.min_discount_pct', 2.0);
    $p = Product::factory()->create(['buy_price' => 100.00, 'sell_price' => 129.00]);

    expect(tradePricer()->price($p)->source)->toBe('suppressed')
        ->and(tradePricer()->price($p)->reason)->toBe('no_headroom_below_retail');
});

it('restores penny-level behaviour when the minimum discount is zero', function (): void {
    config()->set('b2b.storefront.min_discount_pct', 0);
    $p = Product::factory()->create(['buy_price' => 100.00, 'sell_price' => 130.00]);

    expect(tradePricer()->price($p)->pennies)->toBe(12999);
});

it('SUPPRESSES rather than selling below the 6% floor', function (): void {
    // The live Logitech 960-001311: retail sits exactly on the 6% floor, so
    // there is no price that is both above cost+6% and below retail.
    // 2561.78 x 1.06 x 1.2 = 3258.58 = the retail price to the penny.
    $p = Product::factory()->create(['buy_price' => 2561.78, 'sell_price' => 3258.58]);

    $r = tradePricer()->price($p);

    expect($r->source)->toBe('suppressed')
        ->and($r->reason)->toBe('no_headroom_below_retail')
        ->and($r->pennies)->toBeNull()
        ->and($r->isPublishable())->toBeFalse();
});

it('never returns a price below the floor even when retail is very low', function (): void {
    $p = Product::factory()->create(['buy_price' => 100.00, 'sell_price' => 50.00]);

    $r = tradePricer()->price($p);

    // Retail is below cost entirely — nothing sellable exists here.
    expect($r->source)->toBe('suppressed');
});

it('applies a brand cost adjustment, and that adjustment is TRADE ONLY', function (): void {
    $p = Product::factory()->create([
        'brand_id' => 4242,
        'buy_price' => 1000.00,
        'sell_price' => 2000.00,
    ]);

    TradeCostAdjustment::factory()->create(['brand_id' => 4242, 'adjustment_pct' => '12.000']);

    $r = tradePricer()->price($p);

    // 1000 x 0.88 = 880 trade cost; x 1.15 x 1.2 = 1214.40
    expect($r->pennies)->toBe(121440)
        ->and($r->costAdjustment)->toBe(0.12)
        // The feed cost on the product itself is untouched — retail still
        // prices off 1000.00, which is the whole point of the design.
        ->and((float) $p->fresh()->buy_price)->toBe(1000.0);
});

it('lets a brand adjustment rescue a product that would otherwise be suppressed', function (): void {
    // Retail on the 6% floor: nothing to give away at feed cost...
    $p = Product::factory()->create([
        'brand_id' => 77,
        'buy_price' => 2561.78,
        'sell_price' => 3258.58,
    ]);

    expect(tradePricer()->price($p)->source)->toBe('suppressed');

    // ...but a real 12% better cost creates genuine headroom.
    TradeCostAdjustment::factory()->create(['brand_id' => 77, 'adjustment_pct' => '12.000']);

    $r = tradePricer()->price($p);

    expect($r->isPublishable())->toBeTrue()
        ->and($r->pennies)->toBeLessThan(325858);
});

it('prefers a SKU adjustment over the brand one, even when it is smaller', function (): void {
    $p = Product::factory()->create([
        'sku' => 'DEAL-1',
        'brand_id' => 9,
        'buy_price' => 1000.00,
        'sell_price' => 5000.00,
    ]);

    TradeCostAdjustment::factory()->create(['brand_id' => 9, 'adjustment_pct' => '20.000']);
    TradeCostAdjustment::factory()->create(['sku' => 'DEAL-1', 'adjustment_pct' => '5.000']);

    // A per-SKU row is a deliberate override, not an accident.
    expect(tradePricer()->price($p)->costAdjustment)->toBe(0.05);
});

it('ignores an expired adjustment so a manufacturer deal lapses on its own', function (): void {
    $p = Product::factory()->create(['brand_id' => 55, 'buy_price' => 1000.00, 'sell_price' => 5000.00]);

    TradeCostAdjustment::factory()->create([
        'brand_id' => 55,
        'adjustment_pct' => '15.000',
        'valid_until' => now()->subDay()->toDateString(),
    ]);

    expect(tradePricer()->price($p)->costAdjustment)->toBe(0.0);
});

it('ignores an adjustment that has not started yet, and an inactive one', function (): void {
    $future = Product::factory()->create(['brand_id' => 61, 'buy_price' => 1000.00, 'sell_price' => 5000.00]);
    TradeCostAdjustment::factory()->create([
        'brand_id' => 61,
        'adjustment_pct' => '15.000',
        'valid_from' => now()->addWeek()->toDateString(),
    ]);

    $off = Product::factory()->create(['brand_id' => 62, 'buy_price' => 1000.00, 'sell_price' => 5000.00]);
    TradeCostAdjustment::factory()->create([
        'brand_id' => 62,
        'adjustment_pct' => '15.000',
        'is_active' => false,
    ]);

    expect(tradePricer()->price($future)->costAdjustment)->toBe(0.0)
        ->and(tradePricer()->price($off)->costAdjustment)->toBe(0.0);
});

it('suppresses when cost or retail is missing rather than inventing a price', function (): void {
    $noCost = Product::factory()->create(['buy_price' => 0, 'sell_price' => 100.00]);
    $noRetail = Product::factory()->create(['buy_price' => 50.00, 'sell_price' => 0]);

    expect(tradePricer()->price($noCost)->reason)->toBe('no_cost')
        ->and(tradePricer()->price($noRetail)->reason)->toBe('no_retail_price');
});

it('honours configured margins rather than hardcoding 6 and 15', function (): void {
    config()->set('b2b.storefront.max_margin_bps', 1000);
    $p = Product::factory()->create(['buy_price' => 100.00, 'sell_price' => 500.00]);

    // 100 x 1.10 x 1.2 = 132.00
    expect(tradePricer()->price($p)->pennies)->toBe(13200);
});

/*
|--------------------------------------------------------------------------
| 260910-rsv — absolute cost from a manufacturer price list
|--------------------------------------------------------------------------
|
| The August Yealink platinum list is a FIXED price per line for the month.
| Loading it as a percentage would drift every time the supplier feed moves,
| so a row may instead carry the price itself.
*/

it('prices from a manufacturer price-list cost, ignoring what the feed says', function (): void {
    $p = Product::factory()->create([
        'sku' => 'YEA-A24', 'brand_id' => 300,
        'buy_price' => 870.00,      // what the feed quotes (silver)
        'sell_price' => 1400.00,
    ]);

    // The real platinum price for A24 from the August list.
    TradeCostAdjustment::factory()->priceList(773.00)->create(['sku' => 'YEA-A24']);

    $r = tradePricer()->price($p);

    // 773 x 1.15 x 1.2 = 1066.74
    expect($r->pennies)->toBe(106674)
        ->and($r->source)->toBe('target')
        // 11.1% below feed — the measured platinum gap.
        ->and(round($r->costAdjustment * 100, 1))->toBe(11.1);
});

it('leaves products.buy_price untouched — the adjustment is trade only', function (): void {
    $p = Product::factory()->create(['sku' => 'YEA-X', 'buy_price' => 1000.00, 'sell_price' => 2000.00]);
    TradeCostAdjustment::factory()->priceList(800.00)->create(['sku' => 'YEA-X']);

    tradePricer()->price($p);

    expect((float) $p->fresh()->buy_price)->toBe(1000.0);
});

it('takes the cheaper of two competing brand arrangements', function (): void {
    $p = Product::factory()->create(['brand_id' => 501, 'buy_price' => 1000.00, 'sell_price' => 5000.00]);
    TradeCostAdjustment::factory()->create(['brand_id' => 501, 'adjustment_pct' => '5.000']);   // -> 950
    TradeCostAdjustment::factory()->priceList(880.00)->create(['brand_id' => 501]);             // -> 880

    expect(tradePricer()->price($p)->costAdjustment)->toBe(0.12);
});

it('ignores a row that would make trade cost MORE than the feed', function (): void {
    // Three Yealink lines came back with the price list ABOVE the feed cost.
    // Whatever the cause, honouring it would price trade above retail.
    $p = Product::factory()->create(['sku' => 'YEA-RCH80', 'buy_price' => 205.00, 'sell_price' => 400.00]);
    TradeCostAdjustment::factory()->priceList(252.00)->create(['sku' => 'YEA-RCH80']);

    $r = tradePricer()->price($p);

    expect($r->costAdjustment)->toBe(0.0)
        // 205 x 1.15 x 1.2 = 282.90 — priced off the feed, not the bad row.
        ->and($r->pennies)->toBe(28290);
});

it('ignores a price-list row that has expired', function (): void {
    $p = Product::factory()->create(['sku' => 'LOGI-DEAL', 'buy_price' => 1000.00, 'sell_price' => 5000.00]);
    TradeCostAdjustment::factory()->priceList(800.00)->create([
        'sku' => 'LOGI-DEAL',
        'valid_until' => now()->subDay()->toDateString(),
    ]);

    expect(tradePricer()->price($p)->costAdjustment)->toBe(0.0);
});
