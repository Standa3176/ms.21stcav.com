<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260708-cey — ProductGapReport Pest feature test (Pass 2 rewire)
|--------------------------------------------------------------------------
|
| ProductGapReport is the single source of truth for the Woo Maintenance
| Overview (WooMaintenanceGapsWidget) + the Catalogue Gaps drill-down.
|
| Pass 2 rewires the report onto the RECONCILED woo_* mirror columns
| (populated by products:reconcile-woo-maintenance) so the dashboard reports
| the TRUE whole-shop state instead of the misleading local mirror emptiness.
|
| Scope = products LIVE on Woo: status='publish' AND woo_product_id NOT NULL
| (liveBase()). Gaps are only meaningful for RECONCILED products (we know
| their real Woo state), so every gap predicate gates on woo_reconciled_at:
|   missing_images   — woo_image_count = 0
|   missing_ean      — woo_gtin IS NULL
|   missing_category — woo_category_count = 0
| (brand + stock dropped: brand isn't in the Woo /products reconciliation;
|  stock_status is always set on Woo so it's never a gap.)
|
| counts() (single cached aggregate) additionally returns coverage + freshness
| (total / reconciled / not_reconciled / last_reconciled_at) so the Overview
| can warn if reconciliation hasn't run.
|
| Seed matrix — 4 RECONCILED live products (each with exactly one gap, R4 none)
| + 1 UNRECONCILED live product that must NEVER be counted in any gap:
|   R1 woo_image_count=0     — missing_images
|   R2 woo_gtin=null         — missing_ean
|   R3 woo_category_count=0  — missing_category
|   R4 fully populated       — no gaps
|   R5 UNRECONCILED          — woo_reconciled_at NULL, all woo_* null (excluded)
|
| counts() caches 'woo_maintenance.gap_counts' 300s — Cache::flush() in
| beforeEach() so the cache never bleeds across cases.
*/

use App\Domain\Products\Models\Product;
use App\Domain\Products\Services\ProductGapReport;
use App\Filament\Pages\WooMaintenanceOverviewPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

/**
 * A LIVE-on-Woo product (status=publish + woo_product_id) that is RECONCILED
 * and fully populated on every maintainable woo_* field, then broken on
 * whatever the caller overrides — so each seeded product carries exactly one
 * gap.
 */
function ceyReconciledProduct(string $sku, array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => $sku,
        'status' => 'publish',
        'woo_image_count' => 3,
        'woo_gtin' => 'GTIN-'.$sku,
        'woo_category_count' => 2,
        'woo_stock_status' => 'instock',
        'woo_reconciled_at' => now(),
    ], $overrides));
}

/**
 * @return array<string, Product>
 */
function seedProductGapMatrix(): array
{
    return [
        'R1' => ceyReconciledProduct('CEY-R1-NO-IMAGES', ['woo_image_count' => 0]),
        'R2' => ceyReconciledProduct('CEY-R2-NO-EAN', ['woo_gtin' => null]),
        'R3' => ceyReconciledProduct('CEY-R3-NO-CATEGORY', ['woo_category_count' => 0]),
        'R4' => ceyReconciledProduct('CEY-R4-COMPLETE'),
        // UNRECONCILED — live on Woo but never reconciled, so its real Woo
        // state is unknown. Every woo_* is null; must NOT be counted in any gap.
        'R5' => ceyReconciledProduct('CEY-R5-UNRECONCILED', [
            'woo_image_count' => null,
            'woo_gtin' => null,
            'woo_category_count' => null,
            'woo_stock_status' => null,
            'woo_reconciled_at' => null,
        ]),
    ];
}

it('reports coverage: total live, reconciled, not_reconciled and last_reconciled_at', function (): void {
    seedProductGapMatrix();

    $counts = app(ProductGapReport::class)->counts();

    // R1..R5 are all live (publish + woo id). R5 is live but unreconciled.
    expect($counts['total'])->toBe(5);
    expect($counts['reconciled'])->toBe(4);
    expect($counts['not_reconciled'])->toBe(1);
    expect($counts['last_reconciled_at'])->not->toBeNull();
});

it('reports exactly one reconciled product per gap (unreconciled excluded)', function (): void {
    seedProductGapMatrix();

    $gaps = app(ProductGapReport::class)->counts()['gaps'];

    // Only the 3 reconciled gaps; brand + stock dropped from the gap set.
    expect($gaps)->toHaveKeys(['missing_images', 'missing_ean', 'missing_category']);
    expect($gaps['missing_images'])->toBe(1);
    expect($gaps['missing_ean'])->toBe(1);
    expect($gaps['missing_category'])->toBe(1);
    // R5 (unreconciled, all woo_* null) is NOT counted despite null gtin etc.
    expect($gaps)->not->toHaveKey('missing_brand');
    expect($gaps)->not->toHaveKey('missing_stock_status');
});

it('apply(liveBase(), missing_images) narrows to the reconciled zero-image product only', function (): void {
    $matrix = seedProductGapMatrix();

    $report = app(ProductGapReport::class);
    $ids = $report->apply($report->liveBase(), 'missing_images')->pluck('id')->all();

    // Only R1 (reconciled, woo_image_count=0). R5 is also zero-ish but
    // UNRECONCILED (woo_reconciled_at NULL) so the apply() gate excludes it.
    expect($ids)->toBe([$matrix['R1']->id]);
});

it('does NOT flag an unreconciled product as any gap', function (): void {
    $matrix = seedProductGapMatrix();

    $report = app(ProductGapReport::class);

    foreach (array_keys(ProductGapReport::GAPS) as $gap) {
        $ids = $report->apply($report->liveBase(), $gap)->pluck('id')->all();
        expect($ids)->not->toContain($matrix['R5']->id);
    }
});

it('renders the Maintenance Overview page for an admin', function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    seedProductGapMatrix();

    $this->actingAs($admin->fresh())
        ->get('/admin/'.WooMaintenanceOverviewPage::getSlug())
        ->assertSuccessful();
});
