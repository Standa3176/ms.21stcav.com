<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\PricingOpsReport;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| PricingOpsReport — 260606-rld Task 2
|--------------------------------------------------------------------------
| Verifies:
|   - positions() decorates below_cost / at_floor / winnable rows with
|     brand_name via TaxonomyResolver::allBrands() (resolved via runtime
|     app(\FQCN::class) — the documented deptrac escape; see PLAN.md
|     <deptrac_research>).
|   - csv('below_cost') + csv('at_floor') return a 6-column shape with Brand
|     inserted at index 1.
|   - csv('winnable') stays byte-identical to the legacy 5-column shape
|     (no regression on the other competitor-position buckets).
*/

beforeEach(function (): void {
    config(['competitor.min_margin_floor_bps' => 600]);
    Cache::forget(PricingOpsReport::CACHE_KEY);
    Cache::forget('taxonomy.brands');
});

it('positions() decorates below_cost rows with brand_name from TaxonomyResolver', function (): void {
    // Stub TaxonomyResolver — return a known brand map without touching Woo.
    $stub = Mockery::mock(TaxonomyResolver::class);
    $stub->shouldReceive('allBrands')->andReturn([
        ['id' => 10, 'name' => 'Yealink'],
        ['id' => 20, 'name' => 'Logitech'],
    ]);
    app()->instance(TaxonomyResolver::class, $stub);

    // Two below-cost products: one branded (Yealink, id=10), one unbranded (null).
    Product::factory()->create([
        'type' => 'simple', 'sku' => 'OPS-BRD-1', 'buy_price' => 100.00, 'brand_id' => 10,
    ]);
    CompetitorPrice::factory()->forSku('OPS-BRD-1')->create(['price_pennies_ex_vat' => 9000]);

    Product::factory()->create([
        'type' => 'simple', 'sku' => 'OPS-BRD-2', 'buy_price' => 100.00, 'brand_id' => null,
    ]);
    CompetitorPrice::factory()->forSku('OPS-BRD-2')->create(['price_pennies_ex_vat' => 9000]);

    $positions = app(PricingOpsReport::class)->positions();

    $byName = collect($positions['below_cost'])->keyBy('sku');
    expect($positions['below_cost_count'])->toBe(2)
        ->and($byName['OPS-BRD-1']['brand_name'])->toBe('Yealink')
        ->and($byName['OPS-BRD-2']['brand_name'])->toBeNull();
});

it('csv(below_cost) returns the 6-column shape with Brand at index 1', function (): void {
    $stub = Mockery::mock(TaxonomyResolver::class);
    $stub->shouldReceive('allBrands')->andReturn([
        ['id' => 10, 'name' => 'Yealink'],
    ]);
    app()->instance(TaxonomyResolver::class, $stub);

    Product::factory()->create([
        'type' => 'simple', 'sku' => 'CSV-BRD-1', 'buy_price' => 100.00, 'brand_id' => 10,
    ]);
    CompetitorPrice::factory()->forSku('CSV-BRD-1')->create(['price_pennies_ex_vat' => 9000]);

    $out = app(PricingOpsReport::class)->csv('below_cost');

    expect($out['header'])->toBe([
        'SKU', 'Brand', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)',
    ])
        ->and($out['rows'])->toHaveCount(1)
        ->and($out['rows'][0][0])->toBe('CSV-BRD-1')
        ->and($out['rows'][0][1])->toBe('Yealink')
        ->and($out['filename'])->toStartWith('pricing-below_cost-');
});

it('csv(at_floor) also gets the Brand column with null brand rendered as empty string', function (): void {
    $stub = Mockery::mock(TaxonomyResolver::class);
    $stub->shouldReceive('allBrands')->andReturn([]); // no brands in the cache → all rows brand_name=null
    app()->instance(TaxonomyResolver::class, $stub);

    // cost £100 ex, competitor £103 → margin 3% (< 6%) → at floor; brand_id=99 (not in resolver) → null
    Product::factory()->create([
        'type' => 'simple', 'sku' => 'AFL-1', 'buy_price' => 100.00, 'brand_id' => 99,
    ]);
    CompetitorPrice::factory()->forSku('AFL-1')->create(['price_pennies_ex_vat' => 10300]);

    $out = app(PricingOpsReport::class)->csv('at_floor');

    expect($out['header'])->toBe([
        'SKU', 'Brand', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)',
    ])
        ->and($out['rows'][0][1])->toBe(''); // brand_id present but not in resolver → empty cell
});

it('csv(winnable) returns the legacy 5-column shape unchanged', function (): void {
    $stub = Mockery::mock(TaxonomyResolver::class);
    $stub->shouldReceive('allBrands')->andReturn([
        ['id' => 10, 'name' => 'Yealink'],
    ]);
    app()->instance(TaxonomyResolver::class, $stub);

    // cost £100 ex, competitor £120 → margin 20% → winnable; brand_id=10
    Product::factory()->create([
        'type' => 'simple', 'sku' => 'WIN-1', 'buy_price' => 100.00, 'brand_id' => 10,
    ]);
    CompetitorPrice::factory()->forSku('WIN-1')->create(['price_pennies_ex_vat' => 12000]);

    $out = app(PricingOpsReport::class)->csv('winnable');

    // Byte-identical to pre-Task-2 contract: no Brand column.
    expect($out['header'])->toBe([
        'SKU', 'Name', 'Our cost ex-VAT (£)', 'Lowest competitor ex-VAT (£)', 'Margin (%)',
    ])
        ->and($out['rows'])->toHaveCount(1)
        ->and($out['rows'][0][0])->toBe('WIN-1'); // first column is still SKU, no Brand pushed in
});
