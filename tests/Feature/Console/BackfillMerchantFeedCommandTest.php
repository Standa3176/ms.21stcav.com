<?php

declare(strict_types=1);

use App\Console\Commands\BackfillMerchantFeedCommand;
use App\Domain\Integrations\Services\IntegrationCredentialResolver;
use App\Domain\ProductAutoCreate\Services\EanSearchClient;
use App\Domain\ProductAutoCreate\Services\IcecatClient;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Foundation\Integration\Services\IntegrationLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-cgd — products:backfill-merchant-feed
| Extended 260607-g25 — Icecat EAN fallback for stuck SKUs (cases A-F)
| Extended 260607-hxa — EAN-search.org default provider + bucket rename
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
| so Artisan::call resolves the test double.
|
| EAN-lookup boundary (260607-hxa):
| Under the default 'ean_search' provider, the EanSearchClient is bound at
| the container via app()->instance(). The fake exposes a public callCount
| so Case D + Case E can assert "EanSearch was NOT called" (supplier-first
| / opt-out semantics). An IcecatClient throw-on-call fake is ALSO bound so
| any accidental Icecat call under the default provider fails loudly.
| Case G flips EAN_FALLBACK_PROVIDER to 'icecat' via config() and asserts
| that IcecatClient IS called and EanSearchClient is NOT.
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
        '--no-icecat-fallback' => true,  // 260607-cgd parity output
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

    bindEanStub(
        eanMap: [
            'abc' => '5033588057222',
            'def' => 'N/A',
        ],
        // EAN-search would no-match DEF / GHI; bind a fake that always returns
        // null so the supplier-N/A row still ends up null (ean_lookup_no_match),
        // not recovered.
        eanLookupGtinMap: [],
    );

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
        '--no-icecat-fallback' => true,
    ]);
    expect(Product::where('sku', 'ABC')->value('ean'))->toBe('5033588057222');

    // Re-bind a fresh subclass (the prior call may have already consumed it).
    bindEanStub(['abc' => '5033588057222']);

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
        '--no-icecat-fallback' => true,
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
        '--no-icecat-fallback' => true,
    ]);

    $output = Artisan::output();
    expect($output)->toContain('2 candidate products');
});

// ── Brand path (Task 3) ──

it('--field=brand live updates only resolved brands', function (): void {
    Product::factory()->create(['sku' => 'SONY', 'status' => 'publish', 'brand_id' => null, 'ean' => '0000000000001']);
    Product::factory()->create(['sku' => 'LINSX', 'status' => 'publish', 'brand_id' => null, 'ean' => '0000000000002']);
    Product::factory()->create(['sku' => 'NONE', 'status' => 'publish', 'brand_id' => null, 'ean' => '0000000000003']);

    bindBrandStub(
        mfrMap: [
            'sony' => 'Sony',
            'linsx' => 'Linsx',
            // none missing → no supplier manufacturer
        ],
        resolveBrandMap: [
            'Sony' => 42,
            'Linsx' => null, // below threshold
        ],
        allBrands: [['id' => 42, 'name' => 'Sony']],
    );

    $exit = Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'brand',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect(Product::where('sku', 'SONY')->value('brand_id'))->toBe(42);
    expect(Product::where('sku', 'LINSX')->value('brand_id'))->toBeNull();
    expect(Product::where('sku', 'NONE')->value('brand_id'))->toBeNull();
});

it('--field=brand fuzzy below threshold does not write', function (): void {
    Product::factory()->create(['sku' => 'LINSX', 'status' => 'publish', 'brand_id' => null, 'ean' => '0000000000099']);

    bindBrandStub(
        mfrMap: ['linsx' => 'Linsx'],
        resolveBrandMap: ['Linsx' => null],
        allBrands: [['id' => 42, 'name' => 'Sony']],
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'brand',
        '--no-confirm' => true,
    ]);

    expect(Product::where('sku', 'LINSX')->value('brand_id'))->toBeNull();
    $output = Artisan::output();
    expect($output)->toContain('skipped_fuzzy_below_threshold');
});

it('--field=brand dry-run writes nothing', function (): void {
    Product::factory()->create(['sku' => 'SONY', 'status' => 'publish', 'brand_id' => null, 'ean' => '0000000000004']);
    Product::factory()->create(['sku' => 'LINSX', 'status' => 'publish', 'brand_id' => null, 'ean' => '0000000000005']);
    Product::factory()->create(['sku' => 'NONE', 'status' => 'publish', 'brand_id' => null, 'ean' => '0000000000006']);

    bindBrandStub(
        mfrMap: [
            'sony' => 'Sony',
            'linsx' => 'Linsx',
        ],
        resolveBrandMap: [
            'Sony' => 42,
            'Linsx' => null,
        ],
        allBrands: [['id' => 42, 'name' => 'Sony']],
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'brand',
        '--dry-run' => true,
        '--no-confirm' => true,
    ]);

    expect(Product::where('sku', 'SONY')->value('brand_id'))->toBeNull();
    expect(Product::where('sku', 'LINSX')->value('brand_id'))->toBeNull();
    expect(Product::where('sku', 'NONE')->value('brand_id'))->toBeNull();

    $output = Artisan::output();
    expect($output)->toContain('would_update');
    expect($output)->toContain('skipped_fuzzy_below_threshold');
    expect($output)->toContain('skipped_no_supplier_manufacturer');
});

// ── EAN-lookup fallback cases A-F (260607-hxa rename of 260607-g25) ──

it('Case A: EAN-lookup fallback writes EAN when supplier returns N/A and provider returns a valid GTIN', function (): void {
    // brand_id=42 (Sony) so EanSearch gets brand="Sony"+mpn="FW-50EZ20L"
    Product::factory()->create([
        'sku' => 'FW-50EZ20L',
        'status' => 'publish',
        'ean' => null,
        'brand_id' => 42,
    ]);

    bindBrandTermsForEanLookup([['id' => 42, 'name' => 'Sony']]);
    bindEanStub(
        eanMap: ['fw-50ez20l' => 'N/A'],
        eanLookupGtinMap: ['FW-50EZ20L' => '4548736142680'],
    );

    $exit = Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);

    expect($exit)->toBe(0);
    expect(Product::where('sku', 'FW-50EZ20L')->value('ean'))->toBe('4548736142680');
    $output = Artisan::output();
    expect($output)->toContain('recovered_from_ean_lookup');
    expect($output)->toContain('EAN lookup fallback ENABLED via ean_search');
});

it('Case B: ean_lookup_no_match — supplier empty, provider returns null, product stays null', function (): void {
    Product::factory()->create([
        'sku' => 'UNK-1',
        'status' => 'publish',
        'ean' => null,
        'brand_id' => 42,
    ]);

    bindBrandTermsForEanLookup([['id' => 42, 'name' => 'Sony']]);
    bindEanStub(
        eanMap: ['unk-1' => 'N/A'],   // supplier sees row, invalid
        eanLookupGtinMap: [],          // EanSearch → null
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);

    expect(Product::where('sku', 'UNK-1')->value('ean'))->toBeNull();
    $output = Artisan::output();
    expect($output)->toContain('ean_lookup_no_match');
});

it('Case C: ean_lookup_invalid_ean — provider returns a placeholder that fails NormalisesEan', function (): void {
    Product::factory()->create([
        'sku' => 'BAD-1',
        'status' => 'publish',
        'ean' => null,
        'brand_id' => 42,
    ]);

    bindBrandTermsForEanLookup([['id' => 42, 'name' => 'Sony']]);
    bindEanStub(
        eanMap: ['bad-1' => 'N/A'],
        eanLookupGtinMap: ['BAD-1' => 'N/A'],  // junk that fails normaliseEan
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);

    expect(Product::where('sku', 'BAD-1')->value('ean'))->toBeNull();
    $output = Artisan::output();
    expect($output)->toContain('ean_lookup_invalid_ean');
});

it('Case D: supplier-first wins — valid supplier EAN means EanSearch is NEVER called', function (): void {
    Product::factory()->create([
        'sku' => 'WIN-1',
        'status' => 'publish',
        'ean' => null,
        'brand_id' => 42,
    ]);

    bindBrandTermsForEanLookup([['id' => 42, 'name' => 'Sony']]);
    // eanLookupGtinMap=null → throw-on-call fake.
    bindEanStub(
        eanMap: ['win-1' => '5033588057222'],
        eanLookupGtinMap: null,
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);

    expect(Product::where('sku', 'WIN-1')->value('ean'))->toBe('5033588057222');

    /** @var object{callCount:int} $eanSearch */
    $eanSearch = app(EanSearchClient::class);
    expect($eanSearch->callCount)->toBe(0);

    /** @var object{callCount:int} $icecat */
    $icecat = app(IcecatClient::class);
    expect($icecat->callCount)->toBe(0);
});

it('Case E: --no-icecat-fallback restores 260607-cgd behaviour (no provider called)', function (): void {
    Product::factory()->create([
        'sku' => 'OPTOUT-1',
        'status' => 'publish',
        'ean' => null,
        'brand_id' => 42,
    ]);

    bindBrandTermsForEanLookup([['id' => 42, 'name' => 'Sony']]);
    bindEanStub(
        eanMap: ['optout-1' => 'N/A'],
        eanLookupGtinMap: null,  // throw on call
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
        '--no-icecat-fallback' => true,
    ]);

    expect(Product::where('sku', 'OPTOUT-1')->value('ean'))->toBeNull();

    /** @var object{callCount:int} $eanSearch */
    $eanSearch = app(EanSearchClient::class);
    expect($eanSearch->callCount)->toBe(0);

    /** @var object{callCount:int} $icecat */
    $icecat = app(IcecatClient::class);
    expect($icecat->callCount)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('skipped_invalid_ean');
    expect($output)->not->toContain('recovered_from_ean_lookup');
    expect($output)->toContain('EAN lookup fallback DISABLED');
});

it('Case F: budget cap hit — first N succeed, last is ean_lookup_budget_exhausted', function (): void {
    // ean_search costs 3 hundredths-of-pence/query. With
    // --max-icecat-spend-pence=1 the cap is 100 hundredths; 33 queries fit
    // (33 × 3 = 99 ≤ 100; 34th would push to 102 > 100). Provision 40 products
    // → expect ≥ 30 writes and ≥ 1 budget-exhausted (tolerance for boundary).
    $skus = [];
    for ($i = 1; $i <= 40; $i++) {
        $sku = 'BUD' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $skus[] = $sku;
        Product::factory()->create([
            'sku' => $sku,
            'status' => 'publish',
            'ean' => null,
            'brand_id' => 42,
        ]);
    }

    $eanMap = [];
    $eanLookupMap = [];
    foreach ($skus as $sku) {
        $eanMap[strtolower($sku)] = 'N/A';
        $eanLookupMap[$sku] = '5033588057222';
    }

    bindBrandTermsForEanLookup([['id' => 42, 'name' => 'Sony']]);
    bindEanStub(
        eanMap: $eanMap,
        eanLookupGtinMap: $eanLookupMap,
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
        '--max-icecat-spend-pence' => 1,
    ]);

    $written = Product::whereIn('sku', $skus)
        ->whereNotNull('ean')
        ->count();
    expect($written)->toBeGreaterThanOrEqual(30);
    expect($written)->toBeLessThanOrEqual(35);

    $exhausted = 40 - $written;
    expect($exhausted)->toBeGreaterThanOrEqual(1);

    $output = Artisan::output();
    expect($output)->toContain('ean_lookup_budget_exhausted');
});

it('Case G: EAN_FALLBACK_PROVIDER=icecat routes to IcecatClient instead', function (): void {
    config(['integrations.ean_fallback_provider' => 'icecat']);

    Product::factory()->create([
        'sku' => 'ROUTE-1',
        'status' => 'publish',
        'ean' => null,
        'brand_id' => 42,
    ]);

    bindBrandTermsForEanLookup([['id' => 42, 'name' => 'Sony']]);
    // Icecat fake returns a match; EanSearch fake throws on any call.
    bindEanStub(
        eanMap: ['route-1' => 'N/A'],
        eanLookupGtinMap: null,                       // EanSearch must NOT be called
        icecatGtinMap: ['ROUTE-1' => '4548736142680'], // Icecat IS the active provider
    );

    Artisan::call('products:backfill-merchant-feed', [
        '--field' => 'ean',
        '--no-confirm' => true,
    ]);

    expect(Product::where('sku', 'ROUTE-1')->value('ean'))->toBe('4548736142680');

    /** @var object{callCount:int} $eanSearch */
    $eanSearch = app(EanSearchClient::class);
    expect($eanSearch->callCount)->toBe(0);

    /** @var object{callCount:int} $icecat */
    $icecat = app(IcecatClient::class);
    expect($icecat->callCount)->toBeGreaterThanOrEqual(1);

    $output = Artisan::output();
    expect($output)->toContain('EAN lookup fallback ENABLED via icecat');
});

// ── helpers ──

/**
 * Bind an anonymous subclass that overrides `lookupSupplierEans` to return
 * the provided stub map, AND bind both an EanSearchClient fake (the default
 * provider) + an IcecatClient fake at the container.
 *
 * @param  array<string, string>  $eanMap  sku_key => raw EAN string
 * @param  array<string, string>|null  $eanLookupGtinMap  sku => EanSearch-returned GTIN string.
 *                                                       null = "must not be called" (throws on call).
 *                                                       [] = "called, returns null for any SKU".
 * @param  array<string, string>|null  $icecatGtinMap  sku => Icecat-returned GTIN string.
 *                                                    Defaults to null = throw-on-call sentinel so
 *                                                    accidental Icecat use under the default provider
 *                                                    fails loudly. Case G passes a populated map to
 *                                                    exercise the EAN_FALLBACK_PROVIDER=icecat path.
 */
function bindEanStub(array $eanMap, ?array $eanLookupGtinMap = null, ?array $icecatGtinMap = null): void
{
    // EanSearch fake — primary provider under default config.
    $eanSearchFake = new class(
        app(IntegrationCredentialResolver::class),
        app(IntegrationLogger::class),
        $eanLookupGtinMap,
    ) extends EanSearchClient
    {
        public int $callCount = 0;

        public function __construct(
            IntegrationCredentialResolver $resolver,
            IntegrationLogger $logger,
            /** @var array<string, string>|null */
            private readonly ?array $eanLookupGtinMap,
        ) {
            parent::__construct($resolver, $logger);
        }

        public function lookupGtinByMpn(?string $brand, ?string $mpn): ?string
        {
            $this->callCount++;
            if ($this->eanLookupGtinMap === null) {
                throw new \RuntimeException('EanSearch called but test did not opt in');
            }

            return $this->eanLookupGtinMap[$mpn ?? ''] ?? null;
        }
    };
    app()->instance(EanSearchClient::class, $eanSearchFake);

    // Icecat fake — throw-on-call sentinel by default so accidental Icecat
    // use under the default 'ean_search' provider fails loudly. Case G
    // (EAN_FALLBACK_PROVIDER=icecat) passes a populated map to exercise
    // the alternative provider path.
    $icecatFake = new class(
        app(IntegrationCredentialResolver::class),
        app(IntegrationLogger::class),
        $icecatGtinMap,
    ) extends IcecatClient
    {
        public int $callCount = 0;

        public function __construct(
            IntegrationCredentialResolver $resolver,
            IntegrationLogger $logger,
            /** @var array<string, string>|null */
            private readonly ?array $icecatGtinMap,
        ) {
            parent::__construct($resolver, $logger);
        }

        public function lookupGtinByMpn(?string $brand, ?string $mpn): ?string
        {
            $this->callCount++;
            if ($this->icecatGtinMap === null) {
                throw new \RuntimeException('Icecat called but test did not opt in');
            }

            return $this->icecatGtinMap[$mpn ?? ''] ?? null;
        }
    };
    app()->instance(IcecatClient::class, $icecatFake);

    $stub = new class(
        app(IntegrationCredentialResolver::class),
        app(TaxonomyResolver::class),
        app(IcecatClient::class),
        app(EanSearchClient::class),
        $eanMap,
    ) extends BackfillMerchantFeedCommand
    {
        public function __construct(
            IntegrationCredentialResolver $resolver,
            TaxonomyResolver $taxonomy,
            IcecatClient $icecat,
            EanSearchClient $eanSearch,
            /** @var array<string, string> */
            private array $eanMap,
        ) {
            parent::__construct($resolver, $taxonomy, $icecat, $eanSearch);
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

/**
 * Bind an anonymous subclass + TaxonomyResolver fake for brand-path testing.
 *
 * Also binds throw-on-call IcecatClient + EanSearchClient fakes so any
 * accidental call from a brand-path test fails loudly.
 *
 * @param  array<string, string>  $mfrMap  sku_key => raw manufacturer string
 * @param  array<string, ?int>  $resolveBrandMap  manufacturer => brand_id|null (below-threshold)
 * @param  array<int, array{id:int, name:string}>  $allBrands  for sample display
 */
function bindBrandStub(array $mfrMap, array $resolveBrandMap, array $allBrands): void
{
    // Swap a TaxonomyResolver fake into the container.
    $taxonomyFake = new class($resolveBrandMap, $allBrands) extends TaxonomyResolver
    {
        public function __construct(
            /** @var array<string, ?int> */
            private array $resolveBrandMap,
            /** @var array<int, array{id:int, name:string}> */
            private array $brandsList,
        ) {
            // Skip parent constructor — no WooClient needed for the stub.
        }

        public function resolveBrand(?string $brandName): ?int
        {
            if ($brandName === null) {
                return null;
            }

            return $this->resolveBrandMap[$brandName] ?? null;
        }

        public function allBrands(): array
        {
            return $this->brandsList;
        }
    };
    app()->instance(TaxonomyResolver::class, $taxonomyFake);

    // Brand-path tests don't exercise either EAN-lookup provider; bind
    // throw-on-call fakes for both so a regression fails loudly.
    $icecatFake = new class(
        app(IntegrationCredentialResolver::class),
        app(IntegrationLogger::class),
    ) extends IcecatClient
    {
        public int $callCount = 0;

        public function lookupGtinByMpn(?string $brand, ?string $mpn): ?string
        {
            $this->callCount++;
            throw new \RuntimeException('Icecat called from brand-path test — should not happen');
        }
    };
    app()->instance(IcecatClient::class, $icecatFake);

    $eanSearchFake = new class(
        app(IntegrationCredentialResolver::class),
        app(IntegrationLogger::class),
    ) extends EanSearchClient
    {
        public int $callCount = 0;

        public function lookupGtinByMpn(?string $brand, ?string $mpn): ?string
        {
            $this->callCount++;
            throw new \RuntimeException('EanSearch called from brand-path test — should not happen');
        }
    };
    app()->instance(EanSearchClient::class, $eanSearchFake);

    $stub = new class(
        app(IntegrationCredentialResolver::class),
        $taxonomyFake,
        $icecatFake,
        $eanSearchFake,
        $mfrMap,
    ) extends BackfillMerchantFeedCommand
    {
        public function __construct(
            IntegrationCredentialResolver $resolver,
            TaxonomyResolver $taxonomy,
            IcecatClient $icecat,
            EanSearchClient $eanSearch,
            /** @var array<string, string> */
            private array $mfrMap,
        ) {
            parent::__construct($resolver, $taxonomy, $icecat, $eanSearch);
        }

        protected function lookupSupplierManufacturers(array $candidateSkus): array
        {
            $out = [];
            foreach ($candidateSkus as $sku) {
                if (array_key_exists($sku, $this->mfrMap)) {
                    $out[$sku] = $this->mfrMap[$sku];
                }
            }

            return $out;
        }
    };

    app()->instance(BackfillMerchantFeedCommand::class, $stub);
}

/**
 * Bind a TaxonomyResolver fake exposing only `allBrands()` for the EAN-lookup
 * fallback path (which reads brand_id → brand-name via the taxonomy). Used by
 * the EAN-path cases A-G that don't otherwise touch brand resolution.
 *
 * @param  array<int, array{id:int, name:string}>  $allBrands
 */
function bindBrandTermsForEanLookup(array $allBrands): void
{
    $taxonomyFake = new class($allBrands) extends TaxonomyResolver
    {
        public function __construct(
            /** @var array<int, array{id:int, name:string}> */
            private array $brandsList,
        ) {
            // Skip parent constructor — no WooClient needed for the stub.
        }

        public function allBrands(): array
        {
            return $this->brandsList;
        }
    };
    app()->instance(TaxonomyResolver::class, $taxonomyFake);
}
