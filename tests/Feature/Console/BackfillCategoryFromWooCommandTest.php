<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Quick task 260607-v5g — products:backfill-category-from-woo
|--------------------------------------------------------------------------
|
| Tests the 6 outcome paths of the new artisan command. Scenarios A-F:
|
|   A — Woo returns 5 categories → updated successfully.
|   B — Woo returns empty categories → no_woo_categories, NO local write.
|   C — Woo response omits one of the requested IDs → woo_not_found.
|   D — Product has woo_product_id=null → excluded at query level, no Woo call.
|   E — Stub throws on Woo call → error bucket for all candidates in chunk.
|   F — --dry-run with 3 candidates → no DB writes, sample printed.
|
| Boundary strategy:
| Bind an anonymous-subclass WooClient stub into the container via
| `app()->instance(WooClient::class, $stub)`. The stub overrides `get()` to
| return a hard-coded fixture per test case + tracks call args in a public
| `$calls` array so we can assert query parameters.
*/

it('Case A: writes category_id + category_ids when Woo returns 5 categories', function (): void {
    Product::factory()->create([
        'sku' => 'A-001',
        'woo_product_id' => 999,
        'status' => 'publish',
        'category_id' => null,
    ]);

    $stub = bindWooStub([
        // Single chunk response keyed by (sorted) include list.
        ['id' => 999, 'categories' => [
            ['id' => 10, 'name' => 'A'],
            ['id' => 20, 'name' => 'B'],
            ['id' => 30, 'name' => 'C'],
            ['id' => 40, 'name' => 'D'],
            ['id' => 50, 'name' => 'E'],
        ]],
    ]);

    $exit = Artisan::call('products:backfill-category-from-woo', [
        '--skus' => 'A-001',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('sku', 'A-001')->value('category_id'))->toBe(10);
    expect(json_decode((string) DB::table('products')->where('sku', 'A-001')->value('category_ids'), true))
        ->toBe([10, 20, 30, 40, 50]);

    $output = Artisan::output();
    expect($output)->toContain('updated');

    // Verify the WooClient::get call was made with the correct query args.
    expect($stub->calls)->toHaveCount(1);
    expect($stub->calls[0]['endpoint'])->toBe('products');
    expect($stub->calls[0]['query']['orderby'])->toBe('include');
    expect($stub->calls[0]['query']['per_page'])->toBe(1);
});

it('Case B: empty categories falls into no_woo_categories bucket and does NOT write', function (): void {
    Product::factory()->create([
        'sku' => 'B-001',
        'woo_product_id' => 1000,
        'status' => 'publish',
        'category_id' => null,
    ]);

    bindWooStub([
        ['id' => 1000, 'categories' => []],
    ]);

    Artisan::call('products:backfill-category-from-woo', [
        '--skus' => 'B-001',
        '--no-confirm' => true,
    ]);

    expect(DB::table('products')->where('sku', 'B-001')->value('category_id'))->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('no_woo_categories');
});

it('Case C: Woo omits one of two requested IDs — updated:1 + woo_not_found:1', function (): void {
    Product::factory()->create([
        'sku' => 'C-001',
        'woo_product_id' => 2001,
        'status' => 'publish',
        'category_id' => null,
    ]);
    Product::factory()->create([
        'sku' => 'C-002',
        'woo_product_id' => 2002,
        'status' => 'publish',
        'category_id' => null,
    ]);

    bindWooStub([
        // 2002 is omitted from the response — Woo behaviour when a product
        // ID no longer exists on its side.
        ['id' => 2001, 'categories' => [['id' => 11, 'name' => 'X']]],
    ]);

    Artisan::call('products:backfill-category-from-woo', [
        '--skus' => 'C-001,C-002',
        '--no-confirm' => true,
    ]);

    expect(DB::table('products')->where('sku', 'C-001')->value('category_id'))->toBe(11);
    expect(DB::table('products')->where('sku', 'C-002')->value('category_id'))->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('updated');
    expect($output)->toContain('woo_not_found');
});

it('Case D: product with woo_product_id=null is excluded at query level — no Woo call', function (): void {
    Product::factory()->create([
        'sku' => 'D-001',
        'woo_product_id' => null,
        'status' => 'publish',
        'category_id' => null,
    ]);

    $stub = bindWooStub([]);

    Artisan::call('products:backfill-category-from-woo', [
        '--no-confirm' => true,
    ]);

    // Stub should never have been called — query filter excluded the candidate.
    expect($stub->calls)->toHaveCount(0);

    $output = Artisan::output();
    expect($output)->toContain('0 candidate products');
});

it('Case E: stub throws on Woo call — all candidates in chunk go to error bucket, exit SUCCESS', function (): void {
    Product::factory()->create([
        'sku' => 'E-001',
        'woo_product_id' => 3001,
        'status' => 'publish',
        'category_id' => null,
    ]);
    Product::factory()->create([
        'sku' => 'E-002',
        'woo_product_id' => 3002,
        'status' => 'publish',
        'category_id' => null,
    ]);
    Product::factory()->create([
        'sku' => 'E-003',
        'woo_product_id' => 3003,
        'status' => 'publish',
        'category_id' => null,
    ]);

    bindWooStubThrowing(new RuntimeException('Woo 502 Bad Gateway'));

    $exit = Artisan::call('products:backfill-category-from-woo', [
        '--skus' => 'E-001,E-002,E-003',
        '--chunk' => 10,        // single chunk
        '--no-confirm' => true,
    ]);

    // Errors are recorded, not propagated — run completes with SUCCESS exit.
    expect($exit)->toBe(0);
    expect(DB::table('products')->where('sku', 'E-001')->value('category_id'))->toBeNull();
    expect(DB::table('products')->where('sku', 'E-002')->value('category_id'))->toBeNull();
    expect(DB::table('products')->where('sku', 'E-003')->value('category_id'))->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('error');
});

it('Case F: --dry-run with 3 candidates — no DB writes, would_update printed', function (): void {
    Product::factory()->create([
        'sku' => 'F-001',
        'woo_product_id' => 4001,
        'status' => 'publish',
        'category_id' => null,
    ]);
    Product::factory()->create([
        'sku' => 'F-002',
        'woo_product_id' => 4002,
        'status' => 'publish',
        'category_id' => null,
    ]);
    Product::factory()->create([
        'sku' => 'F-003',
        'woo_product_id' => 4003,
        'status' => 'publish',
        'category_id' => null,
    ]);

    bindWooStub([
        ['id' => 4001, 'categories' => [['id' => 100, 'name' => 'F-Cat-1']]],
        ['id' => 4002, 'categories' => [['id' => 200, 'name' => 'F-Cat-2']]],
        ['id' => 4003, 'categories' => [['id' => 300, 'name' => 'F-Cat-3']]],
    ]);

    Artisan::call('products:backfill-category-from-woo', [
        '--skus' => 'F-001,F-002,F-003',
        '--dry-run' => true,
        '--no-confirm' => true,
    ]);

    // All three rows still NULL — dry-run wrote nothing.
    expect(DB::table('products')->where('sku', 'F-001')->value('category_id'))->toBeNull();
    expect(DB::table('products')->where('sku', 'F-002')->value('category_id'))->toBeNull();
    expect(DB::table('products')->where('sku', 'F-003')->value('category_id'))->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('would_update');
    expect($output)->toContain('Dry-run');
});

it('Case G: numeric SKU binds as string and writes category_id (regression for prod 1292 crash)', function (): void {
    // Quick task 260626-fjg — prod crash on `products:backfill-category-from-woo`.
    //
    // Root cause: PHP coerces all-digit string array keys to int. $candidates
    // is keyed by SKU, so a numeric SKU like '41074' arrives in the write loop
    // as int 41074 and binds as an INTEGER in the WHERE clause. `products.sku`
    // is a varchar — MariaDB (strict) then numerically coerces the whole column
    // and throws SQLSTATE 22007 / error 1292 'Truncated incorrect DECIMAL value'
    // on the first non-numeric SKU it scans ('CQ68056'). SQLite is loosely typed
    // so it never errored, which is why cases A-F stayed green while prod crashed.
    //
    // This guard catches the int binding on SQLite by asserting the captured
    // UPDATE binding for sku is a PHP string, never the integer 41074.
    Product::factory()->create([
        'sku' => '41074',          // all-digit SKU — PHP coerces the array key to int
        'woo_product_id' => 5001,
        'status' => 'publish',
        'category_id' => null,
    ]);
    Product::factory()->create([
        'sku' => 'CQ68056',        // non-numeric SKU — the one prod choked on
        'woo_product_id' => 5002,
        'status' => 'publish',
        'category_id' => null,
    ]);

    bindWooStub([
        ['id' => 5001, 'categories' => [['id' => 777, 'name' => 'Numeric Cat']]],
        ['id' => 5002, 'categories' => [['id' => 888, 'name' => 'Alpha Cat']]],
    ]);

    // Capture the bindings of every UPDATE touching `products`. DB::listen works
    // on SQLite, so this guard is portable to the test DB.
    $bindings = [];
    DB::listen(function ($q) use (&$bindings): void {
        if (str_starts_with(strtolower($q->sql), 'update') && str_contains($q->sql, 'products')) {
            $bindings[] = $q->bindings;
        }
    });

    Artisan::call('products:backfill-category-from-woo', [
        '--skus' => '41074,CQ68056',
        '--no-confirm' => true,
    ]);

    // 1 + 2: behavioural — both rows get their category_id written.
    expect(DB::table('products')->where('sku', '41074')->value('category_id'))->toBe(777);
    expect(DB::table('products')->where('sku', 'CQ68056')->value('category_id'))->toBe(888);

    // 3: PORTABLE GUARD — the sku binding for the numeric row must be the STRING
    // '41074', never the INTEGER 41074. This FAILS on the int-coerced code (where
    // the WHERE binds int 41074) and PASSES after the (string) cast, on SQLite,
    // without needing MariaDB strict mode to reproduce error 1292.
    expect(collect($bindings)->flatten()->contains('41074'))->toBeTrue();
    expect(collect($bindings)->flatten()->every(fn ($b) => $b !== 41074))->toBeTrue();
});

/**
 * Bind an anonymous-subclass WooClient stub into the container.
 *
 * @param  array<int, array<string, mixed>>  $nextResponse  list of product
 *                 objects to return from get(); same shape as WooClient
 *                 normalises (assoc arrays).
 * @return object  the bound stub, with public $calls + $nextResponse
 */
function bindWooStub(array $nextResponse): object
{
    $stub = new class($nextResponse) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(
            /** @var array<int, array<string, mixed>> */
            public array $nextResponse,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed
            // for the stub. We only override the get() method.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->calls[] = ['endpoint' => $endpoint, 'query' => $query];

            return $this->nextResponse;
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

/**
 * Bind a WooClient stub that throws on any get() call — for Case E.
 */
function bindWooStubThrowing(Throwable $e): object
{
    $stub = new class($e) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $calls = [];

        public function __construct(private Throwable $throwOnGet)
        {
            // Skip parent constructor.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->calls[] = ['endpoint' => $endpoint, 'query' => $query];
            throw $this->throwOnGet;
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}
