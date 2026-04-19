<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Widgets\BiggestMarginDeltasTable;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Products\Models\Product;

/**
 * Phase 5 Plan 04b Task 1 — W4 null-safety on BiggestMarginDeltasTable.
 *
 * Phase 3's RecomputePriceListener writes Product.sell_price asynchronously on
 * SupplierPriceChanged; newly-imported products may not have it yet. The
 * widget's query skips rows where `products.sell_price IS NULL` so ops see
 * only analysed products. Help text on the page explains "not yet analysed".
 */
it('widget query excludes products whose sell_price is NULL', function (): void {
    $competitor = Competitor::factory()->create();

    // Product 1: no sell_price yet (Phase 3 recompute hasn't run).
    $nullProduct = Product::factory()->create([
        'sku' => 'BMD-NULL-1',
        'sell_price' => null,
        'buy_price' => 10.00,
    ]);
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $nullProduct->sku,
        'price_pennies_gross' => 5000,
        'price_pennies_ex_vat' => 4166,
        'recorded_at' => now(),
    ]);

    // Product 2: fully recomputed.
    $ok = Product::factory()->create([
        'sku' => 'BMD-OK-1',
        'sell_price' => 40.00,
        'buy_price' => 10.00,
    ]);
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $ok->sku,
        'price_pennies_gross' => 3500,
        'price_pennies_ex_vat' => 2916,
        'recorded_at' => now(),
    ]);

    $skus = BiggestMarginDeltasTable::baseQuery()->pluck('competitor_prices.sku')->all();

    expect($skus)->toContain('BMD-OK-1');
    expect($skus)->not->toContain('BMD-NULL-1');
});

it('widget query orders rows by absolute delta descending', function (): void {
    $competitor = Competitor::factory()->create();

    // Small delta: product priced 10.00; competitor 9.90 — delta 10p.
    $small = Product::factory()->create(['sku' => 'BMD-SMALL', 'sell_price' => 10.00, 'buy_price' => 5.00]);
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $small->sku,
        'price_pennies_gross' => 1188,
        'price_pennies_ex_vat' => 990,
        'recorded_at' => now(),
    ]);

    // Big delta: product priced 100.00; competitor 50.00 — delta £50 (5000p).
    $big = Product::factory()->create(['sku' => 'BMD-BIG', 'sell_price' => 100.00, 'buy_price' => 40.00]);
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $big->sku,
        'price_pennies_gross' => 6000,
        'price_pennies_ex_vat' => 5000,
        'recorded_at' => now(),
    ]);

    $first = BiggestMarginDeltasTable::baseQuery()->first();
    expect($first)->not->toBeNull();
    expect((string) $first->sku)->toBe('BMD-BIG');
});

it('widget query deduplicates to the latest CompetitorPrice per (competitor, sku) pair', function (): void {
    $competitor = Competitor::factory()->create();
    $product = Product::factory()->create(['sku' => 'BMD-DEDUP', 'sell_price' => 50.00, 'buy_price' => 20.00]);

    // Two rows, same (competitor, sku): widget must keep only the latest.
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $product->sku,
        'price_pennies_gross' => 4800,
        'price_pennies_ex_vat' => 4000,
        'recorded_at' => now()->subDays(5),
    ]);
    CompetitorPrice::factory()->create([
        'competitor_id' => $competitor->id,
        'sku' => $product->sku,
        'price_pennies_gross' => 4500,
        'price_pennies_ex_vat' => 3750,
        'recorded_at' => now(),
    ]);

    $matching = BiggestMarginDeltasTable::baseQuery()
        ->where('competitor_prices.sku', 'BMD-DEDUP')
        ->get();

    expect($matching)->toHaveCount(1);
});
