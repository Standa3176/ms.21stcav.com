<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Contracts\LowestCompetitorSource;
use App\Domain\Pricing\Services\ShoppingCandidateScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 261006-r3n — the ad scanners read GUARDED competitor prices
|--------------------------------------------------------------------------
|
| Both ad scanners used to hand-roll a windowed query against competitor_prices.
| They applied the recency window and none of the three guards the pricing job
| applies, so the first live price-position run showed a £299.00 "lowest
| competitor" for a product we sell at £6,938 — a quarantined feed row.
|
| For a report that is a wrong verdict. For the ad shortlist it is worse: the
| shortlist gates on "do we undercut the lowest competitor", so a bad feed row
| decides which SKUs get advertised, and budget follows the answer.
|
| Pricing may not depend on the Competitor domain (deptrac: the arrow runs the
| other way), which is WHY the private queries existed. Fixed by inverting —
| Pricing owns LowestCompetitorSource, Competitor implements it.
*/

function seedGuardRow(string $sku, int $buyPence, int $netSellPence): Product
{
    $product = Product::factory()->create([
        'sku' => $sku,
        'name' => "Guarded {$sku}",
        'type' => 'simple',
        'status' => 'publish',
        'ean' => '5012345678900',
        'buy_price' => $buyPence / 100,
        'sell_price' => (int) round($netSellPence * 1.2) / 100,
    ]);

    SupplierOfferSnapshot::create([
        'sku' => strtolower($sku),
        'product_id' => $product->id,
        'supplier_id' => 'SUP-G',
        'supplier_name' => 'GuardSupplier',
        'price' => $buyPence / 100,
        'stock' => 5,
        'rrp' => $netSellPence / 100,
        'recorded_at' => today(),
    ]);

    return $product;
}

function addCompetitorPrice(string $sku, int $grossPence, array $attrs = []): void
{
    CompetitorPrice::factory()->forSku($sku)->create(array_merge([
        'competitor_id' => Competitor::factory(),
        'price_pennies_ex_vat' => (int) round($grossPence / 1.2),
        'price_pennies_gross' => $grossPence,
    ], $attrs));
}

it('excludes a quarantined competitor row from the bulk lookup', function (): void {
    addCompetitorPrice('GUARD-1', 50000);
    // The Neat Board Pro shape: an absurd price flagged by the feed-jump
    // detector. Kept for audit, invisible to pricing — and now to the scanners.
    addCompetitorPrice('GUARD-1', 299, ['is_price_anomaly' => true]);

    $bulk = app(LowestCompetitorSource::class)->bulkCurrentByKey(30);

    expect($bulk['guard-1']['lowest'])->toBe(50000)
        ->and($bulk['guard-1']['competitors'])->toBe(1);
});

it('excludes a competitor paused for pricing from the bulk lookup', function (): void {
    addCompetitorPrice('GUARD-2', 50000);

    $paused = Competitor::factory()->create(['pricing_paused_until' => now()->addWeek()]);
    addCompetitorPrice('GUARD-2', 20000, ['competitor_id' => $paused->id]);

    $bulk = app(LowestCompetitorSource::class)->bulkCurrentByKey(30);

    expect($bulk['guard-2']['lowest'])->toBe(50000);
});

it('counts each competitor once however many rows it published', function (): void {
    $competitor = Competitor::factory()->create();
    foreach ([0, 1, 2, 3] as $daysAgo) {
        CompetitorPrice::factory()->forSku('GUARD-3')->create([
            'competitor_id' => $competitor->id,
            'price_pennies_ex_vat' => 40000,
            'price_pennies_gross' => 48000,
            'recorded_at' => now()->subDays($daysAgo),
        ]);
    }

    $bulk = app(LowestCompetitorSource::class)->bulkCurrentByKey(30);

    expect($bulk['guard-3']['competitors'])->toBe(1);
});

it('takes the LATEST row per competitor, not the cheapest it ever published', function (): void {
    $competitor = Competitor::factory()->create();
    // Cheap last month, dearer today. "Current" means today.
    CompetitorPrice::factory()->forSku('GUARD-4')->create([
        'competitor_id' => $competitor->id,
        'price_pennies_ex_vat' => 10000, 'price_pennies_gross' => 12000,
        'recorded_at' => now()->subDays(20),
    ]);
    CompetitorPrice::factory()->forSku('GUARD-4')->create([
        'competitor_id' => $competitor->id,
        'price_pennies_ex_vat' => 40000, 'price_pennies_gross' => 48000,
        'recorded_at' => now()->subDay(),
    ]);

    $bulk = app(LowestCompetitorSource::class)->bulkCurrentByKey(30);

    expect($bulk['guard-4']['lowest'])->toBe(48000);
});

it('keeps a quarantined row out of the SHOPPING SHORTLIST, not just the lookup', function (): void {
    // The shortlist gates on "we undercut the lowest competitor". Against the
    // £2.99 quarantined row we look expensive and drop out; against the real
    // market price we are cheapest and belong on the list.
    seedGuardRow('SHORT-1', 10000, 30000);           // cost £100 net, sell £300 net
    addCompetitorPrice('SHORT-1', 42000);            // real rival, £420 gross
    addCompetitorPrice('SHORT-1', 299, ['is_price_anomaly' => true]);
    addCompetitorPrice('SHORT-1', 43000);            // second real rival

    $result = app(ShoppingCandidateScanner::class)->scan(minMarginPence: 1000);

    expect(array_column($result['rows'], 'sku'))->toContain('SHORT-1');

    $row = collect($result['rows'])->firstWhere('sku', 'SHORT-1');
    expect($row['lowest_comp_pence'])->toBe(42000)
        ->and($row['competitor_count'])->toBe(2)
        ->and($row['position'])->toBe('beat');
});

it('leaves neither scanner hand-rolling the guards', function (): void {
    // The regression that matters: a future edit re-adding a private query would
    // silently put unguarded prices back into a spending decision.
    foreach ([
        'app/Domain/Pricing/Services/ShoppingCandidateScanner.php',
        'app/Domain/Pricing/Services/AdCandidateScanner.php',
    ] as $file) {
        $source = file_get_contents(base_path($file));

        expect($source)->toContain('$this->competitorPrices->bulkCurrentByKey(')
            // The PRICE reduction specifically — "latest row per competitor,
            // then the minimum". ShoppingCandidateScanner still queries
            // competitor_prices for daysSeenByKey(), which counts SIGHTINGS and
            // is deliberately unguarded; see the note on that method.
            ->and($source)->not->toContain('ROW_NUMBER() OVER (PARTITION BY competitor_id');
    }
});

it('keeps Pricing independent of the Competitor domain', function (): void {
    // The reason the interface exists. Naming the concrete resolver inside a
    // Pricing class is a deptrac violation, so pin the inversion here, where the
    // reasoning is written down rather than only in a config file.
    foreach ([
        'app/Domain/Pricing/Services/ShoppingCandidateScanner.php',
        'app/Domain/Pricing/Services/AdCandidateScanner.php',
    ] as $file) {
        $source = file_get_contents(base_path($file));

        expect($source)->toContain('LowestCompetitorSource')
            ->and($source)->not->toContain('App\Domain\Competitor');
    }
});
