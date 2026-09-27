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
    array $competitorNetPences = [40000, 41000],
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
        // 260927-p41 — PRICES IN ARE NET. sell_price is VAT-inclusive in
        // production, so the fixture adds VAT and the caller's figure stays the
        // net one the margin is computed against.
        'sell_price' => (int) round($sellPence * 1.2) / 100,
    ], $extra));

    foreach ($competitorNetPences as $net) {
        CompetitorPrice::factory()->forSku($sku)->create([
            'competitor_id' => Competitor::factory(),
            'price_pennies_ex_vat' => $net,
            'price_pennies_gross' => (int) round($net * 1.2),
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

/**
 * Run the command and return its console output.
 *
 * Uses Artisan::call (NOT $this->artisan) because PendingCommand buffers into
 * its own output object, leaving Artisan::output() empty — we assert on the
 * rendered funnel/table text, so we need the real buffer.
 *
 * @param  array<string, mixed>  $options
 */
function runShoppingCandidates(array $options = [], int $expectedExitCode = 0): string
{
    $code = Artisan::call('products:shopping-candidates', $options);

    expect($code)->toBe($expectedExitCode);

    return Artisan::output();
}

it('prints the eligibility funnel with a per-gate drop count', function (): void {
    seedShoppingCandidate('C-GOOD', 10000, 35000);
    seedShoppingCandidate('C-DRAFT', 10000, 35000, extra: ['status' => 'draft']);
    seedShoppingCandidate('C-MARGIN', 10000, 12000);
    seedShoppingCandidate('C-COMPS', 10000, 35000, competitorNetPences: [40000]);
    seedShoppingCandidate('C-NOGTIN', 10000, 35000, ean: null);

    $output = runShoppingCandidates();

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

it('states that the demand figure is MODELLED and names what would measure it', function (): void {
    seedShoppingCandidate('C-GOOD', 10000, 35000);

    $output = runShoppingCandidates();

    expect($output)->toContain('MODELLED, not measured')
        ->and($output)->toContain('MEASURED')
        ->and($output)->toContain('ASSUMED')
        ->and($output)->toContain('config/ad_demand.php')
        // the one input that would make it a measurement
        ->and($output)->toContain('Keyword Planner')
        ->and($output)->toContain('United Kingdom')
        // and that margin is now on one tax basis
        ->and($output)->toContain('TRUE CASH margin');
});

it('writes the full shortlist to --csv with a header row and one row per product', function (): void {
    seedShoppingCandidate('CSV-A', 10000, 35000, competitorNetPences: [40000, 41000]);
    seedShoppingCandidate('CSV-B', 10000, 40000, competitorNetPences: [45000, 46000, 47000]);
    // excluded — only one competitor
    seedShoppingCandidate('CSV-SKIP', 10000, 35000, competitorNetPences: [40000]);

    $path = storage_path('app/testing/shopping-candidates-'.uniqid().'.csv');

    // --sort=score explicitly: the default became 'profit' in 260927-p41, and
    // this case is about the CSV shape and the score ordering, not the default.
    runShoppingCandidates(['--csv' => $path, '--sort' => 'score']);

    expect(file_exists($path))->toBeTrue();

    $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim((string) file_get_contents($path))))));

    $header = $rows[0];
    expect($header)->toBe([
        'rank', 'sku', 'name', 'brand', 'brand_id', 'woo_product_id', 'ean', 'has_gtin',
        'buy_price_pence', 'net_sell_price_pence', 'sell_price_pence',
        'margin_pence', 'margin_pct', 'margin_pence_gross_basis',
        'competitor_count', 'lowest_competitor_gross_pence', 'position',
        'delta_vs_lowest_pence', 'stock', 'supplier_name', 'score',
        'demand_class', 'price_band', 'days_seen', 'demand_window_days',
        'factor_class_base', 'factor_price', 'factor_breadth', 'factor_persistence',
        'est_units_per_week', 'est_units_band', 'est_weekly_profit_pence',
    ]);

    // Index by NAME, not position — the previous literal offsets pointed at the
    // wrong columns the moment two were inserted mid-header.
    $col = array_flip($header);

    // CSV-B: 3 comps × 30000p = 90000 score; CSV-A: 2 × 25000 = 50000.
    expect($rows)->toHaveCount(3)
        ->and($rows[1][$col['rank']])->toBe('1')
        ->and($rows[1][$col['sku']])->toBe('CSV-B')
        ->and($rows[1][$col['competitor_count']])->toBe('3')
        ->and($rows[1][$col['score']])->toBe('90000')
        ->and($rows[2][$col['sku']])->toBe('CSV-A')
        // and the demand columns carry values rather than blanks
        ->and($rows[1][$col['est_units_per_week']])->not->toBe('')
        ->and($rows[1][$col['est_units_band']])->not->toBe('');

    @unlink($path);
});

it('honours --sort for the exported ordering', function (): void {
    seedShoppingCandidate('S-A', 10000, 35000, competitorNetPences: [40000, 41000]);           // 2 comps, 25000
    seedShoppingCandidate('S-B', 10000, 30000, competitorNetPences: [40000, 41000, 42000]);    // 3 comps, 20000

    $path = storage_path('app/testing/shopping-sort-'.uniqid().'.csv');

    runShoppingCandidates(['--sort' => 'margin', '--csv' => $path]);

    $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim((string) file_get_contents($path))))));
    expect($rows[1][1])->toBe('S-A');

    @unlink($path);
});

it('rejects an unknown --sort value', function (): void {
    runShoppingCandidates(['--sort' => 'bananas'], expectedExitCode: 1);
});

it('honours --allow-missing-gtin and flags the affected rows', function (): void {
    seedShoppingCandidate('G-NONE', 10000, 35000, ean: null);

    // Flagged as GTIN-less in the preview table's GTIN column.
    expect(runShoppingCandidates(['--allow-missing-gtin' => true]))->toContain('G-NONE')
        ->and(runShoppingCandidates())->not->toContain('G-NONE');
});

it('honours --min-margin-pence and --min-competitors overrides', function (): void {
    seedShoppingCandidate('O-LOW', 10000, 20000, competitorNetPences: [40000]);

    expect(runShoppingCandidates())->not->toContain('O-LOW')
        ->and(runShoppingCandidates([
            '--min-margin-pence' => 9900,
            '--min-competitors' => 1,
        ]))->toContain('O-LOW');
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

    runShoppingCandidates();

    expect($mutations)->toBe([])
        ->and(Product::query()->get()->toArray())->toEqual($productsBefore)
        ->and(DB::table('competitor_prices')->get()->toArray())->toEqual($competitorPricesBefore);
});

it('makes no outbound HTTP call (no Woo, no Google)', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    seedShoppingCandidate('H-A', 10000, 35000, extra: [
        'brand_id' => 42,
        'attributes_json' => [['name' => 'Brand', 'value' => 'Yealink']],
    ]);

    $output = runShoppingCandidates();

    Http::assertNothingSent();

    // Brand resolved from local attributes_json — never from the Woo taxonomy.
    expect($output)->toContain('Yealink');
});

it('--live-stock confirms the shortlist against the live fresh-supplier signal', function (): void {
    seedShoppingCandidate('LIVE-KEEP', 10000, 35000, competitorNetPences: [40000, 41000]);
    seedShoppingCandidate('LIVE-DROP', 10000, 40000, competitorNetPences: [45000, 46000]);

    $fake = new class extends LiveSupplierStockResolver
    {
        public function __construct() {}

        public function isListedByFreshSupplier(string $sku): bool
        {
            return strtolower(trim($sku)) === 'live-keep';
        }
    };
    app()->instance(LiveSupplierStockResolver::class, $fake);

    $output = runShoppingCandidates(['--live-stock' => true]);

    expect($output)->toContain('LIVE-KEEP')
        ->and($output)->not->toContain('LIVE-DROP');
});

it('reports cleanly when nothing is eligible', function (): void {
    seedShoppingCandidate('N-LOW', 10000, 12000);

    expect(runShoppingCandidates())->toContain('No eligible');
});
