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

it('Case I: drain-queue pagination (always page=1) → 101 PUTs across 3 sequential drains', function (): void {
    // 260613-ogv: always-page-1 draining contract. The WooClient stub returns
    // the first 100 products on the first [source=11][page=1] read, the next
    // 1 product on the second [source=11][page=1] read, and [] on the third —
    // mirroring how real WC returns a SHRINKING filter set as products lose
    // the source brand. Assertion intent unchanged: 101 PUTs, but the contract
    // is now "all GETs against brand=11 use page=1 + status=any, total exactly 3".
    $batch1 = [];
    for ($i = 1; $i <= 100; $i++) {
        $batch1[] = ['id' => 50000 + $i, 'sku' => "I-{$i}", 'brands' => [['id' => 11]]];
    }
    $batch2 = [
        ['id' => 60001, 'sku' => 'I-page2', 'brands' => [['id' => 11]]],
    ];
    $page1Drain = [$batch1, $batch2, []]; // final drain → empty signals done

    $stub = new class([
        'brandsByPage' => [
            1 => [
                ['id' => 10, 'name' => 'Poly', 'count' => 500],     // canonical (count wins)
                ['id' => 11, 'name' => 'poly', 'count' => 101],     // source
            ],
        ],
        'productsBrand11Queue' => $page1Drain,
    ]) extends WooClient {
        public array $getCalls = [];
        public array $putCalls = [];
        public array $brandsByPage;
        public array $productsBrand11Queue;

        public function __construct(array $config)
        {
            $this->brandsByPage = $config['brandsByPage'];
            $this->productsBrand11Queue = $config['productsBrand11Queue'];
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];
            if ($endpoint === 'products/brands') {
                return $this->brandsByPage[(int) ($query['page'] ?? 1)] ?? [];
            }
            if ($endpoint === 'products' && (int) ($query['brand'] ?? 0) === 11) {
                return array_shift($this->productsBrand11Queue) ?? [];
            }

            return [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            return ['id' => 0];
        }
    };
    app()->instance(WooClient::class, $stub);

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(101);

    // All GETs against brand=11 used page=1 AND status='any', exactly 3 of them.
    $brand11Gets = array_values(array_filter(
        $stub->getCalls,
        static fn (array $c): bool => $c['endpoint'] === 'products' && ($c['query']['brand'] ?? null) === 11,
    ));
    expect(count($brand11Gets))->toBe(3); // drain → 100, drain → 1, drain → empty
    foreach ($brand11Gets as $call) {
        expect($call['query']['page'] ?? null)->toBe(1);    // ALWAYS page 1
        expect($call['query']['status'] ?? null)->toBe('any');
    }
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

it('Case K: status=any captures pending+draft products that publish-only would skip', function (): void {
    // Stub returns all 3 products under status=any; returns just the 1 publish row under any other status.
    $stub = new class([
        'brandsByPage' => [
            1 => [
                ['id' => 20, 'name' => 'Crestron', 'count' => 80],
                ['id' => 21, 'name' => 'crestron', 'count' => 3],
            ],
        ],
        'allProducts' => [
            ['id' => 7001, 'sku' => 'K-publish',  'status' => 'publish', 'brands' => [['id' => 21]]],
            ['id' => 7002, 'sku' => 'K-pending1', 'status' => 'pending', 'brands' => [['id' => 21]]],
            ['id' => 7003, 'sku' => 'K-pending2', 'status' => 'pending', 'brands' => [['id' => 21]]],
        ],
    ]) extends WooClient {
        public array $getCalls = [];
        public array $putCalls = [];
        public array $brandsByPage;
        public array $allProducts;
        public int $drainCalls = 0;

        public function __construct(array $config)
        {
            $this->brandsByPage = $config['brandsByPage'];
            $this->allProducts = $config['allProducts'];
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];
            if ($endpoint === 'products/brands') {
                return $this->brandsByPage[(int) ($query['page'] ?? 1)] ?? [];
            }
            if ($endpoint === 'products' && (int) ($query['brand'] ?? 0) === 21) {
                $this->drainCalls++;
                if ($this->drainCalls > 1) {
                    return []; // drain after first call
                }
                // Under status=any → all 3; otherwise → just the publish row.
                if (($query['status'] ?? null) === 'any') {
                    return $this->allProducts;
                }

                return array_values(array_filter($this->allProducts, static fn (array $p): bool => ($p['status'] ?? null) === 'publish'));
            }

            return [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            return ['id' => 0];
        }
    };
    app()->instance(WooClient::class, $stub);

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(3); // all 3 — including the 2 pending — retagged
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(3);

    // Sanity: confirm at least one GET against brand=21 carried status=any.
    $brand21Gets = array_filter(
        $stub->getCalls,
        static fn (array $c): bool => $c['endpoint'] === 'products' && ($c['query']['brand'] ?? null) === 21,
    );
    $statuses = array_map(static fn (array $c) => $c['query']['status'] ?? null, $brand21Gets);
    expect($statuses)->toContain('any');
});

it('Case L: 200-product source drains across multiple page=1 reads, safety break NOT hit', function (): void {
    // First page=1 read → 100 products, second → next 100, third → empty.
    $batch1 = [];
    for ($i = 1; $i <= 100; $i++) {
        $batch1[] = ['id' => 80000 + $i, 'sku' => "L-{$i}", 'brands' => [['id' => 31]]];
    }
    $batch2 = [];
    for ($i = 101; $i <= 200; $i++) {
        $batch2[] = ['id' => 80000 + $i, 'sku' => "L-{$i}", 'brands' => [['id' => 31]]];
    }

    $stub = new class([
        'brandsByPage' => [
            1 => [
                ['id' => 30, 'name' => 'LG', 'count' => 800],
                ['id' => 31, 'name' => 'lg', 'count' => 200],
            ],
        ],
        'drainQueue' => [$batch1, $batch2, []],
    ]) extends WooClient {
        public array $getCalls = [];
        public array $putCalls = [];
        public array $brandsByPage;
        public array $drainQueue;

        public function __construct(array $config)
        {
            $this->brandsByPage = $config['brandsByPage'];
            $this->drainQueue = $config['drainQueue'];
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];
            if ($endpoint === 'products/brands') {
                return $this->brandsByPage[(int) ($query['page'] ?? 1)] ?? [];
            }
            if ($endpoint === 'products' && (int) ($query['brand'] ?? 0) === 31) {
                return array_shift($this->drainQueue) ?? [];
            }

            return [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            return ['id' => 0];
        }
    };
    app()->instance(WooClient::class, $stub);

    $exit = Artisan::call('brands:retag-products-on-woo');

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(200);

    // No safety break fired — 200 / 100 = 2 drain iterations, well under the 50-iteration backstop.
    expect(Activity::query()->where('description', 'brands.retag_safety_break')->count())->toBe(0);
    expect(Activity::query()->where('description', 'brands.product_retagged')->count())->toBe(200);
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

                // 260613-ogv: model the shrinking filter set — once a [brand][page]
                // batch is consumed by the always-page-1 loop, subsequent GETs for
                // the same (brand, page) drain to []. Without this, the always-page-1
                // loop would re-process the same products until the safety break.
                $batch = $this->productsByBrandByPage[$brand][$page] ?? [];
                if ($batch !== []) {
                    $this->productsByBrandByPage[$brand][$page] = [];
                }

                return $batch;
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
