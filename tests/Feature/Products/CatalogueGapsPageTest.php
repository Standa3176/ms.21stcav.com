<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260707-wa9 — CatalogueGapsPage Pest feature test (Pass 2)
|--------------------------------------------------------------------------
|
| Pass 2 of the Woo Maintenance section — the drill-down. CatalogueGapsPage
| lists the live-on-Woo products for a chosen gap, REUSING the Pass-1
| ProductGapReport::liveBase() + apply() so the list matches the Overview
| counts (no drift). A required Gap SelectFilter drives it.
|
| Proves:
|   1. filterTable('gap','missing_images') → sees P1 (empty gallery) ONLY,
|      not P2 (null ean) / P3 (complete) — i.e. the filter reuses apply().
|   2. filterTable('gap','missing_ean') → sees P2 ONLY.
|   3. Source-images row action on P1 → Artisan 'products:source-images'
|      called with ['--skus' => P1.sku] (Artisan::shouldReceive spy —
|      mirrors StockDivergencePageTest so the REAL command never runs).
|   4. Resync row action on P1 → 'products:resync-to-woo' with --skus.
|   5. WooMaintenanceGapsWidget: every gap Stat now carries a ->url()
|      containing 'catalogue-gaps' + the gap key (Overview deep-link).
|   6. Admin-only canAccess (mirrors AutoCreateHealthPage / Overview gate).
|
| Live seed matrix (status=publish + woo_product_id — liveBase() scope):
|   P1 empty gallery      — missing_images
|   P2 null ean           — missing_ean
|   P3 fully complete     — no gaps
*/

use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\ProductGapReport;
use App\Filament\Pages\CatalogueGapsPage;
use App\Filament\Widgets\WooMaintenanceGapsWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
 * A LIVE-on-Woo product complete on every maintainable field, then broken
 * on whatever the caller overrides — so each seeded product carries exactly
 * one gap (mirrors the Pass-1 seed shape).
 */
function wa9LiveProduct(string $sku, array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => $sku,
        'status' => 'publish',
        'woo_product_id' => random_int(1000, 99999),
        'ean' => 'EAN-'.$sku,
        'stock_status' => 'instock',
        'brand_id' => 10,
        'category_id' => 20,
        'gallery_image_urls' => ['https://example.test/a.webp'],
    ], $overrides));
}

/**
 * @return array{P1: Product, P2: Product, P3: Product}
 */
function seedCatalogueGapsMatrix(): array
{
    return [
        'P1' => wa9LiveProduct('WA9-P1-NO-IMAGES', ['gallery_image_urls' => [], 'name' => 'AAA P1 no images']),
        'P2' => wa9LiveProduct('WA9-P2-NO-EAN', ['ean' => null, 'name' => 'BBB P2 no ean']),
        'P3' => wa9LiveProduct('WA9-P3-COMPLETE', ['name' => 'CCC P3 complete']),
    ];
}

it('gap=missing_images narrows to the empty-gallery product only (reuses ProductGapReport::apply)', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_images')
        ->assertCanSeeTableRecords([$m['P1']])
        ->assertCanNotSeeTableRecords([$m['P2'], $m['P3']]);
});

it('gap=missing_ean narrows to the null-ean product only', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_ean')
        ->assertCanSeeTableRecords([$m['P2']])
        ->assertCanNotSeeTableRecords([$m['P1'], $m['P3']]);
});

it('source-images row action dispatches products:source-images with the row SKU', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Artisan::shouldReceive('call')
        ->once()
        ->with('products:source-images', ['--skus' => 'WA9-P1-NO-IMAGES'])
        ->andReturn(0);

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_images')
        ->callTableAction('source_images', $m['P1'])
        ->assertHasNoTableActionErrors();
});

it('resync row action dispatches products:resync-to-woo with the row SKU', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    $m = seedCatalogueGapsMatrix();

    Artisan::shouldReceive('call')
        ->once()
        ->with('products:resync-to-woo', ['--skus' => 'WA9-P1-NO-IMAGES'])
        ->andReturn(0);

    Livewire::test(CatalogueGapsPage::class)
        ->filterTable('gap', 'missing_images')
        ->callTableAction('resync', $m['P1'])
        ->assertHasNoTableActionErrors();
});

it('WooMaintenanceGapsWidget gap stats deep-link into the Catalogue Gaps page per gap', function (): void {
    $this->actingAs(catalogueGapsUser('admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    seedCatalogueGapsMatrix();

    $widget = new WooMaintenanceGapsWidget;
    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);
    /** @var array<int, \Filament\Widgets\StatsOverviewWidget\Stat> $stats */
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
