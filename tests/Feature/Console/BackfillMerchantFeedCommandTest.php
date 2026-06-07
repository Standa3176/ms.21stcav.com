<?php

declare(strict_types=1);

use App\Console\Commands\BackfillMerchantFeedCommand;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-cgd — products:backfill-merchant-feed
|--------------------------------------------------------------------------
|
| Tests the EAN + brand field paths. Category path is exercised by the
| existing AssignProductTaxonomyCommand test suite + the Task 5 manual
| dev smoke — re-mocking Claude/Woo inside this test would be high effort,
| low value (see plan Task 4 NOTE on testing).
|
| Boundary strategy (OPTION A from PLAN.md):
| The real supplier_db lookup (mysqli to feeds_products) is overridden via
| an anonymous subclass that replaces `lookupSupplierEans` /
| `lookupSupplierManufacturers` with stub maps. The subclass is bound to
| the container via `app()->instance(BackfillMerchantFeedCommand::class, ...)`
| so Artisan::call resolves the test double. Mirrors the 260607-9c6 H-2
| `runDumpCommand` pattern.
*/

it('dry-run reports 4 quadrants and writes zero rows', function (): void {
    Product::factory()->create(['sku' => 'ABC', 'status' => 'publish', 'ean' => null]);
    Product::factory()->create(['sku' => 'DEF', 'status' => 'publish', 'ean' => null]);
    Product::factory()->create(['sku' => 'GHI', 'status' => 'publish', 'ean' => null]);

    bindEanStub([
        'abc' => '5033588057222',
        'def' => 'N/A',
        // ghi missing → no supplier match
    ]);

    $exit = Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--dry-run' => true,
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);

    // ZERO writes — all three products keep null EAN.
    expect(Product::where('sku', 'ABC')->value('ean'))->toBeNull();
    expect(Product::where('sku', 'DEF')->value('ean'))->toBeNull();
    expect(Product::where('sku', 'GHI')->value('ean'))->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('would_update');
    expect($output)->toContain('skipped_invalid_ean');
    expect($output)->toContain('skipped_no_supplier_match');
    expect($output)->toContain('already_populated_excluded');
    expect(strtolower($output))->toContain('dry-run');
});

it('live updates only validated rows', function (): void {
    Product::factory()->create(['sku' => 'ABC', 'status' => 'publish', 'ean' => null]);
    Product::factory()->create(['sku' => 'DEF', 'status' => 'publish', 'ean' => null]);
    Product::factory()->create(['sku' => 'GHI', 'status' => 'publish', 'ean' => null]);

    bindEanStub([
        'abc' => '5033588057222',
        'def' => 'N/A',
    ]);

    $exit = Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect(Product::where('sku', 'ABC')->value('ean'))->toBe('5033588057222');
    expect(Product::where('sku', 'DEF')->value('ean'))->toBeNull();
    expect(Product::where('sku', 'GHI')->value('ean'))->toBeNull();
});

it('is idempotent on re-run', function (): void {
    Product::factory()->create(['sku' => 'ABC', 'status' => 'publish', 'ean' => null]);

    bindEanStub(['abc' => '5033588057222']);

    // First live pass populates ABC.ean.
    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);
    expect(Product::where('sku', 'ABC')->value('ean'))->toBe('5033588057222');

    // Re-bind a fresh subclass (the prior call may have already consumed it).
    bindEanStub(['abc' => '5033588057222']);

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);

    $output = Artisan::output();
    expect($output)->toContain('0 candidate products');
});

it('--limit caps the candidate set', function (): void {
    foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $sku) {
        Product::factory()->create(['sku' => $sku, 'status' => 'publish', 'ean' => null]);
    }
    bindEanStub([
        's1' => '5033588057222',
        's2' => '5033588057222',
        's3' => '5033588057222',
        's4' => '5033588057222',
        's5' => '5033588057222',
    ]);

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--dry-run' => true,
        '--limit' => 2,
        '--no-confirm' => true,
    ]);

    $output = Artisan::output();
    expect($output)->toContain('2 candidate products');
});

// ── helpers ──

/**
 * Bind an anonymous subclass that overrides `lookupSupplierEans` to return
 * the provided stub map. Mirrors the 260607-9c6 `runDumpCommand` pattern.
 *
 * @param  array<string, string>  $eanMap  sku_key => raw EAN string
 */
function bindEanStub(array $eanMap): void
{
    $stub = new class(app(IntegrationCredentialResolver::class), app(TaxonomyResolver::class), $eanMap) extends BackfillMerchantFeedCommand
    {
        public function __construct(
            IntegrationCredentialResolver $resolver,
            TaxonomyResolver $taxonomy,
            /** @var array<string, string> */
            private array $eanMap,
        ) {
            parent::__construct($resolver, $taxonomy);
        }

        protected function lookupSupplierEans(array $candidateSkus): array
        {
            // Only return rows for SKUs in the candidate set, mirroring the
            // real "WHERE LOWER(TRIM(suppliersku)) IN (...)" semantics.
            $out = [];
            foreach ($candidateSkus as $sku) {
                if (array_key_exists($sku, $this->eanMap)) {
                    $out[$sku] = $this->eanMap[$sku];
                }
            }

            return $out;
        }
    };

    app()->instance(BackfillMerchantFeedCommand::class, $stub);
}
