<?php

declare(strict_types=1);

use App\Domain\Products\Models\Product;
use App\Domain\Sync\Services\SupplierEanLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260726-sle — supplier:lookup-eans (READ-ONLY EAN lookup)
|--------------------------------------------------------------------------
|
| Boundary strategy: the remote supplier_db read (SupplierEanLookup, raw
| mysqli to feeds_products) is faked via an anonymous subclass bound to the
| container so tests need NO real DB. The fake is constructed with a
| suppliersku-map + an mpn-map and REPLICATES the real two-pass precedence
| (suppliersku wins, mpn fallback only for still-unmatched SKUs) so the
| command's matched_by passthrough is exercised faithfully.
*/

/**
 * Bind a fake SupplierEanLookup that mirrors the real two-pass precedence.
 *
 * @param  array<string, string>  $supplierskuMap  sku_key => raw EAN (suppliersku pass)
 * @param  array<string, string>  $mpnMap  sku_key => raw EAN (mpn fallback pass)
 */
function bindSupplierEanLookupStub(array $supplierskuMap, array $mpnMap = []): void
{
    $fake = new class($supplierskuMap, $mpnMap) extends SupplierEanLookup
    {
        public int $callCount = 0;

        public function __construct(
            /** @var array<string, string> */
            private array $supplierskuMap,
            /** @var array<string, string> */
            private array $mpnMap,
        ) {
            // Skip parent constructor — no IntegrationCredentialResolver needed;
            // lookup() is fully overridden and never touches the resolver.
        }

        public function lookup(array $skuKeys): array
        {
            $this->callCount++;
            $out = [];
            // Pass 1 — suppliersku wins.
            foreach ($skuKeys as $key) {
                if ($key === '' || isset($out[$key])) {
                    continue;
                }
                if (array_key_exists($key, $this->supplierskuMap)) {
                    $out[$key] = ['ean' => (string) $this->supplierskuMap[$key], 'matched_by' => 'suppliersku'];
                }
            }
            // Pass 2 — mpn fallback for still-unmatched keys only.
            foreach ($skuKeys as $key) {
                if ($key === '' || isset($out[$key])) {
                    continue;
                }
                if (array_key_exists($key, $this->mpnMap)) {
                    $out[$key] = ['ean' => (string) $this->mpnMap[$key], 'matched_by' => 'mpn'];
                }
            }

            return $out;
        }
    };

    app()->instance(SupplierEanLookup::class, $fake);
}

it('errors cleanly when neither --skus nor --skus-file is supplied', function (): void {
    bindSupplierEanLookupStub([]);

    $exit = Artisan::call('supplier:lookup-eans', []);

    expect($exit)->not->toBe(0);
    expect(Artisan::output())->toContain('No SKUs');
});

it('reports supplier EAN, normalisation and checksum via the NormalisesEan trait', function (): void {
    // 6938820000000 = A30-020's corrupted local value — right length, fails mod-10.
    // 5033588057222 = a genuinely checksum-valid EAN-13.
    bindSupplierEanLookupStub([
        'corrupt' => '6938820000000',
        'valid' => '5033588057222',
    ]);

    $exit = Artisan::call('supplier:lookup-eans', [
        '--skus' => 'CORRUPT,VALID',
    ]);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('6938820000000');
    expect($output)->toContain('5033588057222');
    // Summary counts: 2 found, 1 checksum-valid.
    expect($output)->toContain('Found: 2');
    expect($output)->toContain('Checksum-valid: 1');
});

it('marks an unmatched SKU as found=no with matched_by=none', function (): void {
    bindSupplierEanLookupStub(['known' => '5033588057222']);

    Artisan::call('supplier:lookup-eans', [
        '--skus' => 'KNOWN,GHOST',
        '--csv' => csvPath('unmatched.csv'),
    ]);

    $rows = readCsv(csvPath('unmatched.csv'));
    $byS = collect($rows)->keyBy('sku');

    expect($byS['KNOWN']['found'])->toBe('yes');
    expect($byS['KNOWN']['matched_by'])->toBe('suppliersku');
    expect($byS['GHOST']['found'])->toBe('no');
    expect($byS['GHOST']['matched_by'])->toBe('none');
    expect($byS['GHOST']['supplier_ean'])->toBe('');
});

it('resolves suppliersku match ahead of mpn match (suppliersku wins)', function (): void {
    // 'dup' is in BOTH maps — the suppliersku pass must win.
    bindSupplierEanLookupStub(
        supplierskuMap: ['dup' => '5033588057222'],
        mpnMap: ['dup' => '4006381333931', 'mponly' => '4006381333931'],
    );

    Artisan::call('supplier:lookup-eans', [
        '--skus' => 'DUP,MPONLY',
        '--csv' => csvPath('wins.csv'),
    ]);

    $byS = collect(readCsv(csvPath('wins.csv')))->keyBy('sku');

    expect($byS['DUP']['matched_by'])->toBe('suppliersku');
    expect($byS['DUP']['supplier_ean'])->toBe('5033588057222');
    expect($byS['MPONLY']['matched_by'])->toBe('mpn');
});

it('writes a CSV with the exact header and one row per input SKU', function (): void {
    bindSupplierEanLookupStub([
        'a1' => '5033588057222',
        'b2' => '4006381333931',
    ]);

    Artisan::call('supplier:lookup-eans', [
        '--skus' => 'A1,B2,C3',
        '--csv' => csvPath('out.csv'),
    ]);

    $raw = file_get_contents(csvPath('out.csv'));
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $raw)), fn ($l) => $l !== ''));

    expect($lines[0])->toBe('sku,supplier_ean,normalised,checksum_valid,matched_by,found');
    // Header + 3 data rows.
    expect(count($lines))->toBe(4);
});

it('unions and de-duplicates SKUs across --skus and --skus-file', function (): void {
    bindSupplierEanLookupStub([
        'x1' => '5033588057222',
        'x2' => '4006381333931',
        'x3' => '5033588057222',
    ]);

    $file = csvPath('skus.txt');
    file_put_contents($file, "X2\n x3 \nX4\n\n");

    Artisan::call('supplier:lookup-eans', [
        '--skus' => 'X1, X2 ',
        '--skus-file' => $file,
        '--csv' => csvPath('union.csv'),
    ]);

    $rows = readCsv(csvPath('union.csv'));
    $skus = collect($rows)->pluck('sku')->map(fn ($s) => strtoupper($s))->sort()->values()->all();

    // X1 (skus), X2 (both — deduped), X3 (file), X4 (file) — 4 unique.
    expect($skus)->toBe(['X1', 'X2', 'X3', 'X4']);
});

it('performs no writes to the local products table (read-only)', function (): void {
    $p = Product::factory()->create(['sku' => 'RO-1', 'status' => 'publish', 'ean' => null]);

    bindSupplierEanLookupStub(['ro-1' => '5033588057222']);

    Artisan::call('supplier:lookup-eans', [
        '--skus' => 'RO-1',
        '--csv' => csvPath('readonly.csv'),
    ]);

    $p->refresh();
    // The product row is completely untouched — no ean written, count unchanged.
    expect($p->ean)->toBeNull();
    expect(Product::count())->toBe(1);

    // The lookup seam was consulted exactly once (read), and it exposes no write API.
    /** @var object{callCount:int} $lookup */
    $lookup = app(SupplierEanLookup::class);
    expect($lookup->callCount)->toBe(1);
    expect(method_exists($lookup, 'write'))->toBeFalse();
});

// ── helpers ──

function csvPath(string $name): string
{
    $dir = storage_path('app/testing/260726-sle');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir.DIRECTORY_SEPARATOR.$name;
}

/**
 * @return array<int, array<string, string>>
 */
function readCsv(string $path): array
{
    $raw = file_get_contents($path);
    $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $raw)), fn ($l) => $l !== ''));
    $header = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = array_combine($header, str_getcsv($line));
    }

    return $rows;
}
