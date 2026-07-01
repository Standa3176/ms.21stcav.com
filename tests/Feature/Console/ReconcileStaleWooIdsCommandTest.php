<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260701-n4y — products:reconcile-stale-woo-ids.
|--------------------------------------------------------------------------
|
| The command batch-checks every product's woo_product_id against Woo
| (GET products?include=…&_fields=id&status=any in chunks) and NULLs the
| woo_product_id of products whose id Woo no longer returns — unless --dry-run,
| which reports counts only.
|
| Fixture: three products with woo ids 100 / 200 / 300; the Woo stub returns
| only 100 & 300 (200 is stale). Boundary strategy mirrors bindWooStub in
| BackfillCategoryFromWooCommandTest — an anonymous WooClient subclass whose
| get() returns a hard-coded fixture and records the include-args.
*/

/**
 * Bind an anonymous WooClient stub whose get() returns the given product-id
 * rows and records every call's query args.
 *
 * @param  array<int, int>  $returnIds  the woo ids Woo still knows about
 * @return object the bound stub with public $calls
 */
function bindWooReconcileStub(array $returnIds): object
{
    $stub = new class($returnIds) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(
            /** @var array<int, int> */
            public array $returnIds,
        ) {
            // Skip parent constructor — only get() is exercised.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->calls[] = ['endpoint' => $endpoint, 'query' => $query];

            // Only echo back the ids that were BOTH requested (via include) AND
            // are in the known-good set — mirrors Woo omitting deleted ids.
            $requested = array_filter(
                array_map('intval', explode(',', (string) ($query['include'] ?? ''))),
            );

            return array_values(array_map(
                fn (int $id): array => ['id' => $id],
                array_values(array_intersect($requested, $this->returnIds)),
            ));
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

// ══════════════════════════════════════════════════════════════════════════════
// Dry-run — reports stale:1 with NO write
// ══════════════════════════════════════════════════════════════════════════════

it('dry-run reports 1 stale id and writes nothing', function (): void {
    Product::factory()->create(['sku' => 'R-100', 'woo_product_id' => 100, 'status' => 'publish']);
    Product::factory()->create(['sku' => 'R-200', 'woo_product_id' => 200, 'status' => 'draft']);
    Product::factory()->create(['sku' => 'R-300', 'woo_product_id' => 300, 'status' => 'publish']);

    bindWooReconcileStub([100, 300]); // 200 is stale

    $exit = Artisan::call('products:reconcile-stale-woo-ids', ['--dry-run' => true]);

    expect($exit)->toBe(0);

    // No DB change — all three keep their woo_product_id.
    expect(DB::table('products')->where('sku', 'R-100')->value('woo_product_id'))->toBe(100);
    expect(DB::table('products')->where('sku', 'R-200')->value('woo_product_id'))->toBe(200);
    expect(DB::table('products')->where('sku', 'R-300')->value('woo_product_id'))->toBe(300);

    $output = Artisan::output();
    expect($output)->toContain('stale');
    expect($output)->toContain('1');
});

// ══════════════════════════════════════════════════════════════════════════════
// Live — nulls the stale id only
// ══════════════════════════════════════════════════════════════════════════════

it('live run nulls the stale woo_product_id and leaves the valid ones', function (): void {
    Product::factory()->create(['sku' => 'R-100', 'woo_product_id' => 100, 'status' => 'publish']);
    Product::factory()->create(['sku' => 'R-200', 'woo_product_id' => 200, 'status' => 'draft']);
    Product::factory()->create(['sku' => 'R-300', 'woo_product_id' => 300, 'status' => 'publish']);

    bindWooReconcileStub([100, 300]); // 200 is stale

    $exit = Artisan::call('products:reconcile-stale-woo-ids');

    expect($exit)->toBe(0);

    // Only the stale (200) product is nulled.
    expect(DB::table('products')->where('sku', 'R-100')->value('woo_product_id'))->toBe(100);
    expect(DB::table('products')->where('sku', 'R-200')->value('woo_product_id'))->toBeNull();
    expect(DB::table('products')->where('sku', 'R-300')->value('woo_product_id'))->toBe(300);
});

// ══════════════════════════════════════════════════════════════════════════════
// Registration
// ══════════════════════════════════════════════════════════════════════════════

it('the command is registered with artisan', function (): void {
    expect(array_key_exists('products:reconcile-stale-woo-ids', Artisan::all()))->toBeTrue();
});
