<?php

declare(strict_types=1);

use App\Domain\Competitor\Models\CompetitorPrice;
use App\Domain\Pricing\Services\AdCandidateScanner;
use App\Domain\ProductAutoCreate\Services\TaxonomyResolver;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Models\SupplierOfferSnapshot;
use App\Filament\Pages\AdCandidatesPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-pys — AdCandidatesPage Pest feature test
|--------------------------------------------------------------------------
|
| Validates:
|   1. RBAC — admin + pricing_manager get 200; sales + read_only get 403
|   2. Default filter renders the expected SKU set
|   3. filterBrandIds=[X] narrows to brand X only (Livewire wire:model.live)
|   4. Bulk Copy SKU CSV action streams a downloadable CSV
|   5. Bulk Send to Google Ads dispatches a Filament warning notification
|
| TaxonomyResolver is stubbed (no Woo REST hit) — Pricing→ProductAutoCreate
| arrow is already in the deptrac allow-list (260606-rld).
*/

function adCandidatesUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function bindAdCandidatePageBrandTerms(array $brands): void
{
    $taxonomyFake = new class($brands) extends TaxonomyResolver
    {
        public function __construct(/** @var array<int, array{id:int, name:string}> */ private array $brandsList)
        {
            // Skip parent constructor — no WooClient hit in tests.
        }

        public function allBrands(): array
        {
            return $this->brandsList;
        }
    };
    app()->instance(TaxonomyResolver::class, $taxonomyFake);
}

/**
 * Seed a golden-target Product with competitor + supplier rows so the
 * scanner's default thresholds (£199 margin / stock / beat) include it.
 */
function seedAdCandidatePageProduct(string $sku, ?int $brandId = null, int $stock = 5): Product
{
    $product = Product::factory()->create([
        'sku' => $sku,
        'name' => "Product {$sku}",
        'type' => 'simple',
        'status' => 'publish',
        'buy_price' => 100, // £100
        'sell_price' => 350, // £350 → margin £250
        'brand_id' => $brandId,
    ]);

    // Competitor at £400 gross → we undercut by £50.
    CompetitorPrice::factory()
        ->forSku($sku)
        ->create([
            'price_pennies_ex_vat' => (int) round(40000 / 1.2),
            'price_pennies_gross' => 40000,
        ]);

    SupplierOfferSnapshot::create([
        'sku' => strtolower($sku),
        'product_id' => $product->id,
        'supplier_id' => 'SUP-T',
        'supplier_name' => 'TestSupplier',
        'price' => 100,
        'stock' => $stock,
        'rrp' => 350,
        'recorded_at' => today(),
    ]);

    return $product;
}

beforeEach(function (): void {
    bindAdCandidatePageBrandTerms([
        ['id' => 10, 'name' => 'BrandX'],
        ['id' => 20, 'name' => 'BrandY'],
    ]);
});

it('admin + pricing_manager can access; sales + read_only get 403', function (): void {
    $this->actingAs(adCandidatesUser('admin'))
        ->get('/admin/ad-candidates')
        ->assertOk();

    $this->actingAs(adCandidatesUser('pricing_manager'))
        ->get('/admin/ad-candidates')
        ->assertOk();

    $this->actingAs(adCandidatesUser('sales'))
        ->get('/admin/ad-candidates')
        ->assertForbidden();

    $this->actingAs(adCandidatesUser('read_only'))
        ->get('/admin/ad-candidates')
        ->assertForbidden();
});

it('renders the default-filter SKU set in the Livewire table', function (): void {
    seedAdCandidatePageProduct('LIVE-1', brandId: 10);
    seedAdCandidatePageProduct('LIVE-2', brandId: 20);

    $this->actingAs(adCandidatesUser('admin'));

    Livewire::test(AdCandidatesPage::class)
        ->assertCanSeeTableRecords(Product::whereIn('sku', ['LIVE-1', 'LIVE-2'])->get())
        ->assertSet('filterMinMarginPounds', 199)
        ->assertSet('filterStockRequired', true)
        ->assertSet('filterBeatRequired', true);
});

it('setting filterBrandIds narrows the table to that brand only', function (): void {
    seedAdCandidatePageProduct('B-X', brandId: 10);
    seedAdCandidatePageProduct('B-Y', brandId: 20);

    $this->actingAs(adCandidatesUser('admin'));

    Livewire::test(AdCandidatesPage::class)
        ->set('filterBrandIds', [10])
        ->assertCanSeeTableRecords(Product::where('sku', 'B-X')->get())
        ->assertCanNotSeeTableRecords(Product::where('sku', 'B-Y')->get());
});

it('the Copy SKU CSV bulk action returns a streamed CSV response', function (): void {
    $p1 = seedAdCandidatePageProduct('CSV-1', brandId: 10);
    $p2 = seedAdCandidatePageProduct('CSV-2', brandId: 10);

    $this->actingAs(adCandidatesUser('admin'));

    $component = Livewire::test(AdCandidatesPage::class);

    // Capture the streamed CSV body produced by the bulk action's
    // streamDownload closure.
    ob_start();
    $response = $component->instance()->getTable()->getBulkAction('copy_sku_csv')
        ->records(Product::whereIn('sku', ['CSV-1', 'CSV-2'])->get())
        ->call();
    ob_end_clean();

    expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toContain('sku')
        ->and($body)->toContain('CSV-1')
        ->and($body)->toContain('CSV-2');
});

it('the Send to Google Ads bulk action fires a Filament warning notification', function (): void {
    $p1 = seedAdCandidatePageProduct('NTF-1', brandId: 10);

    $this->actingAs(adCandidatesUser('admin'));

    Livewire::test(AdCandidatesPage::class)
        ->callTableBulkAction(
            'send_to_google_ads',
            Product::whereIn('sku', ['NTF-1'])->pluck('id')->all(),
        )
        ->assertNotified();
});

it('the scanner is the single source of truth — page reads exactly its row set', function (): void {
    // Bind a scanner stub that returns a known shape. The page must
    // render those exact SKUs (drift-prevention vs the backfill command +
    // dashboard tile).
    $product = Product::factory()->create([
        'sku' => 'SCAN-1',
        'name' => 'Scanner Source of Truth',
        'type' => 'simple',
        'status' => 'publish',
        'buy_price' => 100,
        'sell_price' => 350,
        'brand_id' => 10,
    ]);

    $fake = new class($product) extends AdCandidateScanner
    {
        public function __construct(private Product $product)
        {
            // Skip parent constructor — no TaxonomyResolver needed.
        }

        public function scan(
            array $brandIds = [],
            int $minMarginPence = 19900,
            bool $stockRequired = true,
            bool $beatRequired = true,
        ): \Illuminate\Support\Collection {
            $row = new \stdClass;
            $row->sku = (string) $this->product->sku;
            $row->name = (string) $this->product->name;
            $row->product_id = (int) $this->product->id;
            $row->woo_product_id = (int) $this->product->woo_product_id;
            $row->slug = '';
            $row->brand_id = 10;
            $row->brand_name = 'BrandX';
            $row->sell_price_pence = 35000;
            $row->buy_price_pence = 10000;
            $row->margin_pence = 25000;
            $row->lowest_comp_pence = 40000;
            $row->beat_pct_bps = -1250;
            $row->stock = 5;
            $row->best_supplier = 'TestSupplier';

            return collect([$row]);
        }
    };
    app()->instance(AdCandidateScanner::class, $fake);

    $this->actingAs(adCandidatesUser('admin'));

    Livewire::test(AdCandidatesPage::class)
        ->assertCanSeeTableRecords(Product::whereIn('sku', ['SCAN-1'])->get());
});
