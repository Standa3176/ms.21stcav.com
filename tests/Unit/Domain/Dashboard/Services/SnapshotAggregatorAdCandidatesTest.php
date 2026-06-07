<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Dashboard\Services\SnapshotAggregator;
use App\Domain\Pricing\Services\AdCandidateScanner;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-pys — SnapshotAggregator::computeAdCandidatesHealth
|--------------------------------------------------------------------------
|
| Validates the 3-key payload (count + total_margin_pence +
| average_margin_pence) consumed by AdCandidatesReadyWidget on the home
| dashboard. Defensive try/catch matches the existing
| computeSuggestionsTriageHealth pattern from 260606-lhp — a scanner
| exception MUST NOT 500 the dashboard refresh.
*/

function bindAggregatorBrandTerms(array $brands): void
{
    $taxonomyFake = new class($brands) extends TaxonomyResolver
    {
        public function __construct(/** @var array<int, array{id:int, name:string}> */ private array $brandsList)
        {
            // Skip parent constructor — no Woo REST hit in tests.
        }

        public function allBrands(): array
        {
            return $this->brandsList;
        }
    };
    app()->instance(TaxonomyResolver::class, $taxonomyFake);
}

function seedAggregatorRow(string $sku, int $marginPence): void
{
    // sell - buy = margin; comp gross ABOVE sell (we beat); supplier stock > 0.
    $buy = 10000;
    $sell = $buy + $marginPence;
    $comp = $sell + 5000; // we beat by £50

    $product = Product::factory()->create([
        'sku' => $sku,
        'type' => 'simple',
        'status' => 'publish',
        'buy_price' => $buy / 100,
        'sell_price' => $sell / 100,
    ]);

    CompetitorPrice::factory()->forSku($sku)->create([
        'price_pennies_ex_vat' => (int) round($comp / 1.2),
        'price_pennies_gross' => $comp,
    ]);

    SupplierOfferSnapshot::create([
        'sku' => strtolower($sku),
        'product_id' => $product->id,
        'supplier_id' => 'SUP-AG',
        'supplier_name' => 'AggSupplier',
        'price' => $buy / 100,
        'stock' => 5,
        'rrp' => $sell / 100,
        'recorded_at' => today(),
    ]);
}

beforeEach(function (): void {
    bindAggregatorBrandTerms([]);
});

it('returns count + total + average for the seeded golden set', function (): void {
    // Three rows with margins £200 / £300 / £400 → total £900, avg £300.
    seedAggregatorRow('AGG-200', marginPence: 20000);
    seedAggregatorRow('AGG-300', marginPence: 30000);
    seedAggregatorRow('AGG-400', marginPence: 40000);

    $payload = app(SnapshotAggregator::class)->computeAdCandidatesHealth();

    expect($payload)->toHaveKeys(['count', 'total_margin_pence', 'average_margin_pence']);
    expect($payload['count'])->toBe(3);
    expect($payload['total_margin_pence'])->toBe(90000);
    expect($payload['average_margin_pence'])->toBe(30000);
});

it('returns zeroes on an empty DB (no DivisionByZeroError)', function (): void {
    $payload = app(SnapshotAggregator::class)->computeAdCandidatesHealth();

    expect($payload['count'])->toBe(0);
    expect($payload['total_margin_pence'])->toBe(0);
    expect($payload['average_margin_pence'])->toBe(0);
});

it('returns zero payload when the scanner throws (defensive try/catch)', function (): void {
    $throwingScanner = new class extends AdCandidateScanner
    {
        public function __construct()
        {
            // Skip parent constructor.
        }

        public function scan(
            array $brandIds = [],
            int $minMarginPence = 19900,
            bool $stockRequired = true,
            bool $beatRequired = true,
        ): \Illuminate\Support\Collection {
            throw new \RuntimeException('synthetic scanner failure');
        }
    };
    app()->instance(AdCandidateScanner::class, $throwingScanner);

    // report() should be called for the exception — capture via Log spy.
    Log::spy();

    $payload = app(SnapshotAggregator::class)->computeAdCandidatesHealth();

    expect($payload['count'])->toBe(0);
    expect($payload['total_margin_pence'])->toBe(0);
    expect($payload['average_margin_pence'])->toBe(0);
});

it('is registered in computeAll() under the ad_candidates_health metric key', function (): void {
    $all = app(SnapshotAggregator::class)->computeAll();

    expect($all)->toHaveKey('ad_candidates_health');
    expect($all['ad_candidates_health'])->toHaveKeys(['count', 'total_margin_pence', 'average_margin_pence']);
});
