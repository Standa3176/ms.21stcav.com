<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260708-cey — CatalogueGapsPage Pest feature test (Pass 2 rewire)
|--------------------------------------------------------------------------
|
| The Catalogue Gaps drill-down lists the live-on-Woo products for a chosen
| gap, REUSING ProductGapReport::liveBase() + apply() so the list matches the
| Overview counts (no drift). Pass 2 rewires it onto the RECONCILED woo_*
| columns: the Gap filter is the 3 reconciled gaps and the columns surface
| woo_image_count / woo_gtin / woo_category_count / woo_reconciled_at.
|
| Proves:
|   1. filterTable('gap','missing_images') → sees R1 (woo_image_count=0) ONLY,
|      not R2 (null gtin) / R3 (complete) — i.e. the filter reuses apply().
|   2. filterTable('gap','missing_ean') → sees R2 ONLY.
|   3. Source-images row action on R1 → Artisan 'products:source-images'
|      called with ['--skus' => R1.sku] (Artisan::shouldReceive spy —
|      the REAL command never runs).
|   4. Resync row action on R1 → 'products:resync-to-woo' with --skus.
|   5. WooMaintenanceGapsWidget: every gap Stat now carries a ->url()
|      containing 'catalogue-gaps' + the gap key (Overview deep-link).
|   6. Admin-only canAccess (mirrors AutoCreateHealthPage / Overview gate).
|
| Reconciled live seed matrix (status=publish + woo_product_id + reconciled):
|   R1 woo_image_count=0   — missing_images (also empty local gallery so the
|                            Source images fix action is visible)
|   R2 woo_gtin=null       — missing_ean
|   R3 fully populated     — no gaps
*/

use App\Domain\Products\Jobs\RunCatalogueGapFixJob;
use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\ProductGapReport;
use App\Filament\Pages\CatalogueGapsPage;
use App\Filament\Widgets\WooMaintenanceGapsWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

function catalogueGapsUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * A LIVE-on-Woo product, RECONCILED and fully populated on every woo_* field,
 * then broken on whatever the caller overrides — so each seeded product
 * carries exactly one gap (mirrors the Pass-1 rewire seed shape).
 */
function ceyGapsProduct(string $sku, array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => $sku,
        'status' => 'publish',
        'woo_product_id' => random_int(1000, 99999),
        'woo_image_count' => 3,
        'woo_gtin' => 'GTIN-'.$sku,
        'woo_category_count' => 2,
        'woo_brand_count' => 2,
        'woo_stock_status' => 'instock',
        'woo_reconciled_at' => now(),
    ], $overrides));
}

/**
 * @return array{R1: Product, R2: Product, R3: Product, R4: Product}
 */
function seedCatalogueGapsMatrix(): array
{
    return [
        // Empty local gallery too, so the Source images fix action is visible.
        'R1' => ceyGapsProduct('CEY-R1-NO-IMAGES', [
            'woo_image_count' => 0,
            'gallery_image_urls' => [],
            'name' => 'AAA R1 no images',
        ]),
        'R2' => ceyGapsProduct('CEY-R2-NO-EAN', ['woo_gtin' => null, 'name' => 'BBB R2 no ean']),
        'R3' => ceyGapsProduct('CEY-R3-COMPLETE', ['name' => 'CCC R3 complete']),
        // Quick task 260708-fyh — Pass B: reconciled, zero product_brand terms.
        'R4' => ceyGapsProduct('CEY-R4-NO-BRAND', ['woo_brand_count' => 0, 'name' => 'DDD R4 no brand']),
    ];
}

it('gap=missing_images narrows to the zero-image reconciled product only (reuses ProductGapReport::apply)', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_images')
        ->assertCanSeeTableRecords([$m['R1']])
        ->assertCanNotSeeTableRecords([$m['R2'], $m['R3']]);
});

it('gap=missing_ean narrows to the null-gtin reconciled product only', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_ean')
        ->assertCanSeeTableRecords([$m['R2']])
        ->assertCanNotSeeTableRecords([$m['R1'], $m['R3']]);
});

it('gap=missing_brand narrows to the zero-brand reconciled product only', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_brand')
        ->assertCanSeeTableRecords([$m['R4']])
        ->assertCanNotSeeTableRecords([$m['R1'], $m['R2'], $m['R3']]);
});

it('source-images row action dispatches products:source-images with the row SKU', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Artisan::shouldReceive('call')
        ->once()
        ->with('products:source-images', ['--skus' => 'CEY-R1-NO-IMAGES'])
        ->andReturn(0);

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_images')
        ->callTableAction('source_images', $m['R1'])
        ->assertHasNoTableActionErrors();
});

it('resync row action dispatches products:resync-to-woo with the row SKU', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Artisan::shouldReceive('call')
        ->once()
        ->with('products:resync-to-woo', ['--skus' => 'CEY-R1-NO-IMAGES'])
        ->andReturn(0);

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_images')
        ->callTableAction('resync', $m['R1'])
        ->assertHasNoTableActionErrors();
});

/**
 * Four LIVE reconciled products that ALL carry the missing_images gap — so the
 * page's default 'missing_images' filter shows every one of them and a bulk
 * selection of all four survives Filament's re-resolution against the query.
 *
 * @return array<int, Product>
 */
function seedFourMissingImageProducts(): array
{
    return collect(range(1, 4))
        ->map(fn (int $n): Product => ceyGapsProduct("GAB-BULK-{$n}", [
            'woo_image_count' => 0,
            'gallery_image_urls' => [],
            'name' => "GAB bulk {$n}",
        ]))
        ->all();
}

/**
 * Collect the SKU list carried by every RunCatalogueGapFixJob that was pushed,
 * asserting each job is for the expected command + on the sync-bulk queue.
 *
 * @return array<int, string> the flattened union of every job's ->skus
 */
function pushedGapFixSkus(string $expectedCommand): array
{
    $union = [];

    Queue::assertPushed(RunCatalogueGapFixJob::class, function (RunCatalogueGapFixJob $job) use ($expectedCommand, &$union): bool {
        expect($job->command)->toBe($expectedCommand);
        expect($job->queue)->toBe('sync-bulk');

        foreach ($job->skus as $sku) {
            $union[] = $sku;
        }

        return true;
    });

    return $union;
}

it('bulk fix action queues the WHOLE selection as chunked jobs on sync-bulk (none lost or duplicated)', function (): void {
    // Quick task 260708-jou — one click no longer stops at 25: it queues the
    // entire ticked selection to sync-bulk in chunks of maintenance_fix_batch_limit.
    config()->set('services.woo.maintenance_fix_batch_limit', 2);
    config()->set('services.woo.maintenance_fix_max_per_run', 1000);
    Queue::fake();
    $this->actingAs(catalogueGapsUser('admin'));
    $products = seedFourMissingImageProducts(); // 4 live products, all missing_images.

    Livewire::test(CatalogueGapsPage::class)
        ->callTableBulkAction('resync_bulk', $products)
        ->assertHasNoTableBulkActionErrors();

    // 4 selected / chunk 2 → exactly 2 jobs.
    Queue::assertPushed(RunCatalogueGapFixJob::class, 2);

    $expected = collect($products)->map(fn (Product $p): string => (string) $p->sku)->sort()->values()->all();
    $union = collect(pushedGapFixSkus('products:resync-to-woo'))->sort()->values()->all();

    // Union across all jobs equals the selected set — none lost, none duplicated.
    expect($union)->toBe($expected);
    expect($union)->toHaveCount(4);
});

it('bulk fix action queues a single batch when the selection fits in one chunk', function (): void {
    config()->set('services.woo.maintenance_fix_batch_limit', 10);
    config()->set('services.woo.maintenance_fix_max_per_run', 1000);
    Queue::fake();
    $this->actingAs(catalogueGapsUser('admin'));
    $products = seedFourMissingImageProducts(); // 4 live products, chunk 10 → 1 batch.

    Livewire::test(CatalogueGapsPage::class)
        ->callTableBulkAction('resync_bulk', $products)
        ->assertHasNoTableBulkActionErrors();

    Queue::assertPushed(RunCatalogueGapFixJob::class, 1);

    $union = pushedGapFixSkus('products:resync-to-woo');
    expect($union)->toHaveCount(4);
});

it('bulk fix action enforces the max-per-run ceiling (only the first N are queued)', function (): void {
    // Quick task 260708-jou — a single click queues at most maintenance_fix_max_per_run
    // products; the rest wait for a re-run. chunk 1 + ceiling 2 + 4 selected → 2 jobs.
    config()->set('services.woo.maintenance_fix_batch_limit', 1);
    config()->set('services.woo.maintenance_fix_max_per_run', 2);
    Queue::fake();
    $this->actingAs(catalogueGapsUser('admin'));
    $products = seedFourMissingImageProducts(); // 4 selected, ceiling 2.

    Livewire::test(CatalogueGapsPage::class)
        ->callTableBulkAction('resync_bulk', $products)
        ->assertHasNoTableBulkActionErrors();

    // min(4, 2) / chunk 1 → exactly 2 jobs (the ceiling capped the run).
    Queue::assertPushed(RunCatalogueGapFixJob::class, 2);

    $union = pushedGapFixSkus('products:resync-to-woo');
    expect($union)->toHaveCount(2);
    // Every queued SKU is one of the selected products (a genuine subset).
    $selected = collect($products)->map(fn (Product $p): string => (string) $p->sku)->all();
    expect(collect($union)->every(fn (string $sku): bool => in_array($sku, $selected, true)))->toBeTrue();
});

it('bulk fix action carries the correct command for each fix type', function (string $bulkAction, string $command): void {
    config()->set('services.woo.maintenance_fix_batch_limit', 25);
    config()->set('services.woo.maintenance_fix_max_per_run', 1000);
    Queue::fake();
    $this->actingAs(catalogueGapsUser('admin'));
    $products = seedFourMissingImageProducts();

    Livewire::test(CatalogueGapsPage::class)
        ->callTableBulkAction($bulkAction, $products)
        ->assertHasNoTableBulkActionErrors();

    Queue::assertPushed(RunCatalogueGapFixJob::class, 1);
    pushedGapFixSkus($command); // asserts ->command + sync-bulk queue for the pushed job
})->with([
    'source images' => ['source_images_bulk', 'products:source-images'],
    'backfill EAN' => ['backfill_ean_bulk', 'products:backfill-merchant-feed'],
    'resync' => ['resync_bulk', 'products:resync-to-woo'],
]);

it('WooMaintenanceGapsWidget gap stats deep-link into the Catalogue Gaps page per gap', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    seedCatalogueGapsMatrix();

    $widget = new WooMaintenanceGapsWidget;
    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);
    /** @var array<int, Stat> $stats */
    $stats = $method->invoke($widget);

    // Collect every non-null stat url.
    $urls = array_filter(array_map(fn ($stat) => $stat->getUrl(), $stats));

    // Every gap key must appear in some stat url pointing at the drill-down.
    foreach (array_keys(ProductGapReport::GAPS) as $gapKey) {
        $match = collect($urls)->first(
            fn (string $url): bool => str_contains($url, 'catalogue-gaps') && str_contains($url, $gapKey)
        );

        expect($match)->not->toBeNull("Expected a stat url deep-linking to catalogue-gaps for gap '{$gapKey}'");
    }
});

it('admin can access the page; sales gets denied', function (): void {
    expect(CatalogueGapsPage::canAccess())->toBeFalse();

    $this->actingAs(catalogueGapsUser('admin'));
    expect(CatalogueGapsPage::canAccess())->toBeTrue();

    $this->actingAs(catalogueGapsUser('sales'));
    expect(CatalogueGapsPage::canAccess())->toBeFalse();
});
