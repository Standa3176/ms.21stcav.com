<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\AdCandidateScanner;
use App\Domain\Pricing\Services\ShoppingCandidateScanner;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260927-p41 — the margin basis, pinned by the loss-maker case
|--------------------------------------------------------------------------
|
| Both ad scanners computed margin as `sell_price - buy_price`. `sell_price` is
| VAT-INCLUSIVE (PriceCalculator::compute returns gross) and `buy_price` is the
| supplier's EX-VAT cost, so that expression counted the VAT we collect for HMRC
| as profit — overstating every margin by sell/6, i.e. 16.67% of the sell price.
|
| Why it mattered more than a rounding error:
|
|   sell(inc)   buy(net)   reported    TRUE cash
|   £1,200      £980       £220.00     £20.00
|   £5,000      £4,200     £800.00     -£33.33     <- a LOSS, ranked as a winner
|
| And sell/6 alone clears the £199 default floor at any sell price above £1,194,
| so across the expensive half of an AV catalogue the floor was filtering on
| PRICE, not profit. An ad shortlist built on it would spend Google money to sell
| units at a loss.
|
| The existing gate/ranking matrices did NOT catch this: re-basing their fixtures
| makes the numbers correct, but those tests only assert that an eligible row
| stays eligible, and a gross-basis margin is always LARGER — so reverting the
| fix left 21 of 26 green. The case below is the one that has to fail, and the
| only one that distinguishes the two formulas by verdict rather than by value.
*/

/**
 * A product that LOOKS profitable on the gross basis and is a real loss on the
 * net one: we sell at £5,000 inc VAT against a £4,200 ex-VAT cost.
 *
 *   gross basis: 500000 - 420000 = +£800   → passes a £199 floor
 *   true  basis: 416667 - 420000 = -£33.33 → a loss on every unit
 */
function seedVatTrapProduct(string $sku): Product
{
    $sellGross = 500000;
    $buyNet = 420000;

    $product = Product::factory()->create([
        'sku' => $sku,
        'name' => "Trap {$sku}",
        'type' => 'simple',
        'status' => 'publish',
        'ean' => '5012345678900',
        'buy_price' => $buyNet / 100,
        'sell_price' => $sellGross / 100,
    ]);

    // Two competitors, both dearer than us, so no competitor or beat gate can
    // be the reason the row drops — the margin basis has to be.
    foreach ([520000, 540000] as $competitorGross) {
        CompetitorPrice::factory()->forSku($sku)->create([
            'competitor_id' => Competitor::factory(),
            'price_pennies_ex_vat' => (int) round($competitorGross / 1.2),
            'price_pennies_gross' => $competitorGross,
        ]);
    }

    SupplierOfferSnapshot::create([
        'sku' => strtolower($sku),
        'product_id' => $product->id,
        'supplier_id' => 'SUP-TEST',
        'supplier_name' => 'TestSupplier',
        'price' => $buyNet / 100,
        'stock' => 5,
        'rrp' => $sellGross / 100,
        'recorded_at' => today(),
    ]);

    return $product;
}

beforeEach(function (): void {
    // Keep the brand map deterministic — no WooClient hit.
    $fake = new class extends TaxonomyResolver
    {
        public function __construct() {}

        public function allBrands(): array
        {
            return [];
        }
    };
    app()->instance(TaxonomyResolver::class, $fake);
});

it('ShoppingCandidateScanner excludes a product that only profits on the gross basis', function (): void {
    seedVatTrapProduct('VAT-TRAP-1');

    $result = app(ShoppingCandidateScanner::class)->scan();

    expect(array_column($result['rows'], 'sku'))->not->toContain('VAT-TRAP-1')
        // and it must be the MARGIN gate that dropped it, not stock or competitors
        ->and($result['funnel']['dropped_below_min_margin'])->toBe(1)
        ->and($result['funnel']['dropped_no_fresh_stock'])->toBe(0)
        ->and($result['funnel']['dropped_below_min_competitors'])->toBe(0);
});

it('AdCandidateScanner excludes the same product', function (): void {
    seedVatTrapProduct('VAT-TRAP-2');

    // beatRequired off so the ONLY thing that can exclude it is the margin.
    $rows = app(AdCandidateScanner::class)->scan(beatRequired: false);

    expect($rows->pluck('sku')->all())->not->toContain('VAT-TRAP-2');
});

it('reports the loss as negative rather than hiding it, when the floor allows it through', function (): void {
    seedVatTrapProduct('VAT-TRAP-3');

    // A floor low enough to admit a loss-maker — the operator override. The row
    // must then state the loss plainly.
    $rows = app(AdCandidateScanner::class)->scan(
        minMarginPence: -100000,
        beatRequired: false,
    );

    $row = $rows->firstWhere('sku', 'VAT-TRAP-3');

    expect($row)->not->toBeNull()
        ->and($row->margin_pence)->toBeLessThan(0)
        // -£33.33 on a £5,000 sale
        ->and($row->margin_pence)->toBe(416667 - 420000)
        ->and($row->net_sell_price_pence)->toBe(416667)
        // and the old figure is retained so an older export can be reconciled
        ->and($row->margin_pence_gross_basis)->toBe(80000);
});

it('strips VAT via PriceCalculator rather than a hardcoded divisor', function (): void {
    // The rate and the rounding mode live in ONE place. A literal 1.2 in a
    // scanner is how the two drift apart when the VAT rate next changes.
    foreach ([
        'app/Domain/Pricing/Services/AdCandidateScanner.php',
        'app/Domain/Pricing/Services/ShoppingCandidateScanner.php',
    ] as $file) {
        $source = file_get_contents(base_path($file));

        expect($source)->toContain('$this->prices->stripVat($sellPence)')
            ->and($source)->toContain('$marginPence = $netSellPence - $buyPence;');
    }
});

it('the margin-opportunity agent tool ranks and filters on the net basis too', function (): void {
    // Same bug, different surface: ordering by (sell_price - buy_price) is
    // monotonic in price once VAT is in the sell, so the "biggest margin
    // opportunities" were just the dearest products.
    $source = file_get_contents(base_path('app/Domain/Agents/Tools/Marketing/ReadMarginOpportunityTool.php'));

    expect($source)->toContain("sell_price / '.self::VAT_DIVISOR.' - buy_price) DESC")
        ->and($source)->toContain("sell_price / '.self::VAT_DIVISOR.' > buy_price")
        ->and($source)->not->toContain("orderByRaw('(sell_price - buy_price) DESC')");
});
