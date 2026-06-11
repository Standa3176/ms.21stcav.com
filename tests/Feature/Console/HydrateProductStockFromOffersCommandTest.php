<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierFreshnessResolver;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Quick task 260611-qcq — products:hydrate-stock-from-offers
|--------------------------------------------------------------------------
|
| 10 Pest cases A-J cover every documented outcome:
|
|   A — Single fresh in-stock supplier → instock + buy_price set + last_synced_at now.
|   B — Two fresh suppliers with stock → cheapest wins (buildBestOfferMap semantics).
|   C — Fresh suppliers but all stock=0 → outofstock + buy_price PRESERVED.
|   D — Stale supplier with stock>0 ignored → falls to OOS branch via
|       '__NO_FRESH_SUPPLIERS__' sentinel.
|   E — `--skus=E-001` narrows to that SKU; siblings untouched.
|   F — `--dry-run` writes nothing; sample + counter tables render.
|   G — `--only-stale=24` skips recently-synced products; older ones processed.
|   H — Mixed batch (3 products / 3 outcomes) — counter tallies correct.
|   I — Partial failure — one product's UPDATE rolls back via DB::beforeExecuting;
|       siblings still update; errors counter increments.
|   J — Downgrade (instock → outofstock) persists when offers dry up; buy_price preserved.
|
| Suite-wide guard: WooClient is bound to a throwing stub. ANY Woo call from the
| command fails the test — cheap insurance against a future regression that gains
| storefront writes. (260611-qcq invariant: MS-side hydration only.)
*/

/**
 * Insert a snapshot row into supplier_offer_snapshots.
 * recorded_at defaults to today() (fresh per SupplierFreshnessResolver classification).
 */
function makeSnapshot(
    string $sku,
    string $supplierId,
    int $stock,
    float $price,
    ?Carbon $recordedAt = null,
): void {
    DB::table('supplier_offer_snapshots')->insert([
        'sku' => strtolower(trim($sku)),
        'product_id' => null,
        'supplier_id' => $supplierId,
        'supplier_name' => "Supplier {$supplierId}",
        'price' => $price,
        'stock' => $stock,
        'rrp' => $price * 1.5,
        'recorded_at' => ($recordedAt ?? today())->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Force the SupplierFreshnessResolver to forget its per-request cache so the
 * NEXT classify/list call rebuilds against the snapshot rows we've just
 * seeded. SupplierFreshnessResolver is `final`, so we drive freshness via real
 * snapshot rows (recorded_at = today() → classified fresh by the resolver's
 * standard 7-day window). The cache flush is what bridges the seed-then-run
 * gap inside a single test.
 */
function refreshFreshnessCache(): void
{
    app(SupplierFreshnessResolver::class)->forget();
}

/**
 * Bind a throwing WooClient stub. ANY call from the command fails the test.
 * Suite-wide guard — 260611-qcq is strictly MS-side hydration with no Woo writes.
 *
 * @return object the bound stub for optional inspection
 */
function bindHydrateNoWooGuard(): object
{
    $stub = new class extends WooClient
    {
        public function __construct()
        {
            // Skip parent constructor — no IntegrationLogger / resolver needed
            // because every method throws.
        }

        public function get(string $endpoint, array $query = []): array
        {
            throw new RuntimeException(
                "260611-qcq invariant violated: hydrate command called WooClient::get({$endpoint}). "
                .'Hydrate is MS-side only — no Woo writes.'
            );
        }

        public function put(string $endpoint, array $payload): array
        {
            throw new RuntimeException(
                "260611-qcq invariant violated: hydrate command called WooClient::put({$endpoint}). "
                .'Hydrate is MS-side only — no Woo writes.'
            );
        }

        public function post(string $endpoint, array $payload): array
        {
            throw new RuntimeException(
                "260611-qcq invariant violated: hydrate command called WooClient::post({$endpoint}). "
                .'Hydrate is MS-side only — no Woo writes.'
            );
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

beforeEach(function (): void {
    // Suite-wide no-Woo guard — caught on every test.
    bindHydrateNoWooGuard();
    // Each test seeds snapshots THEN invokes the command. Ensure no per-request
    // freshness cache leaks across the seed boundary (and avoids cross-test
    // contamination since the resolver is singleton-bound).
    refreshFreshnessCache();
});

it('Case A: single fresh in-stock supplier sets stock + buy_price + last_synced_at', function (): void {
    $product = Product::factory()->create([
        'sku' => 'A-001',
        'woo_product_id' => 9001,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);

    makeSnapshot('A-001', '10', 5659, 1.00);
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers');

    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBe(5659);
    expect($product->stock_status)->toBe('instock');
    expect((float) $product->buy_price)->toBe(1.0);
    expect($product->last_synced_at)->not->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('updated_in_stock');
});

it('Case B: two fresh suppliers with stock — cheapest wins', function (): void {
    $product = Product::factory()->create([
        'sku' => 'B-001',
        'woo_product_id' => 9002,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);

    makeSnapshot('B-001', '10', 10, 1.00); // cheapest
    makeSnapshot('B-001', '20', 5, 2.00);
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers');

    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBe(10);
    expect((float) $product->buy_price)->toBe(1.0);
    expect($product->stock_status)->toBe('instock');
});

it('Case C: fresh suppliers all stock=0 → outofstock + buy_price PRESERVED', function (): void {
    $product = Product::factory()->create([
        'sku' => 'C-001',
        'woo_product_id' => 9003,
        'status' => 'publish',
        'stock_quantity' => 99,
        'stock_status' => 'instock',
        'buy_price' => 99.99,
        'last_synced_at' => null,
    ]);

    makeSnapshot('C-001', '10', 0, 1.00);
    makeSnapshot('C-001', '20', 0, 2.00);
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers');

    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBe(0);
    expect($product->stock_status)->toBe('outofstock');
    // buy_price preserved — last-known cost still valid for margin math.
    expect((float) $product->buy_price)->toBe(99.99);
    expect($product->last_synced_at)->not->toBeNull();
});

it('Case D: stale supplier with stock>0 is ignored — falls to OOS branch via sentinel', function (): void {
    $product = Product::factory()->create([
        'sku' => 'D-001',
        'woo_product_id' => 9004,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => 50.00,
        'last_synced_at' => null,
    ]);

    // Stale supplier (99) — only snapshot is 30 days old, beyond the default
    // 7-day fresh window. Resolver classifies as stale → freshIds=[] →
    // command renders the '__NO_FRESH_SUPPLIERS__' sentinel in its whereIn.
    makeSnapshot('D-001', '99', 200, 3.00, today()->subDays(30));
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers');

    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBe(0);
    expect($product->stock_status)->toBe('outofstock');
    expect((float) $product->buy_price)->toBe(50.0); // preserved
    // No write happened — product was ALREADY OOS, so unchanged-detection
    // skipped the UPDATE. last_synced_at stays null. The point of Case D is
    // that the stale supplier's stock=200 was IGNORED (no instock branch
    // taken); a future test of "stale-only + product was previously instock"
    // would verify the downgrade-with-write path (Case J covers that with
    // a fresh supplier going to stock=0).
    expect($product->last_synced_at)->toBeNull();
});

it('Case E: --skus narrows to specified SKU; siblings untouched', function (): void {
    $target = Product::factory()->create([
        'sku' => 'E-001',
        'woo_product_id' => 9005,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);
    $sibling = Product::factory()->create([
        'sku' => 'E-002',
        'woo_product_id' => 9006,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);

    makeSnapshot('E-001', '10', 50, 1.00);
    makeSnapshot('E-002', '10', 75, 2.00);
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers', ['--skus' => 'E-001']);

    expect($exit)->toBe(0);

    $target->refresh();
    $sibling->refresh();
    expect($target->stock_quantity)->toBe(50);
    expect($target->stock_status)->toBe('instock');
    // Sibling untouched because --skus filter excluded it.
    expect($sibling->stock_quantity)->toBe(0);
    expect($sibling->stock_status)->toBe('outofstock');
    expect($sibling->last_synced_at)->toBeNull();
});

it('Case F: --dry-run writes nothing; sample + counter tables print', function (): void {
    $products = [];
    for ($i = 1; $i <= 5; $i++) {
        $sku = sprintf('F-%03d', $i);
        $products[] = Product::factory()->create([
            'sku' => $sku,
            'woo_product_id' => 9100 + $i,
            'status' => 'publish',
            'stock_quantity' => 0,
            'stock_status' => 'outofstock',
            'buy_price' => null,
            'last_synced_at' => null,
        ]);
        makeSnapshot($sku, '10', 5, 1.00);
    }
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers', ['--dry-run' => true]);

    expect($exit)->toBe(0);

    // No writes — every product still at the seeded zeros.
    foreach ($products as $p) {
        $p->refresh();
        expect($p->stock_quantity)->toBe(0);
        expect($p->stock_status)->toBe('outofstock');
        expect($p->last_synced_at)->toBeNull();
    }

    $output = Artisan::output();
    expect($output)->toContain('dry-run');
    expect($output)->toContain('proposed_qty');
    expect($output)->toContain('scanned');
});

it('Case G: --only-stale=24 skips recently-synced products', function (): void {
    $recent = Product::factory()->create([
        'sku' => 'G-001',
        'woo_product_id' => 9201,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => now()->subHours(2), // recently synced → skip
    ]);
    $stale = Product::factory()->create([
        'sku' => 'G-002',
        'woo_product_id' => 9202,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => now()->subHours(25), // 25h old → process
    ]);

    makeSnapshot('G-001', '10', 50, 1.00);
    makeSnapshot('G-002', '10', 75, 2.00);
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers', ['--only-stale' => '24']);

    expect($exit)->toBe(0);

    $recent->refresh();
    $stale->refresh();
    // Recent product skipped — still at seed values.
    expect($recent->stock_quantity)->toBe(0);
    expect($recent->stock_status)->toBe('outofstock');
    // Stale product processed.
    expect($stale->stock_quantity)->toBe(75);
    expect($stale->stock_status)->toBe('instock');
    expect((float) $stale->buy_price)->toBe(2.0);
});

it('Case H: mixed batch — in_stock + OOS + no-offers counts sum correctly', function (): void {
    // P1 — fresh + in-stock.
    $p1 = Product::factory()->create([
        'sku' => 'H-001',
        'woo_product_id' => 9301,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => 10.00,
        'last_synced_at' => null,
    ]);
    makeSnapshot('H-001', '10', 100, 1.50);

    // P2 — fresh supplier reports 0 stock → updated_out_of_stock.
    $p2 = Product::factory()->create([
        'sku' => 'H-002',
        'woo_product_id' => 9302,
        'status' => 'publish',
        'stock_quantity' => 50,
        'stock_status' => 'instock',
        'buy_price' => 20.00,
        'last_synced_at' => null,
    ]);
    makeSnapshot('H-002', '10', 0, 2.00);

    // P3 — no offers at all → updated_out_of_stock.
    $p3 = Product::factory()->create([
        'sku' => 'H-003',
        'woo_product_id' => 9303,
        'status' => 'publish',
        'stock_quantity' => 30,
        'stock_status' => 'instock',
        'buy_price' => 30.00,
        'last_synced_at' => null,
    ]);
    // No snapshot for H-003.

    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers');

    expect($exit)->toBe(0);

    $p1->refresh();
    $p2->refresh();
    $p3->refresh();

    // P1 — in-stock branch.
    expect($p1->stock_quantity)->toBe(100);
    expect($p1->stock_status)->toBe('instock');
    expect((float) $p1->buy_price)->toBe(1.5);
    expect($p1->last_synced_at)->not->toBeNull();

    // P2 — OOS branch; buy_price PRESERVED.
    expect($p2->stock_quantity)->toBe(0);
    expect($p2->stock_status)->toBe('outofstock');
    expect((float) $p2->buy_price)->toBe(20.0);
    expect($p2->last_synced_at)->not->toBeNull();

    // P3 — OOS branch (no offers); buy_price PRESERVED.
    expect($p3->stock_quantity)->toBe(0);
    expect($p3->stock_status)->toBe('outofstock');
    expect((float) $p3->buy_price)->toBe(30.0);
    expect($p3->last_synced_at)->not->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('updated_in_stock');
    expect($output)->toContain('updated_out_of_stock');
});

it('Case I: partial failure — one product rolls back; siblings update; errors counter increments', function (): void {
    $p1 = Product::factory()->create([
        'sku' => 'I-001',
        'woo_product_id' => 9401,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);
    $p2 = Product::factory()->create([
        'sku' => 'I-002',
        'woo_product_id' => 9402,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);
    $p3 = Product::factory()->create([
        'sku' => 'I-003',
        'woo_product_id' => 9403,
        'status' => 'publish',
        'stock_quantity' => 0,
        'stock_status' => 'outofstock',
        'buy_price' => null,
        'last_synced_at' => null,
    ]);

    makeSnapshot('I-001', '10', 11, 1.00);
    makeSnapshot('I-002', '10', 22, 2.00);
    makeSnapshot('I-003', '10', 33, 3.00);
    refreshFreshnessCache();

    // Throw on the UPDATE statement targeting P2's id; siblings proceed.
    $p2Id = $p2->id;
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($p2Id): void {
        if (str_starts_with($query, 'update "products"')
            && in_array($p2Id, $bindings, true)) {
            throw new RuntimeException("simulated update failure for product_id={$p2Id}");
        }
    });

    $exit = Artisan::call('products:hydrate-stock-from-offers');

    expect($exit)->toBe(0);

    $p1->refresh();
    $p2->refresh();
    $p3->refresh();

    // P1 + P3 updated; P2 rolled back.
    expect($p1->stock_quantity)->toBe(11);
    expect($p1->stock_status)->toBe('instock');
    expect($p3->stock_quantity)->toBe(33);
    expect($p3->stock_status)->toBe('instock');

    // P2 untouched — transaction rolled back.
    expect($p2->stock_quantity)->toBe(0);
    expect($p2->stock_status)->toBe('outofstock');
    expect($p2->last_synced_at)->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('errors');
});

it('Case J: downgrade — instock → outofstock persists when offers dry up; buy_price preserved', function (): void {
    $product = Product::factory()->create([
        'sku' => 'J-001',
        'woo_product_id' => 9501,
        'status' => 'publish',
        'stock_quantity' => 10,
        'stock_status' => 'instock',
        'buy_price' => 2.00,
        'last_synced_at' => now()->subDays(2),
    ]);

    // Supplier still fresh but stock has dropped to 0 since yesterday.
    makeSnapshot('J-001', '10', 0, 2.00);
    refreshFreshnessCache();

    $exit = Artisan::call('products:hydrate-stock-from-offers');

    expect($exit)->toBe(0);

    $product->refresh();
    expect($product->stock_quantity)->toBe(0);
    expect($product->stock_status)->toBe('outofstock');
    // buy_price preserved — cost still valid for margin math.
    expect((float) $product->buy_price)->toBe(2.0);
    // last_synced_at refreshed to now (the downgrade write happened).
    expect($product->last_synced_at)->not->toBeNull();
    expect($product->last_synced_at->isAfter(now()->subMinute()))->toBeTrue();
});
