<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Services\LiveSupplierStockResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Quick task 260722-shc — products:shopping-candidates (READ-ONLY)
|--------------------------------------------------------------------------
|
| Command-level contract:
|   - prints a gate-by-gate eligibility funnel (incl. the missing-GTIN drop,
|     which quantifies the EAN-backfill opportunity),
|   - prints a ranked preview table,
|   - writes the full shortlist to --csv (header row + one row per product),
|   - states plainly that competitor breadth is a DEMAND PROXY and that true
|     UK volume must be validated in Google Keyword Planner,
|   - performs NO writes and NO outbound HTTP (Woo / Google / anything).
*/

/**
 * @param  array<int, int>  $competitorGrossPences
 * @param  array<string, mixed>  $extra
 */
function seedShoppingCandidate(
    string $sku,
    int $buyPence,
    int $sellPence,
    array $competitorGrossPences = [40000, 41000],
    int $stock = 5,
    ?string $ean = '5012345678900',
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
        CompetitorPrice::factory()->forSku($sku)->create([
            'competitor_id' => Competitor::factory(),
            'price_pennies_ex_vat' => (int) round($gross / 1.2),
            'price_pennies_gross' => $gross,
        ]);
    }

    SupplierOfferSnapshot::create([
        'sku' => strtolower(trim($sku)),
        'product_id' => $product->id,
        'supplier_id' => 'SUP-TEST',
        'supplier_name' => 'TestSupplier',
        'price' => $buyPence / 100,
        'stock' => $stock,
        'rrp' => $sellPence / 100,
        'recorded_at' => today(),
    ]);

    return $product;
}

it('prints the eligibility funnel with a per-gate drop count', function (): void {
    seedShoppingCandidate('C-GOOD', 10000, 35000);
    seedShoppingCandidate('C-DRAFT', 10000, 35000, extra: ['status' => 'draft']);
    seedShoppingCandidate('C-MARGIN', 10000, 12000);
    seedShoppingCandidate('C-COMPS', 10000, 35000, competitorGrossPences: [40000]);
    seedShoppingCandidate('C-NOGTIN', 10000, 35000, ean: null);

    $this->artisan('products:shopping-candidates')
        ->assertExitCode(0);

    $output = Artisan::output();

    expect($output)->toContain('Eligibility funnel')
        ->and($output)->toContain('Products scanned')
        ->and($output)->toContain('not publish/simple')
        ->and($output)->toContain('margin <')
        ->and($output)->toContain('fresh in-stock supplier offer')
        ->and($output)->toContain('competitors <')
        ->and($output)->toContain('missing GTIN')
        ->and($output)->toContain('EAN-backfill opportunity')
        ->and($output)->toContain('ELIGIBLE');
});

it('states that competitor breadth is a demand proxy needing Keyword Planner validation', function (): void {
    seedShoppingCandidate('C-GOOD', 10000, 35000);

    $this->artisan('products:shopping-candidates')->assertExitCode(0);

    $output = Artisan::output();

    expect($output)->toContain('DEMAND PROXY')
        ->and($output)->toContain('Keyword Planner')
        ->and($output)->toContain('United Kingdom');
});

it('writes the full shortlist to --csv with a header row and one row per product', function (): void {
    seedShoppingCandidate('CSV-A', 10000, 35000, competitorGrossPences: [40000, 41000]);
    seedShoppingCandidate('CSV-B', 10000, 40000, competitorGrossPences: [45000, 46000, 47000]);
    // excluded — only one competitor
    seedShoppingCandidate('CSV-SKIP', 10000, 35000, competitorGrossPences: [40000]);

    $path = storage_path('app/testing/shopping-candidates-'.uniqid().'.csv');

    $this->artisan('products:shopping-candidates', ['--csv' => $path])->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim((string) file_get_contents($path))))));

    expect($rows[0])->toBe([
        'rank', 'sku', 'name', 'brand', 'brand_id', 'woo_product_id', 'ean', 'has_gtin',
        'buy_price_pence', 'sell_price_pence', 'margin_pence', 'margin_pct',
        'competitor_count', 'lowest_competitor_gross_pence', 'position',
        'delta_vs_lowest_pence', 'stock', 'supplier_name', 'score',
    ]);

    // CSV-B: 3 comps × 30000p = 90000 score; CSV-A: 2 × 25000 = 50000.
    expect($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('1')
        ->and($rows[1][1])->toBe('CSV-B')
        ->and($rows[1][12])->toBe('3')
        ->and($rows[1][18])->toBe('90000')
        ->and($rows[2][1])->toBe('CSV-A');

    @unlink($path);
});

it('honours --sort for the exported ordering', function (): void {
    seedShoppingCandidate('S-A', 10000, 35000, competitorGrossPences: [40000, 41000]);           // 2 comps, 25000
    seedShoppingCandidate('S-B', 10000, 30000, competitorGrossPences: [40000, 41000, 42000]);    // 3 comps, 20000

    $path = storage_path('app/testing/shopping-sort-'.uniqid().'.csv');

    $this->artisan('products:shopping-candidates', ['--sort' => 'margin', '--csv' => $path])->assertExitCode(0);

    $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim((string) file_get_contents($path))))));
    expect($rows[1][1])->toBe('S-A');

    @unlink($path);
});

it('rejects an unknown --sort value', function (): void {
    $this->artisan('products:shopping-candidates', ['--sort' => 'bananas'])
        ->assertExitCode(1);
});

it('honours --allow-missing-gtin and flags the affected rows', function (): void {
    seedShoppingCandidate('G-NONE', 10000, 35000, ean: null);

    $this->artisan('products:shopping-candidates', ['--allow-missing-gtin' => true])
        ->assertExitCode(0);

    expect(Artisan::output())->toContain('G-NONE');

    $this->artisan('products:shopping-candidates')->assertExitCode(0);

    expect(Artisan::output())->not->toContain('G-NONE');
});

it('honours --min-margin-pence and --min-competitors overrides', function (): void {
    seedShoppingCandidate('O-LOW', 10000, 20000, competitorGrossPences: [40000]);

    $this->artisan('products:shopping-candidates')->assertExitCode(0);
    expect(Artisan::output())->not->toContain('O-LOW');

    $this->artisan('products:shopping-candidates', [
        '--min-margin-pence' => 9900,
        '--min-competitors' => 1,
    ])->assertExitCode(0);
    expect(Artisan::output())->toContain('O-LOW');
});

it('is READ-ONLY — issues no insert, update or delete statement', function (): void {
    seedShoppingCandidate('W-A', 10000, 35000);
    seedShoppingCandidate('W-B', 10000, 40000);

    $productsBefore = Product::query()->get()->toArray();
    $competitorPricesBefore = DB::table('competitor_prices')->get()->toArray();

    $mutations = [];
    DB::listen(function ($query) use (&$mutations): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|drop|alter)\b/i', $query->sql) === 1) {
            $mutations[] = $query->sql;
        }
    });

    $this->artisan('products:shopping-candidates')->assertExitCode(0);

    expect($mutations)->toBe([])
        ->and(Product::query()->get()->toArray())->toBe($productsBefore)
        ->and(DB::table('competitor_prices')->get()->toArray())->toBe($competitorPricesBefore);
});

it('makes no outbound HTTP call (no Woo, no Google)', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    seedShoppingCandidate('H-A', 10000, 35000, extra: [
        'brand_id' => 42,
        'attributes_json' => [['name' => 'Brand', 'value' => 'Yealink']],
    ]);

    $this->artisan('products:shopping-candidates')->assertExitCode(0);

    Http::assertNothingSent();

    expect(Artisan::output())->toContain('Yealink');
});

it('--live-stock confirms the shortlist against the live fresh-supplier signal', function (): void {
    seedShoppingCandidate('LIVE-KEEP', 10000, 35000, competitorGrossPences: [40000, 41000]);
    seedShoppingCandidate('LIVE-DROP', 10000, 40000, competitorGrossPences: [45000, 46000]);

    $fake = new class extends LiveSupplierStockResolver
    {
        public function __construct() {}

        public function isListedByFreshSupplier(string $sku): bool
        {
            return strtolower(trim($sku)) === 'live-keep';
        }
    };
    app()->instance(LiveSupplierStockResolver::class, $fake);

    $this->artisan('products:shopping-candidates', ['--live-stock' => true])->assertExitCode(0);

    $output = Artisan::output();

    expect($output)->toContain('LIVE-KEEP')
        ->and($output)->not->toContain('LIVE-DROP');
});

it('reports cleanly when nothing is eligible', function (): void {
    seedShoppingCandidate('N-LOW', 10000, 12000);

    $this->artisan('products:shopping-candidates')->assertExitCode(0);

    expect(Artisan::output())->toContain('No eligible');
});
