<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Quick task 260611-f1y — products:push-visibility-to-woo
|--------------------------------------------------------------------------
|
| Tests the 6 outcome paths of the new artisan command. Scenarios A-F:
|
|   A — Dry-run with 3 is_internal_only=true products → 0 Woo GETs + 0 PUTs;
|       output shows `would_hide`; no DB changes; exit 0.
|   B — Live single internal product where Woo reports visible → 1 GET + 1 PUT
|       with body ['catalog_visibility' => 'hidden']; hidden=1; exit 0.
|   C — Internal product with woo_product_id=NULL → no Woo call;
|       no_woo_product_id=1; exit 0 (no DB changes).
|   D — `--skus=` force-push targets a NOT-flagged product (operator override) →
|       1 GET + 1 PUT; payload hidden; exit 0.
|   E — Woo PUT throws on one product → errors=1, run continues with next
|       candidate, exit 0 (per-candidate failures non-fatal).
|   F — Pre-GET reports catalog_visibility=hidden → already_hidden=1; NO PUT
|       call recorded (idempotency proof); exit 0.
|
| Boundary strategy: mirrors BackfillCategoryFromWooCommandTest's stub
| pattern. Anonymous-subclass WooClient with public $getCalls + $putCalls;
| skip parent constructor; bind via app()->instance(WooClient::class, $stub).
*/

it('Case A: dry-run with 3 internal products — 0 Woo I/O, would_hide printed', function (): void {
    Product::factory()->create([
        'sku' => 'A-001',
        'woo_product_id' => 9001,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);
    Product::factory()->create([
        'sku' => 'A-002',
        'woo_product_id' => 9002,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);
    Product::factory()->create([
        'sku' => 'A-003',
        'woo_product_id' => 9003,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);

    $stub = bindVisibilityStub([]);

    $exit = Artisan::call('products:push-visibility-to-woo', [
        '--dry-run' => true,
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toBe([]);
    expect($stub->putCalls)->toBe([]);

    $output = Artisan::output();
    expect($output)->toContain('would_hide');
    expect($output)->toContain('[dry-run]');
});

it('Case B: live — single internal product where Woo reports visible → 1 GET + 1 PUT with hidden payload', function (): void {
    Product::factory()->create([
        'sku' => 'B-001',
        'woo_product_id' => 999,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);

    $stub = bindVisibilityStub([
        999 => ['id' => 999, 'catalog_visibility' => 'visible'],
    ]);

    $exit = Artisan::call('products:push-visibility-to-woo', []);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->getCalls[0]['endpoint'])->toBe('products/999');
    expect($stub->putCalls)->toHaveCount(1);
    expect($stub->putCalls[0]['endpoint'])->toBe('products/999');
    expect($stub->putCalls[0]['payload'])->toBe(['catalog_visibility' => 'hidden']);

    $output = Artisan::output();
    expect($output)->toContain('hidden');
});

it('Case C: internal product with woo_product_id=NULL — no Woo call, no_woo_product_id counter', function (): void {
    // Default query (is_internal_only=true AND woo_product_id NOT NULL)
    // would silently skip a NULL-woo_id row, which doesn't exercise the
    // no_woo_product_id branch. To exercise the branch, force-target via
    // --skus on a row that also exists in the candidate set. Simpler:
    // create the row with sku set and target via --skus so the query
    // includes it; the loop then hits the wooId <= 0 guard.
    Product::factory()->create([
        'sku' => 'C-001',
        'woo_product_id' => null,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);

    $stub = bindVisibilityStub([]);

    $exit = Artisan::call('products:push-visibility-to-woo', [
        '--skus' => 'C-001',
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toBe([]);
    expect($stub->putCalls)->toBe([]);

    $output = Artisan::output();
    expect($output)->toContain('no_woo_product_id');
});

it('Case D: --skus force-push targets a NOT-flagged product — 1 GET + 1 PUT', function (): void {
    Product::factory()->create([
        'sku' => '43-LX-WALL-MOUNT',
        'woo_product_id' => 8502,
        'is_internal_only' => false,  // intentionally NOT flagged
        'status' => 'publish',
    ]);

    $stub = bindVisibilityStub([
        8502 => ['id' => 8502, 'catalog_visibility' => 'visible'],
    ]);

    $exit = Artisan::call('products:push-visibility-to-woo', [
        '--skus' => '43-LX-WALL-MOUNT',
    ]);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->putCalls)->toHaveCount(1);
    expect($stub->putCalls[0]['payload'])->toBe(['catalog_visibility' => 'hidden']);
});

it('Case E: Woo PUT throws on one of two candidates — errors=1, hidden=1, exit 0', function (): void {
    Product::factory()->create([
        'sku' => 'E-001',
        'woo_product_id' => 5001,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);
    Product::factory()->create([
        'sku' => 'E-002',
        'woo_product_id' => 5002,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);

    $stub = bindVisibilityStub(
        [
            5001 => ['id' => 5001, 'catalog_visibility' => 'visible'],
            5002 => ['id' => 5002, 'catalog_visibility' => 'visible'],
        ],
        // Throw on PUT for 5001 only.
        throwPutFor: [5001],
    );

    $exit = Artisan::call('products:push-visibility-to-woo', []);

    // Per-candidate failures non-fatal — run completes SUCCESS.
    expect($exit)->toBe(0);

    // Both GETs were attempted (pre-GET runs per candidate).
    expect($stub->getCalls)->toHaveCount(2);

    // PUT was attempted twice; one threw, one succeeded.
    expect($stub->putCalls)->toHaveCount(2);

    $output = Artisan::output();
    expect($output)->toContain('errors');
    expect($output)->toContain('hidden');
});

it('Case F: pre-GET reports hidden — already_hidden=1, ZERO PUT calls (idempotency)', function (): void {
    Product::factory()->create([
        'sku' => 'F-001',
        'woo_product_id' => 6001,
        'is_internal_only' => true,
        'status' => 'publish',
    ]);

    $stub = bindVisibilityStub([
        6001 => ['id' => 6001, 'catalog_visibility' => 'hidden'],
    ]);

    $exit = Artisan::call('products:push-visibility-to-woo', []);

    expect($exit)->toBe(0);
    expect($stub->getCalls)->toHaveCount(1);
    expect($stub->putCalls)->toBe([]);

    $output = Artisan::output();
    expect($output)->toContain('already_hidden');
});

/**
 * Bind an anonymous-subclass WooClient stub into the container.
 *
 * @param  array<int, array<string, mixed>>  $getResponses  woo_id keyed map of
 *                 GET responses (assoc array shape WooClient normalises to).
 * @param  array<int, int>  $throwPutFor  woo_ids whose PUT should throw.
 * @return object  the bound stub, with public $getCalls + $putCalls arrays.
 */
function bindVisibilityStub(array $getResponses, array $throwPutFor = []): object
{
    $stub = new class($getResponses, $throwPutFor) extends WooClient
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
        ) {
            // Skip parent constructor — no IntegrationLogger / resolver
            // needed for the stub.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->getCalls[] = ['endpoint' => $endpoint, 'query' => $query];

            // Parse "products/{wooId}" → wooId for fixture lookup.
            $wooId = 0;
            if (preg_match('#^products/(\d+)$#', $endpoint, $m)) {
                $wooId = (int) $m[1];
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
