<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260707-w2w — ProductGapReport Pest feature test
|--------------------------------------------------------------------------
|
| ProductGapReport is the single source of truth for the Woo Maintenance
| Overview (and the Pass-2 drill-down). Scope = products LIVE on Woo:
|   status = 'publish' AND woo_product_id IS NOT NULL   (liveBase()).
|
| The five maintainable gaps:
|   missing_images       — gallery_image_urls NULL or empty (260708-akz:
|                          plain string compare NULL/'[]'/'' — Laravel's
|                          array cast stores [] as the literal '[]', so this
|                          matches the old JSON_LENGTH=0 verdict with no JSON
|                          function; the counts() aggregate + apply() share
|                          the same EMPTY_GALLERY_SQL predicate)
|   missing_ean          — ean NULL or TRIM(ean) = ''
|   missing_stock_status — stock_status NULL or TRIM(stock_status) = ''
|   missing_brand        — brand_id NULL
|   missing_category     — category_id NULL
|
| Seed matrix — 6 LIVE products each with EXACTLY ONE gap (P6 = none),
| plus two out-of-scope products that must NEVER be counted:
|   P1 empty gallery         — missing_images
|   P2 null ean              — missing_ean
|   P3 '' stock_status       — missing_stock_status
|   P4 null brand_id         — missing_brand
|   P5 null category_id      — missing_category
|   P6 fully complete        — no gaps
|   DRAFT (status!=publish)  — all gaps, EXCLUDED by liveBase()
|   NOT-ON-WOO (woo id NULL) — all gaps, EXCLUDED by liveBase()
|
| counts() caches 'woo_maintenance.gap_counts' for 60s — Cache::flush()
| in beforeEach() so the cache never bleeds across cases.
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
 * A LIVE-on-Woo product (status=publish + woo_product_id) that is fully
 * complete on every maintainable field, then broken on whatever the
 * caller overrides — so each seeded product carries exactly one gap.
 */
function w2wLiveProduct(string $sku, array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'sku' => $sku,
        'status' => 'publish',
        'ean' => 'EAN-'.$sku,
        'stock_status' => 'instock',
        'brand_id' => 10,
        'category_id' => 20,
        'gallery_image_urls' => ['https://example.test/a.webp'],
    ], $overrides));
}

/**
 * @return array<string, Product>
 */
function seedProductGapMatrix(): array
{
    return [
        'P1' => w2wLiveProduct('W2W-P1-NO-IMAGES', ['gallery_image_urls' => []]),
        'P2' => w2wLiveProduct('W2W-P2-NO-EAN', ['ean' => null]),
        'P3' => w2wLiveProduct('W2W-P3-NO-STOCK', ['stock_status' => '']),
        'P4' => w2wLiveProduct('W2W-P4-NO-BRAND', ['brand_id' => null]),
        'P5' => w2wLiveProduct('W2W-P5-NO-CATEGORY', ['category_id' => null]),
        'P6' => w2wLiveProduct('W2W-P6-COMPLETE'),
        // Out of scope — a DRAFT with every gap. liveBase() excludes it.
        'DRAFT' => w2wLiveProduct('W2W-DRAFT-ALL-GAPS', [
            'status' => 'draft',
            'ean' => null,
            'stock_status' => '',
            'brand_id' => null,
            'category_id' => null,
            'gallery_image_urls' => [],
        ]),
        // Out of scope — never pushed to Woo, every gap. liveBase() excludes it.
        'NOWOO' => w2wLiveProduct('W2W-NOWOO-ALL-GAPS', [
            'woo_product_id' => null,
            'ean' => null,
            'stock_status' => '',
            'brand_id' => null,
            'category_id' => null,
            'gallery_image_urls' => [],
        ]),
    ];
}

it('counts total live-on-Woo products, excluding drafts and not-on-Woo', function (): void {
    seedProductGapMatrix();

    $counts = app(ProductGapReport::class)->counts();

    // P1..P6 are live; DRAFT (status!=publish) and NOWOO (woo id NULL) excluded.
    expect($counts['total'])->toBe(6);
});

it('reports exactly one product per gap over the live base', function (): void {
    seedProductGapMatrix();

    $gaps = app(ProductGapReport::class)->counts()['gaps'];

    expect($gaps['missing_images'])->toBe(1);
    expect($gaps['missing_ean'])->toBe(1);
    expect($gaps['missing_stock_status'])->toBe(1);
    expect($gaps['missing_brand'])->toBe(1);
    expect($gaps['missing_category'])->toBe(1);
});

it('apply(liveBase(), missing_images) narrows to the empty-gallery live product only', function (): void {
    $matrix = seedProductGapMatrix();

    $report = app(ProductGapReport::class);
    $ids = $report->apply($report->liveBase(), 'missing_images')->pluck('id')->all();

    // Only P1 (empty gallery, live). DRAFT/NOWOO also have empty galleries
    // but are excluded by liveBase(), proving scope wins over gap predicate.
    expect($ids)->toBe([$matrix['P1']->id]);
});

it('does NOT flag a live product that has a non-empty gallery as missing_images', function (): void {
    // 260708-akz guard: the EMPTY_GALLERY_SQL predicate matches ONLY the
    // literal '[]'/''/NULL — a populated gallery (['http://x/img.jpg']) must
    // NOT be counted, exactly as the old JSON_LENGTH=0 check did.
    Cache::flush();
    $populated = w2wLiveProduct('AKZ-HAS-IMAGES', [
        'gallery_image_urls' => ['http://x/img.jpg'],
    ]);

    $report = app(ProductGapReport::class);

    // Not surfaced by the drill-down predicate.
    $ids = $report->apply($report->liveBase(), 'missing_images')->pluck('id')->all();
    expect($ids)->not->toContain($populated->id);

    // And not counted by the single-aggregate counts().
    Cache::flush();
    expect($report->counts()['gaps']['missing_images'])->toBe(0);
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
