<?php

declare(strict_types=1);

use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Quick task 260613-f2r — brands:retag-products-on-woo
|--------------------------------------------------------------------------
|
| 10 Pest cases A-J covering every documented engine outcome:
|
|   A — 5 products tagged ONLY [source] → 5 PUTs with body brands=[{id:canonical}].
|   B — 1 product tagged [source, otherBrand] → PUT preserves otherBrand alongside canonical.
|   C — product tagged BOTH source AND canonical → PUT body has canonical ONCE (no duplicate).
|   D — product tagged ONLY [canonical] → already_canonical++, ZERO PUTs.
|   E — Woo 5xx on a PUT → errors++, batch continues, exit SUCCESS.
|   F — Woo 404 on /products?brand={sourceId} → no_products_on_woo++, skip source.
|   G — --dry-run → ZERO Woo PUTs, plan + 20-row sample printed.
|   H — --source-ids=3102,12822 → 3102 processed, 12822 → source_not_a_duplicate++.
|   I — pagination across 2 pages → both pages processed.
|   J — idempotent re-run after retag → all products show already_canonical.
|
| Boundary strategy: anonymous-subclass WooClient stub with public $brandsByPage +
| $productsByBrandByPage + $putBehaviour + $brandListFailBehaviour. The
| BrandDuplicateFinder service resolves through the container and constructor-
| injects WooClient, picking up the SAME stub automatically — no second binding
| needed. Audit log assertions via Spatie Activity::query() — Auditor is `final`
| and cannot be Mockery-mocked.
*/

beforeEach(function (): void {
    // Default stub binding — each test re-binds with its own fixture if needed.
    bindRetagWooStub([], []);
});

it('Case A: 5 products tagged only [source] → 5 PUTs all targeting canonical', function (): void {
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 5],
            ],
        ],
        productsByBrandByPage: [
            11 => [
                1 => [
                    ['id' => 5001, 'sku' => 'A-1', 'brands' => [['id' => 11, 'name' => 'poly']]],
                    ['id' => 5002, 'sku' => 'A-2', 'brands' => [['id' => 11, 'name' => 'poly']]],
                    ['id' => 5003, 'sku' => 'A-3', 'brands' => [['id' => 11, 'name' => 'poly']]],
                    ['id' => 5004, 'sku' => 'A-4', 'brands' => [['id' => 11, 'name' => 'poly']]],
                    ['id' => 5005, 'sku' => 'A-5', 'brands' => [['id' => 11, 'name' => 'poly']]],
                ],
            ],
        ],
    );

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(5);

    foreach ($stub->putCalls as $call) {
        expect($call['payload'])->toBe(['brands' => [['id' => 10]]]);
    }

    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(5);
});

it('Case B: product tagged [source, otherBrand] → PUT preserves otherBrand alongside canonical', function (): void {
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 1],
            ],
        ],
        productsByBrandByPage: [
            11 => [
                1 => [
                    ['id' => 6001, 'sku' => 'B-1', 'brands' => [
                        ['id' => 11, 'name' => 'poly'],
                        ['id' => 99, 'name' => 'OtherBrand'],
                    ]],
                ],
            ],
        ],
    );

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(1);

    // Sorted compare since the command sorts $newBrandIds for deterministic order.
    $putBrandIds = array_column($stub->putCalls[0]['payload']['brands'], 'id');
    sort($putBrandIds);
    expect($putBrandIds)->toBe([10, 99]);
});

it('Case C: product tagged BOTH source AND canonical → PUT body has canonical exactly once', function (): void {
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 1],
            ],
        ],
        productsByBrandByPage: [
            11 => [
                1 => [
                    ['id' => 7001, 'sku' => 'C-1', 'brands' => [
                        ['id' => 10, 'name' => 'Poly'],     // canonical already present
                        ['id' => 11, 'name' => 'poly'],     // source — to be removed
                        ['id' => 99, 'name' => 'OtherBrand'],
                    ]],
                ],
            ],
        ],
    );

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(1);

    $putBrandIds = array_column($stub->putCalls[0]['payload']['brands'], 'id');
    sort($putBrandIds);
    expect($putBrandIds)->toBe([10, 99]);

    // Canonical (10) appears exactly once — no duplicate.
    expect(array_count_values($putBrandIds)[10])->toBe(1);
});

it('Case D: product tagged ONLY [canonical] → already_canonical++, ZERO PUTs', function (): void {
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 1],
            ],
        ],
        productsByBrandByPage: [
            11 => [
                // Woo's brand=11 query returns ONE row — but its brands[] array
                // contains ONLY canonical=10 (source already removed externally).
                // This is the post-retag idempotent state.
                1 => [
                    ['id' => 8001, 'sku' => 'D-1', 'brands' => [
                        ['id' => 10, 'name' => 'Poly'],
                    ]],
                ],
            ],
        ],
    );

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toBe([]);

    $output = Artisan::output();
    expect($output)->toContain('already_canonical');

    // No brands.product_retagged audit rows.
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(0);
});

it('Case E: Woo 5xx on a PUT → errors++, batch continues, exit SUCCESS', function (): void {
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 2],
            ],
        ],
        productsByBrandByPage: [
            11 => [
                1 => [
                    ['id' => 5001, 'sku' => 'E-poison', 'brands' => [['id' => 11]]],
                    ['id' => 5002, 'sku' => 'E-ok', 'brands' => [['id' => 11]]],
                ],
            ],
        ],
        putBehaviour: [5001 => '5xx'],
    );

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    // 5001 throws but is still recorded in putCalls; 5002 succeeds.
    expect($stub->putCalls)->toHaveCount(2);

    // 1 successful retag + 1 failure audit.
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.retag_failed')->count())->toBe(1);

    $failedRow = Activity::query()->where('description', 'brands.retag_failed')->first();
    expect($failedRow->properties['product_id'])->toBe(5001);
});

it('Case F: Woo 404 on /products?brand={sourceId} → no_products_on_woo++, skip source', function (): void {
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 1],
            ],
        ],
        productsByBrandByPage: [],
        brandListFailBehaviour: [11 => '404'],
    );

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toBe([]);

    expect(Activity::query()->where('description', 'brands.retag_no_products_on_woo')->count())->toBe(1);
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('no_products_on_woo');
});

it('Case G: --dry-run → ZERO Woo PUTs, plan + sample printed', function (): void {
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 3],
            ],
        ],
        productsByBrandByPage: [
            11 => [
                1 => [
                    ['id' => 5001, 'sku' => 'G-1', 'brands' => [['id' => 11]]],
                    ['id' => 5002, 'sku' => 'G-2', 'brands' => [['id' => 11]]],
                    ['id' => 5003, 'sku' => 'G-3', 'brands' => [['id' => 11]]],
                ],
            ],
        ],
    );

    $exit = Artisan::call('brands:retag-products-on-woo', ['--dry-run' => true]);

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toBe([]);

    $output = Artisan::output();
    expect($output)->toContain('[dry-run]');
    expect($output)->toContain('would_retag');

    // No product_retagged audit rows in dry-run.
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(0);
});

it('Case H: --source-ids=3102,12822 → 3102 processed, 12822 → source_not_a_duplicate++', function (): void {
    // brandsByPage includes:
    //  - duplicate group containing source 3102 (canonical=2001)
    //  - non-duplicate brand 12822 (only one row → not in $groups)
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 2001, 'name' => 'BrandA', 'count' => 50],
                ['id' => 3102, 'name' => 'brandA', 'count' => 2],   // dup of 2001
                ['id' => 12822, 'name' => 'StandaloneBrand', 'count' => 10],
            ],
        ],
        productsByBrandByPage: [
            3102 => [
                1 => [
                    ['id' => 9001, 'sku' => 'H-1', 'brands' => [['id' => 3102]]],
                ],
            ],
        ],
    );

    $exit = Artisan::call(
        'brands:retag-products-on-woo',
        ['--source-ids' => '3102,12822'],
    );

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(1);

    // 3102 → 2001 retag confirmed.
    expect($stub->putCalls[0]['endpoint'])->toBe('products/9001');
    expect($stub->putCalls[0]['payload'])->toBe(['brands' => [['id' => 2001]]]);

    $output = Artisan::output();
    expect($output)->toContain('source_not_a_duplicate source=12822');
});

it('Case I: pagination across 2 pages → both pages processed', function (): void {
    // page 1: 100 products, page 2: 1 product (forces 2nd page).
    $page1Products = [];
    for ($i = 1; $i <= 100; $i++) {
        $page1Products[] = ['id' => 50000 + $i, 'sku' => "I-{$i}", 'brands' => [['id' => 11]]];
    }
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 500],     // canonical (count wins)
                ['id' => 11, 'name' => 'poly', 'count' => 101],     // source
            ],
        ],
        productsByBrandByPage: [
            11 => [
                1 => $page1Products,
                2 => [
                    ['id' => 60001, 'sku' => 'I-page2', 'brands' => [['id' => 11]]],
                ],
            ],
        ],
    );

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(101);

    // Confirm both pages got hit.
    $pages = array_filter(
        $stub->getCalls,
        static fn (array $c): bool => $c['endpoint'] === 'products' && ($c['query']['brand'] ?? null) === 11,
    );
    $pageNums = array_map(static fn (array $c): int => (int) ($c['query']['page'] ?? 0), $pages);
    expect($pageNums)->toContain(1);
    expect($pageNums)->toContain(2);
});

it('Case J: idempotent re-run after retag → all products already_canonical, ZERO PUTs', function (): void {
    // First run: 5 products tagged [11] → all retagged to [10].
    $stub = bindRetagWooStub(
        brandsByPage: [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 50],
                ['id' => 11, 'name' => 'poly', 'count' => 5],
            ],
        ],
        productsByBrandByPage: [
            11 => [
                1 => [
                    ['id' => 5001, 'sku' => 'J-1', 'brands' => [['id' => 11]]],
                    ['id' => 5002, 'sku' => 'J-2', 'brands' => [['id' => 11]]],
                    ['id' => 5003, 'sku' => 'J-3', 'brands' => [['id' => 11]]],
                    ['id' => 5004, 'sku' => 'J-4', 'brands' => [['id' => 11]]],
                    ['id' => 5005, 'sku' => 'J-5', 'brands' => [['id' => 11]]],
                ],
            ],
        ],
    );

    $firstExit = Artisan::call('brands:retag-products-on-woo');
    expect($firstExit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(5);
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(5);

    // Now simulate post-retag Woo state: source brand=11 still has the same
    // 5 product rows surfaced (the brand term hasn't been deleted yet — that's
    // what step 3 of the operator workflow handles), BUT each product's
    // brands[] now reflects the retag — only canonical=10 remains. This is
    // what Woo returns to the next run.
    $stub->productsByBrandByPage = [
        11 => [
            1 => [
                ['id' => 5001, 'sku' => 'J-1', 'brands' => [['id' => 10]]],
                ['id' => 5002, 'sku' => 'J-2', 'brands' => [['id' => 10]]],
                ['id' => 5003, 'sku' => 'J-3', 'brands' => [['id' => 10]]],
                ['id' => 5004, 'sku' => 'J-4', 'brands' => [['id' => 10]]],
                ['id' => 5005, 'sku' => 'J-5', 'brands' => [['id' => 10]]],
            ],
        ],
    ];
    // Reset call recorders for the second run.
    $stub->putCalls = [];

    $secondExit = Artisan::call('brands:retag-products-on-woo');

    expect($secondExit)->toBe(0);
    expect($stub->putCalls)->toBe([]);

    // No NEW retagged rows from the idempotent re-run.
    // (The 5 from the first run still exist in the activity log.)
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(5);
});

/**
 * Bind an anonymous-subclass WooClient stub into the container.
 *
 * The BrandDuplicateFinder service resolves through the container and
 * constructor-injects WooClient, picking up THIS stub automatically — no
 * second binding required.
 *
 * @param  array<int, array<int, array<string,mixed>>>  $brandsByPage  page → list of brand rows
 * @param  array<int, array<int, array<int, array<string,mixed>>>>  $productsByBrandByPage  [sourceId => [page => [product rows]]]
 * @param  array<int, 'ok'|'5xx'>  $putBehaviour  wooProductId → per-PUT outcome
 * @param  array<int, '404'>  $brandListFailBehaviour  sourceId → per-list-GET failure
 */
function bindRetagWooStub(
    array $brandsByPage = [],
    array $productsByBrandByPage = [],
    array $putBehaviour = [],
    array $brandListFailBehaviour = [],
): object {
    $stub = new class($brandsByPage, $productsByBrandByPage, $putBehaviour, $brandListFailBehaviour) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $getCalls = [];

        /** @var array<int, array{endpoint:string, payload:array<string,mixed>}> */
        public array $putCalls = [];

        public function __construct(
            /** @var array<int, array<int, array<string,mixed>>> */
            public array $brandsByPage,
            /** @var array<int, array<int, array<int, array<string,mixed>>>> */
            public array $productsByBrandByPage,
            /** @var array<int, 'ok'|'5xx'> */
            public array $putBehaviour,
            /** @var array<int, '404'> */
            public array $brandListFailBehaviour,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver needed.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];

            if ($endpoint === 'products/brands') {
                $page = (int) ($query['page'] ?? 1);

                return $this->brandsByPage[$page] ?? [];
            }

            if ($endpoint === 'products' && isset($query['brand'])) {
                $brand = (int) $query['brand'];
                $page = (int) ($query['page'] ?? 1);

                $behaviour = $this->brandListFailBehaviour[$brand] ?? null;
                if ($behaviour === '404') {
                    throw new RuntimeException('rest_term_invalid: Term does not exist', 404);
                }

                return $this->productsByBrandByPage[$brand][$page] ?? [];
            }

            return [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            // Parse "products/{id}" → wooProductId for behaviour lookup.
            $wooId = 0;
            if (preg_match('#^products/(\d+)$#', $endpoint, $m)) {
                $wooId = (int) $m[1];
            }
            $behaviour = $this->putBehaviour[$wooId] ?? 'ok';
            if ($behaviour === '5xx') {
                throw new RuntimeException('Stub 5xx for woo_id='.$wooId, 500);
            }

            return ['id' => $wooId];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}
