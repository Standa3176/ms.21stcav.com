<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quick task 260606-mx9 — AutoCreateHealthPage Pest feature test
|--------------------------------------------------------------------------
|
| Surfaces auto-create local-state drift the operator currently has no
| internal signal for. Predicate (Task 2 spec, adjusted for the actual
| schema — see deviation note below):
|
|   auto_create_status != 'manual'
|   AND (
|        gallery_image_urls IS NULL
|     OR json_array_length(gallery_image_urls) = 0   -- SQLite
|     OR JSON_LENGTH(gallery_image_urls) = 0          -- MySQL (production)
|     OR brand_id IS NULL
|     OR category_id IS NULL
|     OR woo_product_id IS NULL
|   )
|
| Driver-aware JSON expression mirrors PruneOrphanSuggestionsCommand
| (commit d6c8a4d). Pest in-memory SQLite drives the test.
|
| Deviation from plan PRED (Rule 1 — auto-fix latent bug):
|   PLAN.md specified `whereNotNull('auto_create_status')` as the
|   legacy-WC exclusion. But the column was added by migration
|   2026_04_22_100300 as `NOT NULL DEFAULT 'manual'` with an explicit
|   belt-and-braces backfill of every pre-existing row to 'manual'.
|   So `IS NOT NULL` is a vacuous filter — it never excludes anything.
|   Per the migration's own docblock, 'manual' IS the legacy / pre-auto-
|   create marker. We use `auto_create_status != 'manual'` instead, which
|   preserves the must-have "Legacy WC-migration products are excluded
|   even when their fields are missing" without depending on a NULL value
|   that the schema cannot hold.
|
| Three cases:
|   1. Predicate-matrix sanity — seed 6 products covering all 6 shapes
|      and assert the page's public getUnhealthyQuery() returns exactly
|      the 4 expected rows. Legacy WC-migration product (auto_create_status
|      NULL) is excluded even when its fields are missing — same scope
|      decision as RetryMissingImagesCommand.
|   2. Nav badge count — getNavigationBadge() returns '4' (string per
|      Filament 3 contract) for the seed; returns null when no unhealthy
|      products remain.
|   3. Role gate — admin can GET /admin/auto-create-health (200); sales,
|      read_only, and pricing_manager all get 403 (admin-only per brief —
|      tighter than NotificationCentrePage's per-action gates).
*/

use App\Domain\Products\Models\Product;
use App\Filament\Pages\AutoCreateHealthPage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

function autoCreateHealthUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Seed the 6-product predicate matrix:
 *   P1 HEALTHY                  — should NOT appear
 *   P2 no images                 — MUST appear
 *   P3 no brand                  — MUST appear
 *   P4 no category               — MUST appear
 *   P5 not pushed to Woo         — MUST appear
 *   P6 legacy WC ('manual')      — should NOT appear (out of scope by design)
 *
 * P6 uses 'manual' rather than NULL (the plan's wording) because the
 * `auto_create_status` column is NOT NULL with DEFAULT 'manual' per
 * migration 2026_04_22_100300 — see file-level deviation note.
 *
 * @return array<string, Product>
 */
function seedAutoCreateHealthMatrix(): array
{
    return [
        'P1' => Product::factory()->create([
            'sku' => 'MX9-P1-HEALTHY',
            'auto_create_status' => 'published',
            'gallery_image_urls' => ['https://example.test/a.webp'],
            'brand_id' => 10,
            'category_id' => 20,
            'woo_product_id' => 9991,
        ]),
        'P2' => Product::factory()->create([
            'sku' => 'MX9-P2-NO-IMAGES',
            'auto_create_status' => 'published',
            'gallery_image_urls' => [],
            'brand_id' => 10,
            'category_id' => 20,
            'woo_product_id' => 9992,
        ]),
        'P3' => Product::factory()->create([
            'sku' => 'MX9-P3-NO-BRAND',
            'auto_create_status' => 'published',
            'gallery_image_urls' => ['https://example.test/a.webp'],
            'brand_id' => null,
            'category_id' => 20,
            'woo_product_id' => 9993,
        ]),
        'P4' => Product::factory()->create([
            'sku' => 'MX9-P4-NO-CATEGORY',
            'auto_create_status' => 'published',
            'gallery_image_urls' => ['https://example.test/a.webp'],
            'brand_id' => 10,
            'category_id' => null,
            'woo_product_id' => 9994,
        ]),
        'P5' => Product::factory()->create([
            'sku' => 'MX9-P5-NOT-PUSHED',
            'auto_create_status' => 'draft',
            'gallery_image_urls' => ['https://example.test/a.webp'],
            'brand_id' => 10,
            'category_id' => 20,
            'woo_product_id' => null,
        ]),
        'P6' => Product::factory()->create([
            'sku' => 'MX9-P6-LEGACY-WC',
            'auto_create_status' => 'manual',
            'gallery_image_urls' => [],
            'brand_id' => null,
            'category_id' => null,
            'woo_product_id' => 9996,
        ]),
    ];
}

it('unhealthy predicate returns exactly the 4 expected products (P2..P5)', function (): void {
    $matrix = seedAutoCreateHealthMatrix();

    // The page exposes its query via a public method so this test can
    // assert the predicate directly without going through Livewire.
    $page = app(AutoCreateHealthPage::class);

    $skus = $page->getUnhealthyQuery()->pluck('sku')->sort()->values()->all();

    $expected = collect([$matrix['P2'], $matrix['P3'], $matrix['P4'], $matrix['P5']])
        ->pluck('sku')->sort()->values()->all();

    expect($skus)->toBe($expected);
    expect($skus)->toHaveCount(4);
    // Defensive: P1 (HEALTHY) and P6 (legacy WC) MUST NOT appear.
    expect($skus)->not->toContain($matrix['P1']->sku);
    expect($skus)->not->toContain($matrix['P6']->sku);
});

it('navigation badge count matches the live unhealthy total', function (): void {
    seedAutoCreateHealthMatrix();

    // Flush any prior cached tooltip so badge breakdown is computed fresh.
    Cache::flush();

    expect(AutoCreateHealthPage::getNavigationBadge())->toBe('4');

    // Clearing the unhealthy population hides the badge entirely.
    Product::query()->delete();
    Cache::flush();

    expect(AutoCreateHealthPage::getNavigationBadge())->toBeNull();
});

it('admin can access the page; sales, read_only, and pricing_manager get 403', function (): void {
    $this->actingAs(autoCreateHealthUser('admin'))
        ->get('/admin/auto-create-health')
        ->assertOk();

    $this->actingAs(autoCreateHealthUser('sales'))
        ->get('/admin/auto-create-health')
        ->assertForbidden();

    $this->actingAs(autoCreateHealthUser('read_only'))
        ->get('/admin/auto-create-health')
        ->assertForbidden();

    $this->actingAs(autoCreateHealthUser('pricing_manager'))
        ->get('/admin/auto-create-health')
        ->assertForbidden();
});
