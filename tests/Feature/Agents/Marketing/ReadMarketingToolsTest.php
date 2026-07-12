<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-01 Task 1 — Marketing read tools (advice-only)
|--------------------------------------------------------------------------
|
| read_ga4_channel_performance aggregates ga_channel_metrics_daily over 30d
| by channel_group + campaign; read_margin_opportunity surfaces top
| high-margin in-stock products + competitor position. Both extend
| TruncatingTool: 3 KB soft cap + _truncated hint when over cap.
*/

use App\Domain\Agents\Tools\Marketing\ReadGa4ChannelPerformanceTool;
use App\Domain\Agents\Tools\Marketing\ReadMarginOpportunityTool;
use App\Domain\Agents\Tools\TruncatingTool;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Domain\Products\Models\Product;

function seedGaRow(array $overrides = []): GaChannelMetric
{
    return GaChannelMetric::create(array_merge([
        'date' => now()->subDays(3)->toDateString(),
        'channel_group' => 'Paid Search',
        'source_medium' => 'google / cpc',
        'campaign' => 'Brand UK',
        'sessions' => 100,
        'key_events' => 10,
        'transactions' => 3,
        'purchase_revenue_pennies' => 120000,
        'pulled_at' => now(),
    ], $overrides));
}

it('read_ga4_channel_performance extends TruncatingTool + uses read_ prefix', function () {
    $tool = app(ReadGa4ChannelPerformanceTool::class);
    expect($tool)->toBeInstanceOf(TruncatingTool::class);
    expect($tool->name())->toBe('read_ga4_channel_performance');
});

it('read_ga4_channel_performance aggregates by channel_group + campaign, revenue-first, pennies→£', function () {
    // Two rows, same grain — should aggregate into one channel with summed metrics.
    seedGaRow(['date' => now()->subDays(2)->toDateString(), 'sessions' => 100, 'transactions' => 3, 'purchase_revenue_pennies' => 120000]);
    seedGaRow(['date' => now()->subDays(1)->toDateString(), 'sessions' => 50, 'transactions' => 2, 'purchase_revenue_pennies' => 80000]);
    // A different, lower-revenue channel.
    seedGaRow(['channel_group' => 'Organic Search', 'campaign' => null, 'source_medium' => 'google / organic', 'sessions' => 500, 'transactions' => 1, 'purchase_revenue_pennies' => 5000]);

    $json = (new ReflectionMethod(ReadGa4ChannelPerformanceTool::class, 'execute'));
    $json->setAccessible(true);
    $out = json_decode($json->invoke(app(ReadGa4ChannelPerformanceTool::class)), true);

    expect($out['window_days'])->toBe(30);
    expect($out['channels'])->toHaveCount(2);

    // Revenue-first ordering: Paid Search (£2000) leads Organic (£50).
    $first = $out['channels'][0];
    expect($first['channel_group'])->toBe('Paid Search');
    expect($first['campaign'])->toBe('Brand UK');
    expect($first['sessions'])->toBe(150);       // 100 + 50
    expect($first['transactions'])->toBe(5);      // 3 + 2
    expect((float) $first['revenue_gbp'])->toEqual(2000.0);  // 200000 pennies

    expect($out['channels'][1]['channel_group'])->toBe('Organic Search');
    expect($out['channels'][1]['campaign'])->toBeNull();
});

it('read_ga4_channel_performance excludes rows older than 30 days', function () {
    seedGaRow(['date' => now()->subDays(45)->toDateString()]);

    $m = new ReflectionMethod(ReadGa4ChannelPerformanceTool::class, 'execute');
    $m->setAccessible(true);
    $out = json_decode($m->invoke(app(ReadGa4ChannelPerformanceTool::class)), true);

    expect($out['channels'])->toBeEmpty();
});

it('read_ga4_channel_performance defaults to a 30-day window that excludes a ~100-day-old row', function () {
    // Default config (30) — a ~100-day-old row is outside the window.
    seedGaRow(['date' => now()->subDays(100)->toDateString()]);

    $m = new ReflectionMethod(ReadGa4ChannelPerformanceTool::class, 'execute');
    $m->setAccessible(true);
    $out = json_decode($m->invoke(app(ReadGa4ChannelPerformanceTool::class)), true);

    expect($out['window_days'])->toBe(30);
    expect($out['channels'])->toBeEmpty();
});

it('read_ga4_channel_performance reads its window from config — 120 includes a ~100-day-old row', function () {
    config()->set('agents.ad_optimisation.data_lookback_days', 120);

    // ~40-day-old and ~100-day-old rows — both inside a 120-day window,
    // and the 100-day row would be EXCLUDED under the 30-day default.
    seedGaRow(['channel_group' => 'Paid Search', 'campaign' => 'Recent', 'date' => now()->subDays(40)->toDateString(), 'purchase_revenue_pennies' => 200000]);
    seedGaRow(['channel_group' => 'Organic Search', 'campaign' => 'Historic', 'source_medium' => 'google / organic', 'date' => now()->subDays(100)->toDateString(), 'purchase_revenue_pennies' => 50000]);

    $m = new ReflectionMethod(ReadGa4ChannelPerformanceTool::class, 'execute');
    $m->setAccessible(true);
    $out = json_decode($m->invoke(app(ReadGa4ChannelPerformanceTool::class)), true);

    expect($out['window_days'])->toBe(120);
    expect($out['channels'])->toHaveCount(2);
    expect(collect($out['channels'])->pluck('campaign')->all())->toContain('Historic');
});

it('read_ga4_channel_performance caps output + sets _truncated when over 3 KB', function () {
    // Seed many distinct grains so the JSON exceeds the 3 KB soft cap.
    for ($i = 0; $i < 120; $i++) {
        seedGaRow([
            'channel_group' => 'Channel '.$i,
            'campaign' => 'Campaign long name number '.$i,
            'source_medium' => 'src '.$i,
            'purchase_revenue_pennies' => 100000 - $i,
        ]);
    }

    $m = new ReflectionMethod(ReadGa4ChannelPerformanceTool::class, 'execute');
    $m->setAccessible(true);
    $raw = $m->invoke(app(ReadGa4ChannelPerformanceTool::class));
    $out = json_decode($raw, true);

    expect(strlen($raw))->toBeLessThanOrEqual(3072);
    expect($out['_truncated'])->toBeTrue();
    expect($out['_total_available'])->toBe(120);
});

it('read_margin_opportunity extends TruncatingTool + uses read_ prefix', function () {
    $tool = app(ReadMarginOpportunityTool::class);
    expect($tool)->toBeInstanceOf(TruncatingTool::class);
    expect($tool->name())->toBe('read_margin_opportunity');
});

it('read_margin_opportunity ranks by margin, attaches competitor position + demand', function () {
    $high = Product::factory()->create([
        'sku' => 'MARGIN-HIGH', 'name' => 'High margin bar',
        'status' => 'publish', 'stock_status' => 'instock',
        'buy_price' => 100, 'sell_price' => 500, 'last_sales_count_90d' => 27,
    ]);
    $low = Product::factory()->create([
        'sku' => 'MARGIN-LOW', 'name' => 'Low margin cable',
        'status' => 'publish', 'stock_status' => 'instock',
        'buy_price' => 90, 'sell_price' => 100, 'last_sales_count_90d' => 5,
    ]);
    // Excluded — out of stock.
    Product::factory()->create([
        'sku' => 'MARGIN-OOS', 'status' => 'publish', 'stock_status' => 'outofstock',
        'buy_price' => 10, 'sell_price' => 900,
    ]);

    // Competitor sightings for the high-margin SKU (two distinct competitors).
    CompetitorPrice::factory()->forSku('MARGIN-HIGH')->create(['price_pennies_ex_vat' => 46000, 'price_pennies_gross' => 55200, 'recorded_at' => now()->subDays(2)]);
    CompetitorPrice::factory()->forSku('MARGIN-HIGH')->create(['price_pennies_ex_vat' => 48000, 'price_pennies_gross' => 57600, 'recorded_at' => now()->subDays(1)]);

    $m = new ReflectionMethod(ReadMarginOpportunityTool::class, 'execute');
    $m->setAccessible(true);
    $out = json_decode($m->invoke(app(ReadMarginOpportunityTool::class)), true);

    // Only in-stock products; margin-first ordering.
    expect(collect($out['products'])->pluck('sku')->all())->toBe(['MARGIN-HIGH', 'MARGIN-LOW']);

    $top = $out['products'][0];
    expect((float) $top['margin_gbp'])->toEqual(400.0);
    expect($top['sales_90d'])->toBe(27);
    expect((float) $top['min_competitor_price_ex_vat_gbp'])->toEqual(460.0);
    expect($top['competitor_count'])->toBe(2);

    // SKU with no competitor sightings reports null + 0.
    expect($out['products'][1]['min_competitor_price_ex_vat_gbp'])->toBeNull();
    expect($out['products'][1]['competitor_count'])->toBe(0);
});

it('read_margin_opportunity caps output + sets _truncated when over 3 KB', function () {
    for ($i = 0; $i < 30; $i++) {
        Product::factory()->create([
            'sku' => 'MARGIN-BULK-'.$i,
            'name' => 'A fairly long product display name to inflate payload '.$i,
            'status' => 'publish', 'stock_status' => 'instock',
            'buy_price' => 10, 'sell_price' => 500 - $i,
        ]);
    }

    $m = new ReflectionMethod(ReadMarginOpportunityTool::class, 'execute');
    $m->setAccessible(true);
    $raw = $m->invoke(app(ReadMarginOpportunityTool::class));
    $out = json_decode($raw, true);

    expect(strlen($raw))->toBeLessThanOrEqual(3072);
    expect($out['_truncated'])->toBeTrue();
});
