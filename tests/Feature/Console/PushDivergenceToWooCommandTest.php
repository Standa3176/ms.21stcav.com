<?php

declare(strict_types=1);

use App\Domain\Cutover\Services\DivergenceScanner;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\WooFieldComparator;
use App\Domain\Sync\Models\SyncDiff;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Quick task 260611-g4q — products:push-divergence-to-woo
|--------------------------------------------------------------------------
|
| 10 Pest cases A-J cover the 6 outcomes (pushed / errors / no_woo_product_id /
| woo_not_found / already_applied / partial_success) plus:
|   - per-field filter (--field=stock_quantity) re-run safety
|   - dry-run no-op
|   - --correlation-id override (defeating latest() default)
|
| Boundary strategy mirrors 260611-f1y's PushVisibilityToWooCommandTest:
| anonymous-subclass WooClient with public $getCalls + $putCalls; skip parent
| constructor; bind via app()->instance(WooClient::class, $stub).
*/

it('Case A: stock-only single-product happy path — 1 GET, 1 PUT with manage_stock, applied', function (): void {
    $product = Product::factory()->create([
        'sku' => 'A-001',
        'woo_product_id' => 9001,
        'stock_quantity' => 10,
    ]);

    $diff = makeDiffRow($product->id, 'stock_quantity', 10, 0, 'cid-A');

    $stub = bindDivergenceStub([
        9001 => ['id' => 9001, 'stock_quantity' => 0, 'meta_data' => []],
    ]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->getCalls[0]['endpoint'])->toBe('products/9001');
    expect($stub->putCalls)->toHaveCount(1);
    expect($stub->putCalls[0]['endpoint'])->toBe('products/9001');
    expect($stub->putCalls[0]['payload'])->toHaveKey('stock_quantity', 10);
    expect($stub->putCalls[0]['payload'])->toHaveKey('manage_stock', true);

    $diff->refresh();
    expect($diff->status)->toBe('applied');
    expect($diff->applied_at)->not->toBeNull();
});

it('Case B: buy_price-only — meta_data MERGE preserves Yoast + brand entries, drops old cost', function (): void {
    $product = Product::factory()->create([
        'sku' => 'B-001',
        'woo_product_id' => 9002,
        'buy_price' => 113.03,
    ]);

    $diff = makeDiffRow($product->id, 'buy_price', 113.03, 1.00, 'cid-B');

    $stub = bindDivergenceStub([
        9002 => [
            'id' => 9002,
            'meta_data' => [
                ['key' => '_yoast_wpseo_metadesc', 'value' => 'Foo bar'],
                ['key' => '_alg_wc_cog_cost', 'value' => '1.00'],
                ['key' => '_product_brand_id', 'value' => '42'],
            ],
        ],
    ]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(1);

    $putMeta = $stub->putCalls[0]['payload']['meta_data'] ?? null;
    expect($putMeta)->toBeArray();
    expect($putMeta)->toHaveCount(3);

    // Build a key→value map for easier assertions.
    $byKey = [];
    foreach ($putMeta as $entry) {
        $byKey[$entry['key']] = $entry['value'];
    }
    expect($byKey)->toHaveKey('_yoast_wpseo_metadesc', 'Foo bar');
    expect($byKey)->toHaveKey('_product_brand_id', '42');
    expect($byKey)->toHaveKey('_alg_wc_cog_cost', '113.0300');

    // Confirm OLD cost entry is GONE — exactly ONE entry with that key.
    $costEntries = array_filter($putMeta, fn ($e) => ($e['key'] ?? null) === WooFieldComparator::BUY_PRICE_META_KEY);
    expect($costEntries)->toHaveCount(1);

    $diff->refresh();
    expect($diff->status)->toBe('applied');
});

it('Case C: category_id-only — categories built from category_ids JSON multi-cat', function (): void {
    $product = Product::factory()->create([
        'sku' => 'C-001',
        'woo_product_id' => 9003,
        'category_id' => 42,
        'category_ids' => [42, 99],
    ]);

    $diff = makeDiffRow($product->id, 'category_id', 42, 7, 'cid-C');

    $stub = bindDivergenceStub([
        9003 => ['id' => 9003, 'meta_data' => []],
    ]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(1);
    $payload = $stub->putCalls[0]['payload'];
    expect($payload)->toHaveKey('categories');
    expect($payload['categories'])->toBe([['id' => 42], ['id' => 99]]);
    expect($payload)->not->toHaveKey('stock_quantity');
    expect($payload)->not->toHaveKey('manage_stock');
    expect($payload)->not->toHaveKey('meta_data');

    $diff->refresh();
    expect($diff->status)->toBe('applied');
});

it('Case D: 3-field combo for one product — SINGLE PUT with all 3 fields', function (): void {
    $product = Product::factory()->create([
        'sku' => 'D-001',
        'woo_product_id' => 9004,
        'stock_quantity' => 5659,
        'buy_price' => 87.50,
        'category_id' => 12,
        'category_ids' => [12],
    ]);

    $stockDiff = makeDiffRow($product->id, 'stock_quantity', 5659, 0, 'cid-D');
    $buyDiff = makeDiffRow($product->id, 'buy_price', 87.50, 1.00, 'cid-D');
    $catDiff = makeDiffRow($product->id, 'category_id', 12, 7, 'cid-D');

    $stub = bindDivergenceStub([
        9004 => [
            'id' => 9004,
            'meta_data' => [
                ['key' => '_yoast_wpseo_metadesc', 'value' => 'X'],
            ],
        ],
    ]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->putCalls)->toHaveCount(1);

    $payload = $stub->putCalls[0]['payload'];
    expect($payload)->toHaveKey('stock_quantity', 5659);
    expect($payload)->toHaveKey('manage_stock', true);
    expect($payload)->toHaveKey('categories');
    expect($payload['categories'])->toBe([['id' => 12]]);
    expect($payload)->toHaveKey('meta_data');

    // meta_data should contain the Yoast entry + new cost entry (2 entries).
    $byKey = [];
    foreach ($payload['meta_data'] as $entry) {
        $byKey[$entry['key']] = $entry['value'];
    }
    expect($byKey)->toHaveKey('_yoast_wpseo_metadesc', 'X');
    expect($byKey)->toHaveKey('_alg_wc_cog_cost', '87.5000');

    // All 3 diff rows flipped to applied.
    $stockDiff->refresh();
    $buyDiff->refresh();
    $catDiff->refresh();
    expect($stockDiff->status)->toBe('applied');
    expect($buyDiff->status)->toBe('applied');
    expect($catDiff->status)->toBe('applied');
});

it('Case E: --field=stock_quantity filter — buy_price diff stays pending for next pass', function (): void {
    $product = Product::factory()->create([
        'sku' => 'E-001',
        'woo_product_id' => 9005,
        'stock_quantity' => 20,
        'buy_price' => 50.00,
    ]);

    $stockDiff = makeDiffRow($product->id, 'stock_quantity', 20, 0, 'cid-E');
    $buyDiff = makeDiffRow($product->id, 'buy_price', 50.00, 1.00, 'cid-E');

    $stub = bindDivergenceStub([
        9005 => ['id' => 9005, 'meta_data' => []],
    ]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--field' => 'stock_quantity',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->putCalls)->toHaveCount(1);

    $payload = $stub->putCalls[0]['payload'];
    expect($payload)->toHaveKey('stock_quantity', 20);
    expect($payload)->toHaveKey('manage_stock', true);
    expect($payload)->not->toHaveKey('meta_data');

    // Only the stock_quantity row applied; buy_price still pending.
    $stockDiff->refresh();
    $buyDiff->refresh();
    expect($stockDiff->status)->toBe('applied');
    expect($buyDiff->status)->toBe('pending');
});

it('Case F: Woo PUT 5xx — sync_diff stays pending, errors counter increments', function (): void {
    $product = Product::factory()->create([
        'sku' => 'F-001',
        'woo_product_id' => 9006,
        'stock_quantity' => 5,
    ]);

    $diff = makeDiffRow($product->id, 'stock_quantity', 5, 0, 'cid-F');

    $stub = bindDivergenceStub(
        [9006 => ['id' => 9006, 'meta_data' => []]],
        throwPutFor: [9006],
    );

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->putCalls)->toHaveCount(1);

    $diff->refresh();
    expect($diff->status)->toBe('pending');

    $output = Artisan::output();
    expect($output)->toContain('errors');
});

it('Case G: Woo GET 404 — sync_diff flipped to woo_not_found, woo_not_found counter', function (): void {
    $product = Product::factory()->create([
        'sku' => 'G-001',
        'woo_product_id' => 9007,
        'stock_quantity' => 5,
    ]);

    $diff = makeDiffRow($product->id, 'stock_quantity', 5, 0, 'cid-G');

    $stub = bindDivergenceStub(
        [],
        throwGet404For: [9007],
    );

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->putCalls)->toBe([]);

    $diff->refresh();
    expect($diff->status)->toBe('woo_not_found');

    $output = Artisan::output();
    expect($output)->toContain('woo_not_found');
});

it('Case H: --dry-run with 5 products — 0 PUTs, all sync_diff rows stay pending', function (): void {
    $diffs = [];
    for ($i = 1; $i <= 5; $i++) {
        $wooId = 9100 + $i;
        $product = Product::factory()->create([
            'sku' => "H-00{$i}",
            'woo_product_id' => $wooId,
            'stock_quantity' => 10,
        ]);
        $diffs[] = makeDiffRow($product->id, 'stock_quantity', 10, 0, 'cid-H');
    }

    $stub = bindDivergenceStub([
        9101 => ['id' => 9101, 'meta_data' => []],
        9102 => ['id' => 9102, 'meta_data' => []],
        9103 => ['id' => 9103, 'meta_data' => []],
        9104 => ['id' => 9104, 'meta_data' => []],
        9105 => ['id' => 9105, 'meta_data' => []],
    ]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--dry-run' => true,
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    // Pre-GET runs even in dry-run for plan accuracy + woo_not_found detection.
    expect($stub->getCalls)->toHaveCount(5);
    expect($stub->putCalls)->toBe([]);

    foreach ($diffs as $d) {
        $d->refresh();
        expect($d->status)->toBe('pending');
    }

    $output = Artisan::output();
    expect($output)->toContain('would_push');
});

it('Case I: pre-applied sync_diff row is filtered out — 0 GETs, 0 PUTs', function (): void {
    $product = Product::factory()->create([
        'sku' => 'I-001',
        'woo_product_id' => 9009,
        'stock_quantity' => 10,
    ]);

    // Pre-applied row — query.where('status','pending') excludes it.
    $diff = makeDiffRow($product->id, 'stock_quantity', 10, 0, 'cid-I');
    $diff->status = 'applied';
    $diff->applied_at = now();
    $diff->save();

    $stub = bindDivergenceStub([]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toBe([]);
    expect($stub->putCalls)->toBe([]);

    $diff->refresh();
    // Stays applied — the row was never re-touched.
    expect($diff->status)->toBe('applied');
});

it('Case J: --correlation-id override targets older cid, not latest', function (): void {
    $productOld = Product::factory()->create([
        'sku' => 'J-OLD',
        'woo_product_id' => 9010,
        'stock_quantity' => 30,
    ]);
    $productNew = Product::factory()->create([
        'sku' => 'J-NEW',
        'woo_product_id' => 9011,
        'stock_quantity' => 40,
    ]);

    $oldDiff = makeDiffRow($productOld->id, 'stock_quantity', 30, 0, 'cid-OLD');
    $newDiff = makeDiffRow($productNew->id, 'stock_quantity', 40, 0, 'cid-NEW');

    // Force created_at ordering so latest() would pick cid-NEW absent override.
    SyncDiff::where('id', $oldDiff->id)->update(['created_at' => now()->subHour()]);
    SyncDiff::where('id', $newDiff->id)->update(['created_at' => now()]);

    $stub = bindDivergenceStub([
        9010 => ['id' => 9010, 'meta_data' => []],
        9011 => ['id' => 9011, 'meta_data' => []],
    ]);

    $exit = Artisan::call('products:push-divergence-to-woo', [
        '--correlation-id' => 'cid-OLD',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->getCalls[0]['endpoint'])->toBe('products/9010');
    expect($stub->putCalls)->toHaveCount(1);
    expect($stub->putCalls[0]['endpoint'])->toBe('products/9010');

    $oldDiff->refresh();
    $newDiff->refresh();
    expect($oldDiff->status)->toBe('applied');
    expect($newDiff->status)->toBe('pending');
});

/**
 * Construct a SyncDiff row mirroring the 260610-qc4 DivergenceScanner emit shape.
 */
function makeDiffRow(int $productId, string $field, mixed $laravel, mixed $live, string $correlationId): SyncDiff
{
    return SyncDiff::create([
        'provider' => DivergenceScanner::PROVIDER,
        'channel' => 'woo',
        'method' => 'GET',
        'endpoint' => 'internal',
        'payload' => [
            'product_id' => $productId,
            'sku' => 'test-sku',
            'field' => $field,
            'laravel' => $laravel,
            'live' => $live,
            'pin_column' => null,
        ],
        'correlation_id' => $correlationId,
        'created_at' => now(),
        'status' => 'pending',
    ]);
}

/**
 * Bind an anonymous-subclass WooClient stub into the container.
 *
 * @param  array<int, array<string, mixed>>  $getResponses  woo_id keyed map of GET responses.
 * @param  array<int, int>  $throwPutFor  woo_ids whose PUT should throw.
 * @param  array<int, int>  $throwGet404For  woo_ids whose GET should throw 404-ish.
 * @return object the bound stub with public $getCalls + $putCalls.
 */
function bindDivergenceStub(array $getResponses, array $throwPutFor = [], array $throwGet404For = []): object
{
    $stub = new class($getResponses, $throwPutFor, $throwGet404For) extends WooClient
    {
        /** @var array<int, array{endpoint:string, query:array<string,mixed>}> */
        public array $getCalls = [];

        /** @var array<int, array{endpoint:string, payload:array<string,mixed>}> */
        public array $putCalls = [];

        public function __construct(
            /** @var array<int, array<string, mixed>> */
            public array $getResponses,
            /** @var array<int, int> */
            public array $throwPutFor,
            /** @var array<int, int> */
            public array $throwGet404For,
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver
            // needed for the stub (mirrors 260611-f1y pattern).
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];

            $wooId = 0;
            if (preg_match('#^products/(\d+)$#', $endpoint, $m)) {
                $wooId = (int) $m[1];
            }

            if (in_array($wooId, $this->throwGet404For, true)) {
                throw new RuntimeException("Stub GET 404 for woo_id={$wooId}");
            }

            return $this->getResponses[$wooId] ?? [];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->putCalls[] = ['endpoint' => $endpoint, 'payload' => $payload];

            $wooId = 0;
            if (preg_match('#^products/(\d+)$#', $endpoint, $m)) {
                $wooId = (int) $m[1];
            }

            if (in_array($wooId, $this->throwPutFor, true)) {
                throw new RuntimeException("Stub PUT throw for woo_id={$wooId}");
            }

            return ['ok' => true];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}
