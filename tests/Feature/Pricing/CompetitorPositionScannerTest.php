<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\CompetitorPositionScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;

/*
|--------------------------------------------------------------------------
| CompetitorPositionScanner — Pricing Operations dashboard read model
|--------------------------------------------------------------------------
| Verifies the below-cost / at-floor / winnable bucketing matches the
| pricing:floor-report ex-VAT margin math, lowest-across-competitors +
| latest-per-competitor selection, and sku↔mpn matching.
*/

beforeEach(function (): void {
    config(['competitor.min_margin_floor_bps' => 600]); // 6% floor
});

it('buckets products by ex-VAT margin vs the lowest current competitor', function (): void {
    // cost £100 ex, competitor £90 ex → margin −10% → below cost
    Product::factory()->create(['type' => 'simple', 'sku' => 'AAA', 'buy_price' => 100.00]);
    CompetitorPrice::factory()->forSku('AAA')->create(['price_pennies_ex_vat' => 9000]);

    // cost £100 ex, competitor £103 ex → margin 3% (< 6%) → at floor
    Product::factory()->create(['type' => 'simple', 'sku' => 'BBB', 'buy_price' => 100.00]);
    CompetitorPrice::factory()->forSku('BBB')->create(['price_pennies_ex_vat' => 10300]);

    // cost £100 ex, competitor £120 ex → margin 20% → winnable
    Product::factory()->create(['type' => 'simple', 'sku' => 'CCC', 'buy_price' => 100.00]);
    CompetitorPrice::factory()->forSku('CCC')->create(['price_pennies_ex_vat' => 12000]);

    $scan = app(CompetitorPositionScanner::class)->compute();

    expect($scan['matched_count'])->toBe(3)
        ->and($scan['below_cost_count'])->toBe(1)
        ->and($scan['at_floor_count'])->toBe(1)
        ->and($scan['winnable_count'])->toBe(1)
        ->and($scan['below_cost'][0]['sku'])->toBe('AAA')
        ->and($scan['below_cost'][0]['margin_bps'])->toBe(-1000)
        ->and($scan['at_floor'][0]['sku'])->toBe('BBB');
});

it('takes the latest row per competitor then the lowest across competitors', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'DDD', 'buy_price' => 100.00]);

    // Competitor 1: older £150 then newer £130 → latest = £130.
    $c1 = Competitor::factory()->create();
    CompetitorPrice::factory()->forSku('DDD')->recordedAt(now()->subDays(3))
        ->create(['competitor_id' => $c1->id, 'price_pennies_ex_vat' => 15000]);
    CompetitorPrice::factory()->forSku('DDD')->recordedAt(now()->subDay())
        ->create(['competitor_id' => $c1->id, 'price_pennies_ex_vat' => 13000]);

    // Competitor 2: £105 → the lowest across competitors → margin 5% < 6% → at floor.
    $c2 = Competitor::factory()->create();
    CompetitorPrice::factory()->forSku('DDD')
        ->create(['competitor_id' => $c2->id, 'price_pennies_ex_vat' => 10500]);

    $scan = app(CompetitorPositionScanner::class)->compute();

    expect($scan['at_floor_count'])->toBe(1)
        ->and($scan['at_floor'][0]['comp_ex'])->toBe(10500);
});

it('matches a product sku against the competitor mpn column too', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'PROD-SKU', 'buy_price' => 100.00]);
    // Competitor lists it under a different sku, but its mpn equals our sku.
    CompetitorPrice::factory()->create(['sku' => 'COMP-XYZ', 'mpn' => 'PROD-SKU', 'price_pennies_ex_vat' => 9000]);

    $scan = app(CompetitorPositionScanner::class)->compute();

    expect($scan['below_cost_count'])->toBe(1)
        ->and($scan['below_cost'][0]['sku'])->toBe('PROD-SKU');
});

it('ignores stale competitor prices outside the window', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'OLD', 'buy_price' => 100.00]);
    CompetitorPrice::factory()->forSku('OLD')->recordedAt(now()->subDays(60))
        ->create(['price_pennies_ex_vat' => 9000]);

    $scan = app(CompetitorPositionScanner::class)->compute(maxAgeDays: 30);

    expect($scan['matched_count'])->toBe(0)
        ->and($scan['below_cost_count'])->toBe(0);
});

it('resolves the supplier name behind our cost and the winning competitor name', function (): void {
    $product = Product::factory()->create(['type' => 'simple', 'sku' => 'NAM-1', 'buy_price' => 100.00]);

    // Cheapest current supplier offer = Ingram at £80 in stock today → the cost source.
    SupplierOfferSnapshot::create([
        'sku' => 'nam-1',
        'product_id' => $product->id,
        'supplier_id' => 'SUP-INGRAM',
        'supplier_name' => 'Ingram',
        'price' => 80.00,
        'stock' => 5,
        'rrp' => 150.00,
        'recorded_at' => today(),
    ]);
    // A pricier offer for the same product should NOT win the name.
    SupplierOfferSnapshot::create([
        'sku' => 'nam-1',
        'product_id' => $product->id,
        'supplier_id' => 'SUP-OTHER',
        'supplier_name' => 'Westcoast',
        'price' => 95.00,
        'stock' => 3,
        'rrp' => 150.00,
        'recorded_at' => today(),
    ]);

    // Lowest current competitor belongs to RivalCo at £90 ex → below cost.
    $rival = Competitor::factory()->create(['name' => 'RivalCo']);
    CompetitorPrice::factory()->forSku('NAM-1')
        ->create(['competitor_id' => $rival->id, 'price_pennies_ex_vat' => 9000]);

    $scan = app(CompetitorPositionScanner::class)->compute();

    expect($scan['below_cost_count'])->toBe(1)
        ->and($scan['below_cost'][0]['sku'])->toBe('NAM-1')
        ->and($scan['below_cost'][0]['supplier_name'])->toBe('Ingram')
        ->and($scan['below_cost'][0]['competitor_name'])->toBe('RivalCo');
});

it('yields a null supplier_name when no supplier offer snapshot exists', function (): void {
    $product = Product::factory()->create(['type' => 'simple', 'sku' => 'NAM-2', 'buy_price' => 100.00]);

    $rival = Competitor::factory()->create(['name' => 'SoloRival']);
    CompetitorPrice::factory()->forSku('NAM-2')
        ->create(['competitor_id' => $rival->id, 'price_pennies_ex_vat' => 9000]);

    $scan = app(CompetitorPositionScanner::class)->compute();

    expect($scan['below_cost_count'])->toBe(1)
        ->and($scan['below_cost'][0]['supplier_name'])->toBeNull()
        ->and($scan['below_cost'][0]['competitor_name'])->toBe('SoloRival');
});

it('breaks competitor ties deterministically on the lowest competitor id', function (): void {
    Product::factory()->create(['type' => 'simple', 'sku' => 'TIE-1', 'buy_price' => 100.00]);

    // Two competitors tie at the lowest ex-VAT price; the lower id must win.
    $first = Competitor::factory()->create(['name' => 'LowerIdCo']);
    $second = Competitor::factory()->create(['name' => 'HigherIdCo']);
    expect($first->id)->toBeLessThan($second->id);

    CompetitorPrice::factory()->forSku('TIE-1')
        ->create(['competitor_id' => $second->id, 'price_pennies_ex_vat' => 9000]);
    CompetitorPrice::factory()->forSku('TIE-1')
        ->create(['competitor_id' => $first->id, 'price_pennies_ex_vat' => 9000]);

    $scan = app(CompetitorPositionScanner::class)->compute();

    expect($scan['below_cost_count'])->toBe(1)
        ->and($scan['below_cost'][0]['competitor_name'])->toBe('LowerIdCo');
});
