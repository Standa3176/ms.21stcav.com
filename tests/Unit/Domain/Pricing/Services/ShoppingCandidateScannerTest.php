<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\ShoppingCandidateScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260722-shc — ShoppingCandidateScanner gate + ranking matrix
|--------------------------------------------------------------------------
|
| The scanner is the READ-ONLY predicate behind `products:shopping-candidates`.
| It is built ALONGSIDE AdCandidateScanner (which must not be modified — it
| powers the Ad Candidates page) and reuses its windowed-SQL patterns.
|
| Gates, in funnel order:
|   1. status = 'publish' AND type = 'simple'
|   2. non-empty sku AND buy_price > 0 AND sell_price > 0
|   3. margin (sell - buy) >= minMarginPence           (default 19900 = £199)
|   4. current fresh in-stock supplier offer            (snapshot stock > 0, 7d)
|   5. distinct competitor count >= minCompetitors      (default 2, 30d window)
|   6. has GTIN (products.ean non-empty)                unless allowMissingGtin
|
| Ranking: score = competitor_count × margin_pence (default), or margin, or
| competitors — every mode tie-breaks on margin desc then sku asc.
*/

/**
 * Seed one product with N distinct competitors and (optionally) a fresh
 * in-stock supplier offer snapshot.
 *
 * @param  array<int, int>  $competitorGrossPences  one entry per DISTINCT competitor
 * @param  array<string, mixed>  $extra  extra Product attributes
 */
function seedShoppingRow(
    string $sku,
    int $buyPence,
    int $sellPence,
    array $competitorGrossPences = [],
    int $stock = 5,
    ?string $ean = '5012345678900',
    bool $supplierFresh = true,
    bool $withSupplierOffer = true,
    array $extra = [],
): Product {
    $product = Product::factory()->create(array_merge([
        'sku' => $sku,
        'name' => "Product {$sku}",
        'type' => 'simple',
        'status' => 'publish',
        'ean' => $ean,
        'buy_price' => $buyPence / 100,
        'sell_price' => $sellPence / 100,
    ], $extra));

    foreach ($competitorGrossPences as $gross) {
        CompetitorPrice::factory()
            ->forSku($sku)
            ->create([
                'competitor_id' => Competitor::factory(),
                'price_pennies_ex_vat' => (int) round($gross / 1.2),
                'price_pennies_gross' => $gross,
            ]);
    }

    if ($withSupplierOffer) {
        SupplierOfferSnapshot::create([
            'sku' => strtolower(trim($sku)),
            'product_id' => $product->id,
            'supplier_id' => 'SUP-TEST',
            'supplier_name' => 'TestSupplier',
            'price' => $buyPence / 100,
            'stock' => $stock,
            'rrp' => $sellPence / 100,
            'recorded_at' => $supplierFresh ? today() : today()->subDays(10),
        ]);
    }

    return $product;
}

function shoppingSkus(array $result): array
{
    return array_map(static fn (array $r): string => $r['sku'], $result['rows']);
}

it('includes a fully-eligible product at defaults and computes its fields', function (): void {
    // sell £350 / buy £100 → margin £250 (25000p). 2 competitors, lowest £400.
    seedShoppingRow('GOOD-1', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 45000]);

    $result = app(ShoppingCandidateScanner::class)->scan();

    expect($result['rows'])->toHaveCount(1);

    $row = $result['rows'][0];
    expect($row['sku'])->toBe('GOOD-1')
        ->and($row['margin_pence'])->toBe(25000)
        // margin % of sell price = 25000 / 35000 = 71.42% → 7142 bps
        ->and($row['margin_pct_bps'])->toBe(7142)
        ->and($row['competitor_count'])->toBe(2)
        ->and($row['lowest_comp_pence'])->toBe(40000)
        // we sell at 35000 vs lowest 40000 → we BEAT by 5000p
        ->and($row['position'])->toBe('beat')
        ->and($row['delta_vs_lowest_pence'])->toBe(-5000)
        ->and($row['stock'])->toBe(5)
        ->and($row['has_gtin'])->toBeTrue()
        ->and($row['ean'])->toBe('5012345678900')
        ->and($row['score'])->toBe(2 * 25000);
});

it('reports our position as above when we are dearer than the lowest competitor', function (): void {
    seedShoppingRow('ABOVE-1', buyPence: 10000, sellPence: 45000, competitorGrossPences: [40000, 41000]);

    $row = app(ShoppingCandidateScanner::class)->scan()['rows'][0];

    expect($row['position'])->toBe('above')
        ->and($row['delta_vs_lowest_pence'])->toBe(5000);
});

it('GATE margin — excludes products below --min-margin-pence and includes them when the floor drops', function (): void {
    // margin £100 (10000p) — under the £199 default floor.
    seedShoppingRow('LOWMARGIN', buyPence: 10000, sellPence: 20000, competitorGrossPences: [25000, 26000]);

    $scanner = app(ShoppingCandidateScanner::class);

    expect(shoppingSkus($scanner->scan()))->not->toContain('LOWMARGIN')
        ->and($scanner->scan()['funnel']['dropped_below_min_margin'])->toBe(1)
        ->and(shoppingSkus($scanner->scan(minMarginPence: 9900)))->toContain('LOWMARGIN');
});

it('GATE competitors — excludes products with fewer than --min-competitors distinct competitors', function (): void {
    seedShoppingRow('ONECOMP', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000]);

    $scanner = app(ShoppingCandidateScanner::class);

    expect(shoppingSkus($scanner->scan()))->not->toContain('ONECOMP')
        ->and($scanner->scan()['funnel']['dropped_below_min_competitors'])->toBe(1)
        ->and(shoppingSkus($scanner->scan(minCompetitors: 1)))->toContain('ONECOMP');
});

it('GATE competitors — counts DISTINCT competitors, not price rows', function (): void {
    $product = seedShoppingRow('DUPCOMP', buyPence: 10000, sellPence: 35000, competitorGrossPences: []);

    // ONE competitor, THREE price rows on different days → competitor_count = 1.
    $competitor = Competitor::factory()->create();
    foreach ([0, 1, 2] as $daysAgo) {
        CompetitorPrice::factory()->forSku('DUPCOMP')->create([
            'competitor_id' => $competitor->id,
            'sku' => 'DUPCOMP-'.$daysAgo, // distinct sku column per row…
            'mpn' => 'DUPCOMP',           // …matched via mpn to the same key
            'price_pennies_ex_vat' => 30000,
            'price_pennies_gross' => 36000,
            'recorded_at' => now()->subDays($daysAgo),
        ]);
    }
    expect($product->exists)->toBeTrue();

    $result = app(ShoppingCandidateScanner::class)->scan(minCompetitors: 1);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['rows'][0]['competitor_count'])->toBe(1);
});

it('GATE competitors — ignores prices older than --competitor-window-days', function (): void {
    seedShoppingRow('STALECOMP', buyPence: 10000, sellPence: 35000, competitorGrossPences: []);

    foreach ([40000, 41000] as $gross) {
        CompetitorPrice::factory()->forSku('STALECOMP')->create([
            'competitor_id' => Competitor::factory(),
            'price_pennies_ex_vat' => (int) round($gross / 1.2),
            'price_pennies_gross' => $gross,
            'recorded_at' => now()->subDays(45),
        ]);
    }

    $scanner = app(ShoppingCandidateScanner::class);

    expect(shoppingSkus($scanner->scan()))->not->toContain('STALECOMP')
        ->and(shoppingSkus($scanner->scan(competitorWindowDays: 60)))->toContain('STALECOMP');
});

it('GATE gtin — excludes products without an EAN unless --allow-missing-gtin, which flags them', function (): void {
    seedShoppingRow('NOGTIN', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], ean: null);

    $scanner = app(ShoppingCandidateScanner::class);

    $strict = $scanner->scan();
    expect(shoppingSkus($strict))->not->toContain('NOGTIN')
        ->and($strict['funnel']['dropped_missing_gtin'])->toBe(1);

    $loose = $scanner->scan(allowMissingGtin: true);
    expect(shoppingSkus($loose))->toContain('NOGTIN')
        ->and($loose['rows'][0]['has_gtin'])->toBeFalse()
        ->and($loose['funnel']['dropped_missing_gtin'])->toBe(0);
});

it('GATE gtin — treats a whitespace-only EAN as missing', function (): void {
    seedShoppingRow('BLANKGTIN', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], ean: '   ');

    expect(shoppingSkus(app(ShoppingCandidateScanner::class)->scan()))->not->toContain('BLANKGTIN');
});

it('GATE status — excludes non-publish products', function (): void {
    seedShoppingRow('DRAFTED', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], extra: ['status' => 'draft']);

    $result = app(ShoppingCandidateScanner::class)->scan();

    expect(shoppingSkus($result))->not->toContain('DRAFTED')
        ->and($result['funnel']['dropped_not_publish_simple'])->toBe(1);
});

it('GATE type — excludes non-simple products', function (): void {
    seedShoppingRow('VARIABLE', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], extra: ['type' => 'variable']);

    $result = app(ShoppingCandidateScanner::class)->scan();

    expect(shoppingSkus($result))->not->toContain('VARIABLE')
        ->and($result['funnel']['dropped_not_publish_simple'])->toBe(1);
});

it('GATE price — excludes products without a positive buy and sell price', function (): void {
    seedShoppingRow('NOBUY', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], extra: ['buy_price' => null]);

    $result = app(ShoppingCandidateScanner::class)->scan();

    expect(shoppingSkus($result))->not->toContain('NOBUY')
        ->and($result['funnel']['dropped_no_price_or_sku'])->toBe(1);
});

it('GATE stock — excludes products with no current fresh in-stock supplier offer', function (): void {
    // zero stock
    seedShoppingRow('ZEROSTOCK', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], stock: 0);
    // stale snapshot (10 days old — outside the 7-day window)
    seedShoppingRow('STALESTOCK', buyPence: 10000, sellPence: 35000, competitorGrossPences: [42000, 43000], supplierFresh: false);
    // no snapshot at all
    seedShoppingRow('NOSTOCKROW', buyPence: 10000, sellPence: 35000, competitorGrossPences: [44000, 45000], withSupplierOffer: false);

    $result = app(ShoppingCandidateScanner::class)->scan();

    expect($result['rows'])->toBeEmpty()
        ->and($result['funnel']['dropped_no_fresh_stock'])->toBe(3);
});

it('produces a funnel whose counts add up to the total product count', function (): void {
    seedShoppingRow('F-GOOD', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000]);
    seedShoppingRow('F-DRAFT', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], extra: ['status' => 'draft']);
    seedShoppingRow('F-NOPRICE', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], extra: ['sell_price' => 0]);
    seedShoppingRow('F-MARGIN', buyPence: 10000, sellPence: 12000, competitorGrossPences: [40000, 41000]);
    seedShoppingRow('F-STOCK', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], stock: 0);
    seedShoppingRow('F-COMPS', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000]);
    seedShoppingRow('F-GTIN', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], ean: null);

    $f = app(ShoppingCandidateScanner::class)->scan()['funnel'];

    expect($f['products_total'])->toBe(7)
        ->and($f['dropped_not_publish_simple'])->toBe(1)
        ->and($f['dropped_no_price_or_sku'])->toBe(1)
        ->and($f['dropped_below_min_margin'])->toBe(1)
        ->and($f['dropped_no_fresh_stock'])->toBe(1)
        ->and($f['dropped_below_min_competitors'])->toBe(1)
        ->and($f['dropped_missing_gtin'])->toBe(1)
        ->and($f['eligible'])->toBe(1)
        ->and($f['returned'])->toBe(1);

    $sum = $f['dropped_not_publish_simple']
        + $f['dropped_no_price_or_sku']
        + $f['dropped_below_min_margin']
        + $f['dropped_no_fresh_stock']
        + $f['dropped_below_min_competitors']
        + $f['dropped_missing_gtin']
        + $f['eligible'];

    expect($sum)->toBe($f['products_total']);
});

it('RANK score — orders by competitor_count × margin_pence descending', function (): void {
    // A: 2 comps × 25000p margin = 50000
    seedShoppingRow('R-A', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000]);
    // B: 3 comps × 20000p margin = 60000  (highest score, lower margin)
    seedShoppingRow('R-B', buyPence: 10000, sellPence: 30000, competitorGrossPences: [40000, 41000, 42000]);
    // C: 2 comps × 30000p margin = 60000  (score tie with B → margin desc wins)
    seedShoppingRow('R-C', buyPence: 10000, sellPence: 40000, competitorGrossPences: [45000, 46000]);

    $skus = shoppingSkus(app(ShoppingCandidateScanner::class)->scan(sort: 'score'));

    expect($skus)->toBe(['R-C', 'R-B', 'R-A']);
});

it('RANK margin — orders by margin_pence descending', function (): void {
    seedShoppingRow('R-A', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000]);   // 25000
    seedShoppingRow('R-B', buyPence: 10000, sellPence: 30000, competitorGrossPences: [40000, 41000, 42000]); // 20000
    seedShoppingRow('R-C', buyPence: 10000, sellPence: 40000, competitorGrossPences: [45000, 46000]);   // 30000

    $skus = shoppingSkus(app(ShoppingCandidateScanner::class)->scan(sort: 'margin'));

    expect($skus)->toBe(['R-C', 'R-A', 'R-B']);
});

it('RANK competitors — orders by competitor_count descending, tie-broken by margin', function (): void {
    seedShoppingRow('R-A', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000]);   // 2 comps, 25000
    seedShoppingRow('R-B', buyPence: 10000, sellPence: 30000, competitorGrossPences: [40000, 41000, 42000]); // 3 comps, 20000
    seedShoppingRow('R-C', buyPence: 10000, sellPence: 40000, competitorGrossPences: [45000, 46000]);   // 2 comps, 30000

    $skus = shoppingSkus(app(ShoppingCandidateScanner::class)->scan(sort: 'competitors'));

    expect($skus)->toBe(['R-B', 'R-C', 'R-A']);
});

it('applies --limit to the shortlist while the funnel keeps the full eligible count', function (): void {
    seedShoppingRow('L-A', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000]);
    seedShoppingRow('L-B', buyPence: 10000, sellPence: 40000, competitorGrossPences: [45000, 46000]);
    seedShoppingRow('L-C', buyPence: 10000, sellPence: 45000, competitorGrossPences: [50000, 51000]);

    $result = app(ShoppingCandidateScanner::class)->scan(limit: 2);

    expect($result['rows'])->toHaveCount(2)
        ->and($result['funnel']['eligible'])->toBe(3)
        ->and($result['funnel']['returned'])->toBe(2);
});

it('resolves the brand name from local attributes_json without any Woo call', function (): void {
    seedShoppingRow('BRANDED', buyPence: 10000, sellPence: 35000, competitorGrossPences: [40000, 41000], extra: [
        'brand_id' => 77,
        'attributes_json' => [
            ['name' => 'Brand', 'value' => 'Yealink'],
            ['name' => 'Colour', 'value' => 'Black'],
        ],
    ]);

    $row = app(ShoppingCandidateScanner::class)->scan()['rows'][0];

    expect($row['brand'])->toBe('Yealink')
        ->and($row['brand_id'])->toBe(77);
});
