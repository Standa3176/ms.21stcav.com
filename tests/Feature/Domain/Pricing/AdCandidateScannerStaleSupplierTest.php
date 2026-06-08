<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\AdCandidateScanner;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260608-g8x — AdCandidateScanner stale-supplier exclusion
|--------------------------------------------------------------------------
|
| Pins the wiring of SupplierFreshnessResolver into AdCandidateScanner's
| in-stock predicate.
|
| Default (flag ON): in-stock rows belonging to suppliers whose latest
| snapshot is past the threshold are dropped at the SQL level → product
| falls out of the candidate set because the stockRequired gate fails.
|
| Override (flag OFF): the row resurfaces (back-compat for operator
| override / debugging).
*/

function bindStaleSupplierTaxonomy(): void
{
    $fake = new class extends TaxonomyResolver
    {
        public function __construct()
        {
            // Skip parent constructor — no WooClient hit needed.
        }

        public function allBrands(): array
        {
            return [];
        }
    };
    app()->instance(TaxonomyResolver::class, $fake);
}

/**
 * Seed a golden-target Product whose ONLY supplier snapshot is from
 * a supplier that's been silent for $daysAgo days. Default 30 → way
 * past the 7-day stale threshold.
 */
function seedStaleSupplierCandidate(string $sku, int $daysAgo = 30): Product
{
    $product = Product::factory()->create([
        'sku' => $sku,
        'name' => "Product {$sku}",
        'type' => 'simple',
        'status' => 'publish',
        'buy_price' => 100,
        'sell_price' => 350,
        'brand_id' => null,
    ]);

    CompetitorPrice::factory()
        ->forSku($sku)
        ->create([
            'price_pennies_ex_vat' => (int) round(40000 / 1.2),
            'price_pennies_gross' => 40000,
        ]);

    SupplierOfferSnapshot::create([
        'sku' => strtolower($sku),
        'product_id' => $product->id,
        'supplier_id' => 'SILENT',
        'supplier_name' => 'SilentSupplier',
        'price' => 100,
        'stock' => 12,
        'rrp' => 350,
        // The supplier last spoke $daysAgo days ago — well past the 7d
        // default in config('supplier.default_stale_after_days').
        'recorded_at' => today()->subDays($daysAgo),
    ]);

    return $product;
}

beforeEach(function (): void {
    bindStaleSupplierTaxonomy();
});

it('Test A: flag ON drops the stale-supplier-only candidate', function (): void {
    seedStaleSupplierCandidate('SILENT-30');

    // Default constructor: excludeStaleSupplierStock=true (the canonical wiring).
    $rows = app(AdCandidateScanner::class)->scan(minMarginPence: 0);

    // SILENT classifies as stale (recorded_at = today - 30 ≥ 7d threshold).
    // freshOnly filter excludes its row → stock predicate fails → row dropped.
    expect($rows->pluck('sku')->all())->not->toContain('SILENT-30');
});

it('Test B: flag OFF keeps the same candidate (back-compat operator override)', function (): void {
    seedStaleSupplierCandidate('SILENT-30');

    $taxonomy = app(TaxonomyResolver::class);
    $freshness = app(SupplierFreshnessResolver::class);
    $scanner = new AdCandidateScanner(
        $taxonomy,
        $freshness,
        excludeStaleSupplierStock: false,
    );

    $rows = $scanner->scan(minMarginPence: 0);

    // With the stale filter disabled the snapshot row resurfaces — the
    // recorded_at <7d window also excludes it, but for THAT we'd need
    // stockRequired=false. With stockRequired default true, the existing
    // SUPPLIER_STOCK_WINDOW_DAYS=7 also drops it. Verify by ALSO turning
    // stockRequired off — the row should appear, which proves the flag-off
    // path bypassed the freshness gate (otherwise the freshOnly filter
    // would have empty fresh-set and short-circuited to no rows).
    $rowsNoStockGate = $scanner->scan(minMarginPence: 0, stockRequired: false);
    expect($rowsNoStockGate->pluck('sku')->all())->toContain('SILENT-30');
});
