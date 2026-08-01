<?php

declare(strict_types=1);

use App\Console\Support\Sleeper;
use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\WooClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Quick task 260726-egr — products:reconcile-ean-from-woo
|--------------------------------------------------------------------------
|
| Local `products.ean` drifted from Woo's real GTIN (`global_unique_id`).
| Proven on prod: A30-020 local ean `6938820000000` (fails the EAN-13 check
| digit) vs Woo `0841885115294` (valid). This command READS Woo's GTIN and
| pulls it back into the LOCAL columns (ean + woo_gtin), checksum-gated, and
| NEVER writes to Woo.
|
| Verdicts:
|   FIX               local empty/invalid + Woo valid → set local ean + woo_gtin
|   CONFLICT          both valid but differ           → report only, never change
|   in_sync           both valid and match            → skip
|   no_valid_woo_gtin Woo empty/invalid               → can't fix from Woo
|   read_failed       Woo GET fails all retries        → leave local untouched
|
| Boundary strategy: bind an anonymous-subclass WooClient stub that returns a
| fake `global_unique_id` per woo_product_id and RECORDS every write call so we
| can assert NO WooClient write is ever made. A recording no-op Sleeper is bound
| so retry-backoff / pacing never actually waits.
*/

// Known-good / known-bad GTINs (mirror the trait unit test).
const WOO_GTIN_VALID = '0841885115294';   // A30-020 real Woo GTIN
const WOO_GTIN_VALID_2 = '4006381333931';
const LOCAL_EAN_CORRUPT = '6938820000000'; // 13 digits, bad check digit

beforeEach(function (): void {
    // Recording no-op Sleeper — retry-backoff + pacing never really wait.
    $sleeper = new class extends Sleeper
    {
        /** @var array<int, int> */
        public array $microSleeps = [];

        public function seconds(int $seconds): void {}

        public function micros(int $micros): void
        {
            $this->microSleeps[] = $micros;
        }
    };
    app()->instance(Sleeper::class, $sleeper);
});

it('FIX (dry-run): invalid local + valid Woo → reports FIX, writes NOTHING, no Woo write', function (): void {
    Product::factory()->create([
        'sku' => 'A30-020',
        'woo_product_id' => 900,
        'ean' => LOCAL_EAN_CORRUPT,
        'woo_gtin' => null,
    ]);

    $stub = bindReconcileWooStub([900 => WOO_GTIN_VALID]);

    $exit = Artisan::call('products:reconcile-ean-from-woo', ['--skus' => 'A30-020']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    // Dry-run wrote nothing locally.
    expect(DB::table('products')->where('sku', 'A30-020')->value('ean'))->toBe(LOCAL_EAN_CORRUPT);
    expect(DB::table('products')->where('sku', 'A30-020')->value('woo_gtin'))->toBeNull();
    expect($output)->toContain('FIX');
    // The hard invariant: no Woo write EVER.
    expect($stub->writes)->toBe([]);
});

it('FIX (--apply): invalid local + valid Woo → sets local ean + woo_gtin, still no Woo write', function (): void {
    Product::factory()->create([
        'sku' => 'A30-020',
        'woo_product_id' => 900,
        'ean' => LOCAL_EAN_CORRUPT,
        'woo_gtin' => null,
    ]);

    $stub = bindReconcileWooStub([900 => WOO_GTIN_VALID]);

    $exit = Artisan::call('products:reconcile-ean-from-woo', ['--skus' => 'A30-020', '--apply' => true]);

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('sku', 'A30-020')->value('ean'))->toBe(WOO_GTIN_VALID);
    expect(DB::table('products')->where('sku', 'A30-020')->value('woo_gtin'))->toBe(WOO_GTIN_VALID);
    // Still zero Woo writes — LOCAL columns only.
    expect($stub->writes)->toBe([]);
});

it('FIX (--apply): empty local ean + valid Woo → sets local ean + woo_gtin', function (): void {
    Product::factory()->create([
        'sku' => 'DS-D6075UN',
        'woo_product_id' => 901,
        'ean' => null,
        'woo_gtin' => null,
    ]);

    bindReconcileWooStub([901 => WOO_GTIN_VALID_2]);

    Artisan::call('products:reconcile-ean-from-woo', ['--skus' => 'DS-D6075UN', '--apply' => true]);

    expect(DB::table('products')->where('sku', 'DS-D6075UN')->value('ean'))->toBe(WOO_GTIN_VALID_2);
    expect(DB::table('products')->where('sku', 'DS-D6075UN')->value('woo_gtin'))->toBe(WOO_GTIN_VALID_2);
});

it('CONFLICT (--apply): both valid but differ → reported, local UNCHANGED', function (): void {
    Product::factory()->create([
        'sku' => 'CONF-1',
        'woo_product_id' => 902,
        'ean' => WOO_GTIN_VALID,      // valid locally
        'woo_gtin' => null,
    ]);

    $stub = bindReconcileWooStub([902 => WOO_GTIN_VALID_2]); // valid but different

    $exit = Artisan::call('products:reconcile-ean-from-woo', ['--skus' => 'CONF-1', '--apply' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    // Even with --apply, a conflict is NEVER auto-changed.
    expect(DB::table('products')->where('sku', 'CONF-1')->value('ean'))->toBe(WOO_GTIN_VALID);
    expect(DB::table('products')->where('sku', 'CONF-1')->value('woo_gtin'))->toBeNull();
    expect($output)->toContain('CONFLICT');
    expect($stub->writes)->toBe([]);
});

it('in_sync: local valid == Woo valid → skipped, nothing written', function (): void {
    Product::factory()->create([
        'sku' => 'SYNC-1',
        'woo_product_id' => 903,
        'ean' => WOO_GTIN_VALID,
        'woo_gtin' => WOO_GTIN_VALID,
    ]);

    bindReconcileWooStub([903 => WOO_GTIN_VALID]);

    $exit = Artisan::call('products:reconcile-ean-from-woo', ['--skus' => 'SYNC-1', '--apply' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('sku', 'SYNC-1')->value('ean'))->toBe(WOO_GTIN_VALID);
    expect($output)->toContain('in_sync');
});

it('no_valid_woo_gtin: Woo GTIN empty/invalid → reported, local UNTOUCHED', function (): void {
    Product::factory()->create([
        'sku' => 'NOGT-1',
        'woo_product_id' => 904,
        'ean' => LOCAL_EAN_CORRUPT,
        'woo_gtin' => null,
    ]);

    // Woo returns a corrupted GTIN (fails checksum) — can't fix from Woo.
    bindReconcileWooStub([904 => '6936420000000']);

    $exit = Artisan::call('products:reconcile-ean-from-woo', ['--skus' => 'NOGT-1', '--apply' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect(DB::table('products')->where('sku', 'NOGT-1')->value('ean'))->toBe(LOCAL_EAN_CORRUPT);
    expect($output)->toContain('no_valid_woo_gtin');
});

it('read_failed: Woo GET throws through all retries → left untouched, counted', function (): void {
    Product::factory()->create([
        'sku' => 'READ-FAIL',
        'woo_product_id' => 905,
        'ean' => LOCAL_EAN_CORRUPT,
        'woo_gtin' => null,
    ]);

    $stub = bindReconcileWooStubThrowing(new RuntimeException('JSON ERROR: Syntax error'));

    $exit = Artisan::call('products:reconcile-ean-from-woo', [
        '--skus' => 'READ-FAIL',
        '--apply' => true,
        '--read-retries' => 2,
        '--read-backoff-ms' => 0,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    // Never "fix" from a failed read.
    expect(DB::table('products')->where('sku', 'READ-FAIL')->value('ean'))->toBe(LOCAL_EAN_CORRUPT);
    expect(DB::table('products')->where('sku', 'READ-FAIL')->value('woo_gtin'))->toBeNull();
    expect($output)->toContain('read_failed');
    // retries=2 → 3 total attempts before giving up.
    expect($stub->reads)->toHaveCount(3);
    expect($stub->writes)->toBe([]);
});

it('--skus scopes to the named SKUs only (other broken rows not read)', function (): void {
    Product::factory()->create([
        'sku' => 'TARGET',
        'woo_product_id' => 910,
        'ean' => LOCAL_EAN_CORRUPT,
    ]);
    Product::factory()->create([
        'sku' => 'OTHER',
        'woo_product_id' => 911,
        'ean' => LOCAL_EAN_CORRUPT,
    ]);

    $stub = bindReconcileWooStub([910 => WOO_GTIN_VALID, 911 => WOO_GTIN_VALID]);

    Artisan::call('products:reconcile-ean-from-woo', ['--skus' => 'TARGET']);

    expect($stub->reads)->toBe(['products/910']);
});

it('default scope selects only empty/invalid-local rows (valid local excluded)', function (): void {
    // Broken — invalid local ean → in scope.
    Product::factory()->create([
        'sku' => 'BROKEN',
        'woo_product_id' => 920,
        'ean' => LOCAL_EAN_CORRUPT,
    ]);
    // Empty local ean → in scope.
    Product::factory()->create([
        'sku' => 'EMPTY',
        'woo_product_id' => 921,
        'ean' => null,
    ]);
    // Valid local ean → EXCLUDED from default scope.
    Product::factory()->create([
        'sku' => 'GOOD',
        'woo_product_id' => 922,
        'ean' => WOO_GTIN_VALID,
    ]);

    $stub = bindReconcileWooStub([
        920 => WOO_GTIN_VALID,
        921 => WOO_GTIN_VALID_2,
        922 => WOO_GTIN_VALID,
    ]);

    // No --skus, no --all → default broken-set scope.
    Artisan::call('products:reconcile-ean-from-woo', []);

    sort($stub->reads);
    expect($stub->reads)->toBe(['products/920', 'products/921']);
});

it('writes a --csv with per-product verdict rows', function (): void {
    Product::factory()->create([
        'sku' => 'CSV-1',
        'woo_product_id' => 930,
        'ean' => LOCAL_EAN_CORRUPT,
    ]);

    bindReconcileWooStub([930 => WOO_GTIN_VALID]);

    $csvPath = storage_path('app/testing/ean-reconcile-'.uniqid().'.csv');

    Artisan::call('products:reconcile-ean-from-woo', [
        '--skus' => 'CSV-1',
        '--csv' => $csvPath,
    ]);

    expect(file_exists($csvPath))->toBeTrue();
    $contents = (string) file_get_contents($csvPath);
    expect($contents)->toContain('sku,woo_id,local_ean,woo_gtin,local_valid,woo_valid,verdict');
    expect($contents)->toContain('CSV-1');
    expect($contents)->toContain(WOO_GTIN_VALID);
    expect($contents)->toContain('fix');

    @unlink($csvPath);
});

/**
 * Bind an anonymous-subclass WooClient stub that returns a fake
 * `global_unique_id` per woo_product_id and RECORDS every read + write.
 *
 * @param  array<int, string>  $gtinByWooId  woo_product_id => global_unique_id
 * @return object the bound stub with public $reads + $writes
 */
function bindReconcileWooStub(array $gtinByWooId): object
{
    $stub = new class($gtinByWooId) extends WooClient
    {
        /** @var array<int, string> */
        public array $reads = [];

        /** @var array<int, array{0:string,1:string,2:array<string,mixed>}> */
        public array $writes = [];

        public function __construct(
            /** @var array<int, string> */
            public array $gtinByWooId,
        ) {
            // Skip parent constructor — no resolver / logger needed for the stub.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->reads[] = $endpoint;
            $id = preg_match('#products/(\d+)#', $endpoint, $m) === 1 ? (int) $m[1] : 0;

            if (! array_key_exists($id, $this->gtinByWooId)) {
                return ['id' => $id]; // no global_unique_id key at all
            }

            return ['id' => $id, 'global_unique_id' => $this->gtinByWooId[$id]];
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->writes[] = ['put', $endpoint, $payload];

            return [];
        }

        public function post(string $endpoint, array $payload): array
        {
            $this->writes[] = ['post', $endpoint, $payload];

            return [];
        }

        public function patch(string $endpoint, array $payload): array
        {
            $this->writes[] = ['patch', $endpoint, $payload];

            return [];
        }

        public function delete(string $endpoint, array $payload = []): array
        {
            $this->writes[] = ['delete', $endpoint, $payload];

            return [];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}

/**
 * Bind a WooClient stub whose get() always throws (flaky-endpoint simulation)
 * and records every attempt — for the read_failed path.
 */
function bindReconcileWooStubThrowing(Throwable $e): object
{
    $stub = new class($e) extends WooClient
    {
        /** @var array<int, string> */
        public array $reads = [];

        /** @var array<int, array{0:string,1:string,2:array<string,mixed>}> */
        public array $writes = [];

        public function __construct(private Throwable $throwOnGet)
        {
            // Skip parent constructor.
        }

        public function get(string $endpoint, array $query = []): array
        {
            $this->reads[] = $endpoint;
            throw $this->throwOnGet;
        }

        public function put(string $endpoint, array $payload): array
        {
            $this->writes[] = ['put', $endpoint, $payload];

            return [];
        }

        public function post(string $endpoint, array $payload): array
        {
            $this->writes[] = ['post', $endpoint, $payload];

            return [];
        }

        public function patch(string $endpoint, array $payload): array
        {
            $this->writes[] = ['patch', $endpoint, $payload];

            return [];
        }

        public function delete(string $endpoint, array $payload = []): array
        {
            $this->writes[] = ['delete', $endpoint, $payload];

            return [];
        }
    };

    app()->instance(WooClient::class, $stub);

    return $stub;
}
