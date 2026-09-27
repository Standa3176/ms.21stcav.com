<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\AdCandidateScanner;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-pys — AdCandidateScanner unit test (5-row matrix)
|--------------------------------------------------------------------------
|
| Validates the "golden ad target" predicate that BOTH the AdCandidatesPage
| AND the BackfillMerchantFeedCommand share. Single SQL surface — drift is
| structurally impossible.
|
| ── Fixture basis (260927-p41) ──
| A real product's `sell_price` is VAT-INCLUSIVE and `buy_price` is the
| supplier's EX-VAT cost, so the scanner strips VAT off the sell before
| subtracting. Every price the cases below pass is therefore NET, and
| seedAdCandidateRow() adds VAT on the way in — for the product's sell_price and
| for each competitor's gross price alike.
|
| That is what the matrix always MEANT: "£250 margin" is the true margin only on
| a net basis. Scaling both sides preserves every net-vs-net relation, so
| beat_pct_bps (a ratio) is unchanged and each row keeps its verdict.
|
| 5-row matrix (defaults: minMarginPence=19900, stockRequired=true,
|                          beatRequired=true):
|
|   A — £250 margin, in stock, undercuts comp  → INCLUDED at defaults
|   B — £250 margin, in stock, ABOVE comp +£20 → EXCLUDED unless beatRequired=false
|   C — £250 margin, NO supplier stock 7d, undercuts comp
|                                              → EXCLUDED unless stockRequired=false
|   D — £100 margin, in stock, undercuts comp  → EXCLUDED at default min margin;
|                                                INCLUDED when minMarginPence=9900
|   E — £250 margin, brand=X, in stock, undercuts comp
|                                              → INCLUDED when brandIds=[X];
|                                                EXCLUDED when brandIds=[Y]
|
| TaxonomyResolver is stubbed to keep the brand-name map deterministic
| (no WooClient hit) — Pricing depends on ProductAutoCreate per the
| existing 260606-rld allow-list.
*/

/**
 * Bind a TaxonomyResolver fake that returns a fixed brand-id → name map.
 * AdCandidateScanner reads `allBrands()` once before its row decoration loop
 * (cache-aware in production; the fake is deterministic for tests).
 *
 * @param  array<int, array{id:int, name:string}>  $brands
 */
function bindAdCandidateBrandTerms(array $brands): void
{
    $taxonomyFake = new class($brands) extends TaxonomyResolver
    {
        public function __construct(
            /** @var array<int, array{id:int, name:string}> */
            private array $brandsList,
        ) {
            // Skip parent constructor — no WooClient hit needed in tests.
        }

        public function allBrands(): array
        {
            return $this->brandsList;
        }
    };
    app()->instance(TaxonomyResolver::class, $taxonomyFake);
}

/** Gross (VAT-inclusive) pennies from a net figure — mirrors PriceCalculator::addVat. */
function adGrossFromNet(int $netPence): int
{
    return (int) round($netPence * 1.2);
}

/**
 * Seed a Product with a synthetic supplier offer snapshot and a competitor
 * price. PRICES IN ARE NET. Returns the Product for assertion convenience.
 *
 * @param  array<string, mixed>  $extra  extra Product attributes
 */
function seedAdCandidateRow(
    string $sku,
    int $buyPence,
    int $sellPence,
    int $compNetPence,
    int $stock,
    ?int $brandId = null,
    bool $supplierFresh = true,
    array $extra = [],
): Product {
    $product = Product::factory()->create(array_merge([
        'sku' => $sku,
        'name' => "Product {$sku}",
        'type' => 'simple',
        'status' => 'publish',
        'buy_price' => $buyPence / 100,
        // NET in, GROSS stored — see the fixture-basis note above.
        'sell_price' => adGrossFromNet($sellPence) / 100,
        'brand_id' => $brandId,
    ], $extra));

    // Competitor — store both ex-vat and gross; scanner reads gross.
    CompetitorPrice::factory()
        ->forSku($sku)
        ->create([
            'price_pennies_ex_vat' => $compNetPence,
            'price_pennies_gross' => adGrossFromNet($compNetPence),
        ]);

    // Supplier offer snapshot — fresh (today) or stale (10d ago) to
    // exercise the 7-day window in the scanner.
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

    return $product;
}

beforeEach(function (): void {
    // Default brand-name map for the matrix. Tests override per-case.
    bindAdCandidateBrandTerms([
        ['id' => 10, 'name' => 'BrandX'],
        ['id' => 20, 'name' => 'BrandY'],
    ]);
});

it('Row A — £250 margin + in stock + undercuts comp → INCLUDED at defaults', function (): void {
    // sell=£200 (20000p), buy=£175 (17500p) → margin £25 (2500p) — NOT golden.
    // Use £350 sell, £100 buy → margin £250 (25000p), comp gross £400 (40000p) → we beat by £50.
    seedAdCandidateRow('A-IN', buyPence: 10000, sellPence: 35000, compNetPence: 40000, stock: 5);

    $rows = app(AdCandidateScanner::class)->scan();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->sku)->toBe('A-IN');
});

it('Row B — above-competitor row is EXCLUDED when beatRequired=true and INCLUDED when false', function (): void {
    // sell £420, buy £170 → margin £250. Comp gross £400 → we are ABOVE comp by £20.
    seedAdCandidateRow('B-ABOVE', buyPence: 17000, sellPence: 42000, compNetPence: 40000, stock: 5);

    $defaults = app(AdCandidateScanner::class)->scan();
    expect($defaults->pluck('sku')->all())->not->toContain('B-ABOVE');

    $noBeat = app(AdCandidateScanner::class)->scan(beatRequired: false);
    expect($noBeat->pluck('sku')->all())->toContain('B-ABOVE');
});

it('Row C — stale supplier snapshot (>7d) is EXCLUDED when stockRequired and INCLUDED otherwise', function (): void {
    seedAdCandidateRow(
        'C-STALE',
        buyPence: 10000,
        sellPence: 35000,
        compNetPence: 40000,
        stock: 5,
        supplierFresh: false, // recorded 10 days ago — outside 7d window
    );

    $defaults = app(AdCandidateScanner::class)->scan();
    expect($defaults->pluck('sku')->all())->not->toContain('C-STALE');

    $noStock = app(AdCandidateScanner::class)->scan(stockRequired: false);
    expect($noStock->pluck('sku')->all())->toContain('C-STALE');
});

it('Row D — £100 margin is EXCLUDED at default minMargin and INCLUDED when threshold drops', function (): void {
    // sell £200, buy £100 → margin £100 (10000p). Default minMarginPence=19900 excludes.
    // minMarginPence=9900 includes.
    seedAdCandidateRow('D-LOW', buyPence: 10000, sellPence: 20000, compNetPence: 25000, stock: 5);

    $defaults = app(AdCandidateScanner::class)->scan();
    expect($defaults->pluck('sku')->all())->not->toContain('D-LOW');

    $lowMargin = app(AdCandidateScanner::class)->scan(minMarginPence: 9900);
    expect($lowMargin->pluck('sku')->all())->toContain('D-LOW');
});

it('Row E — brand-filter narrows to only the requested brand', function (): void {
    // Three rows: one brand=10 (X), one brand=20 (Y), one brand=null.
    seedAdCandidateRow('E-X', buyPence: 10000, sellPence: 35000, compNetPence: 40000, stock: 5, brandId: 10);
    seedAdCandidateRow('E-Y', buyPence: 10000, sellPence: 35000, compNetPence: 40000, stock: 5, brandId: 20);
    seedAdCandidateRow('E-NONE', buyPence: 10000, sellPence: 35000, compNetPence: 40000, stock: 5, brandId: null);

    $all = app(AdCandidateScanner::class)->scan();
    expect($all->pluck('sku')->all())->toEqualCanonicalizing(['E-X', 'E-Y', 'E-NONE']);

    $onlyX = app(AdCandidateScanner::class)->scan(brandIds: [10]);
    expect($onlyX->pluck('sku')->all())->toBe(['E-X']);

    $onlyY = app(AdCandidateScanner::class)->scan(brandIds: [20]);
    expect($onlyY->pluck('sku')->all())->toBe(['E-Y']);
});

it('returned rows expose the full decorated shape (sku, name, brand_name, prices, margin, stock, best_supplier)', function (): void {
    seedAdCandidateRow('SHAPE-1', buyPence: 10000, sellPence: 35000, compNetPence: 40000, stock: 7, brandId: 10);

    $rows = app(AdCandidateScanner::class)->scan();

    expect($rows)->toHaveCount(1);
    $row = $rows->first();

    expect($row->sku)->toBe('SHAPE-1');
    expect($row->name)->toBe('Product SHAPE-1');
    expect($row->brand_id)->toBe(10);
    expect($row->brand_name)->toBe('BrandX');
    // sell_price_pence is the stored GROSS price; net_sell_price_pence is the
    // figure the margin is computed from.
    expect($row->sell_price_pence)->toBe(adGrossFromNet(35000));
    expect($row->net_sell_price_pence)->toBe(35000);
    expect($row->buy_price_pence)->toBe(10000);
    expect($row->margin_pence)->toBe(25000);
    // The pre-260927-p41 figure, kept on the row so an older export reconciles:
    // gross 42000 - net cost 10000 = 32000. £70 of that "£320" was VAT.
    expect($row->margin_pence_gross_basis)->toBe(32000);
    expect($row->lowest_comp_pence)->toBe(adGrossFromNet(40000));
    // beat_pct_bps is a RATIO, so scaling both sides leaves it unchanged:
    // (42000-48000) * 10000 / 48000 = -1250
    expect($row->beat_pct_bps)->toBe(-1250);
    expect($row->stock)->toBe(7);
    expect($row->best_supplier)->toBe('TestSupplier');
    expect((int) $row->product_id)->toBe(Product::where('sku', 'SHAPE-1')->value('id'));
});
