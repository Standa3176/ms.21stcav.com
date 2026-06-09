<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\StockDivergenceFinding;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Quick task 260609-nku — products:audit-stock-divergence
|--------------------------------------------------------------------------
|
| 6 engine cases (A-F):
|
|   A — Headline live bug: MS=0 + fresh supplier=0 + Woo>0 → 1 divergent row.
|   B — Fresh supplier has real stock → NOT EXISTS subquery filters out;
|       0 rows written; Woo never called.
|   C — MS has stock → not a candidate; 0 rows written.
|   D — Woo agrees MS=0 → matched bucket; 0 rows written.
|   E — Woo product not found (id excluded from response) → woo_not_found;
|       graceful skip, NO exception.
|   F — Dry-run is read-only → stock_divergence_findings count unchanged.
|
| Boundary strategy mirrors BackfillCategoryFromWooCommandTest: anonymous
| WooClient subclass bound via app()->instance() with hardcoded fixtures +
| call-args capture.
*/

function seedFreshSupplierZero(string $sku, string $supplierId = 'ingram'): void
{
    // Today's snapshot with stock=0 → supplier is fresh (within 7d window)
    // AND reports zero stock. This is the predicate that distinguishes
    // phantom-stock from legitimate-out-of-stock.
    SupplierOfferSnapshot::create([
        'sku' => strtolower(trim($sku)),
        'product_id' => null,
        'supplier_id' => $supplierId,
        'supplier_name' => 'Ingram',
        'price' => 100.00,
        'stock' => 0,
        'rrp' => 150.00,
        'recorded_at' => today(),
    ]);
}

function seedFreshSupplierWithStock(string $sku, int $stock, string $supplierId = 'ingram'): void
{
    SupplierOfferSnapshot::create([
        'sku' => strtolower(trim($sku)),
        'product_id' => null,
        'supplier_id' => $supplierId,
        'supplier_name' => 'Ingram',
        'price' => 100.00,
        'stock' => $stock,
        'rrp' => 150.00,
        'recorded_at' => today(),
    ]);
}

function bindStockDivergenceWooStub(array $response): object
{
    $stub = new class($response) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(public array $nextResponse)
        {
            // Skip parent constructor.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->calls[] = ['endpoint' => $endpoint, 'query' => $query];

            return $this->nextResponse;
        }
    };

    app()->instance(WooClient::class, $stub);
    // Reset the freshness resolver cache so newly-seeded snapshots are picked up.
    app(\App\Domain\Sync\Services\SupplierFreshnessResolver::class)->forget();

    return $stub;
}

it('Case A (headline live bug): MS=0 + fresh supplier=0 + Woo=7 writes one divergent row', function (): void {
    Product::factory()->create([
        'sku' => '45-243-224',
        'woo_product_id' => 8502,
        'status' => 'publish',
        'stock_quantity' => 0,
        'last_synced_at' => now(),
        'name' => 'Ergotron WorkFit-A',
    ]);
    seedFreshSupplierZero('45-243-224');

    bindStockDivergenceWooStub([
        [
            'id' => 8502,
            'stock_quantity' => 7,
            'date_modified' => '2026-05-31T07:54:57',
        ],
    ]);

    $exit = Artisan::call('products:audit-stock-divergence');
    expect($exit)->toBe(0);

    $row = StockDivergenceFinding::query()->where('sku', '45-243-224')->first();
    expect($row)->not->toBeNull();
    expect($row->phantom_units)->toBe(7);
    expect($row->woo_stock_quantity)->toBe(7);
    expect($row->ms_stock_quantity)->toBe(0);
    expect($row->status)->toBe('woo_overcount');
    expect(StockDivergenceFinding::query()->count())->toBe(1);
});

it('Case B: fresh supplier has real stock — NOT EXISTS filters candidate; 0 rows written', function (): void {
    Product::factory()->create([
        'sku' => 'B-001',
        'woo_product_id' => 1001,
        'status' => 'publish',
        'stock_quantity' => 0,
    ]);
    seedFreshSupplierWithStock('B-001', 5);

    $stub = bindStockDivergenceWooStub([
        ['id' => 1001, 'stock_quantity' => 7, 'date_modified' => '2026-05-31T07:54:57'],
    ]);

    Artisan::call('products:audit-stock-divergence');

    expect(StockDivergenceFinding::query()->count())->toBe(0);
    // Woo MUST NOT be called — candidate filtered out before chunk processing.
    expect($stub->calls)->toHaveCount(0);
});

it('Case C: MS has stock — not a candidate; 0 rows written', function (): void {
    Product::factory()->create([
        'sku' => 'C-001',
        'woo_product_id' => 1002,
        'status' => 'publish',
        'stock_quantity' => 10,
    ]);
    seedFreshSupplierZero('C-001');

    bindStockDivergenceWooStub([
        ['id' => 1002, 'stock_quantity' => 7, 'date_modified' => '2026-05-31T07:54:57'],
    ]);

    Artisan::call('products:audit-stock-divergence');

    expect(StockDivergenceFinding::query()->count())->toBe(0);
});

it('Case D: Woo agrees MS=0 — matched, not divergent; 0 rows written', function (): void {
    Product::factory()->create([
        'sku' => 'D-001',
        'woo_product_id' => 1003,
        'status' => 'publish',
        'stock_quantity' => 0,
    ]);
    seedFreshSupplierZero('D-001');

    bindStockDivergenceWooStub([
        ['id' => 1003, 'stock_quantity' => 0, 'date_modified' => '2026-05-31T07:54:57'],
    ]);

    Artisan::call('products:audit-stock-divergence');

    expect(StockDivergenceFinding::query()->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('matched');
});

it('Case E: Woo response omits the requested id — graceful skip with woo_not_found counter; NO exception', function (): void {
    Product::factory()->create([
        'sku' => 'E-001',
        'woo_product_id' => 99999,
        'status' => 'publish',
        'stock_quantity' => 0,
    ]);
    seedFreshSupplierZero('E-001');

    // Empty response — Woo "no such id" case.
    bindStockDivergenceWooStub([]);

    $exit = Artisan::call('products:audit-stock-divergence');
    expect($exit)->toBe(0);
    expect(StockDivergenceFinding::query()->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('woo_not_found');
});

it('Case F: --dry-run is read-only — stock_divergence_findings unchanged + counters printed', function (): void {
    Product::factory()->create([
        'sku' => 'F-001',
        'woo_product_id' => 5001,
        'status' => 'publish',
        'stock_quantity' => 0,
    ]);
    Product::factory()->create([
        'sku' => 'F-002',
        'woo_product_id' => 5002,
        'status' => 'publish',
        'stock_quantity' => 0,
    ]);
    Product::factory()->create([
        'sku' => 'F-003',
        'woo_product_id' => 5003,
        'status' => 'publish',
        'stock_quantity' => 0,
    ]);
    seedFreshSupplierZero('F-001');
    seedFreshSupplierZero('F-002');
    seedFreshSupplierZero('F-003');

    bindStockDivergenceWooStub([
        ['id' => 5001, 'stock_quantity' => 3, 'date_modified' => '2026-05-31T07:54:57'],
        ['id' => 5002, 'stock_quantity' => 4, 'date_modified' => '2026-05-31T07:54:57'],
        ['id' => 5003, 'stock_quantity' => 5, 'date_modified' => '2026-05-31T07:54:57'],
    ]);

    $baselineCount = StockDivergenceFinding::query()->count();

    Artisan::call('products:audit-stock-divergence', [
        '--dry-run' => true,
    ]);

    // No writes during dry-run.
    expect(StockDivergenceFinding::query()->count())->toBe($baselineCount);

    $output = Artisan::output();
    expect($output)->toContain('DRY-RUN');
    expect($output)->toContain('candidates_scanned');
    expect($output)->toContain('divergent_found');
});
