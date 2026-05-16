<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 04 Task 1 — CRITICAL title→name column mapping guard
|--------------------------------------------------------------------------
|
| The SEOAGT-01 contract uses 'title' as the user-facing field name; the
| Product Eloquent model has NO 'title' column — the canonical column is
| 'name'. The applier MUST translate 'title' → 'name' at write time AND
| 'title' → 'pin_title' on the override.
|
| This regression test fences off P12 critical gotcha #1 — if a future
| refactor drops the mapping table, this test fails first.
|
| Assertions:
|   1. After applier runs with field='title' patch:
|      a. Product::find($id)->name === 'New name'
|      b. ProductOverride::pin_title === true
|      c. Audit row records data.field='title' (user-facing semantics
|         preserved — admin sees 'title' even though column write is 'name')
|   2. Product model fillable does NOT list 'title' (column never existed).
|   3. The SeoContentPatchApplier source contains the literal
|      'title' => 'name' mapping (P12 gotcha #1 fence).
*/

use App\Domain\Agents\Appliers\SeoContentPatchApplier;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use App\Domain\Suggestions\Models\Suggestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('field=title writes to Product.name (NOT title) and pin_title=true and audits field=title', function () {
    $product = Product::factory()->create([
        'sku' => 'TITLE-NAME-' . Str::random(4),
        'name' => 'Old product name',
    ]);

    $suggestion = Suggestion::create([
        'kind' => 'seo_content_patch',
        'status' => Suggestion::STATUS_PENDING,
        'correlation_id' => (string) Str::uuid(),
        'payload' => [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'patches' => [[
                'field' => 'title',
                'before' => 'Old product name',
                'after' => 'New product name',
                'reasoning' => 'cleaner title',
                'applied_at' => now()->toIso8601String(),
            ]],
            'agent_run_id' => (string) Str::ulid(),
        ],
        'evidence' => [],
        'proposed_at' => now(),
    ]);

    app(SeoContentPatchApplier::class)->apply($suggestion);

    // (a) Product.name updated (NOT title — there is no title column)
    $product->refresh();
    expect($product->name)->toBe('New product name');

    // (b) ProductOverride.pin_title=true
    $override = ProductOverride::where('product_id', $product->id)->first();
    expect($override)->not->toBeNull();
    expect((bool) $override->pin_title)->toBeTrue();

    // (c) Audit row records data.field='title' (NOT 'name' — preserve user-facing semantics)
    $audit = DB::table('activity_log')
        ->where('description', 'seo.content_patch_applied')
        ->orderByDesc('id')
        ->first();
    expect($audit)->not->toBeNull();
    $props = (array) json_decode((string) $audit->properties, true);
    expect((string) data_get($props, 'field'))->toBe('title');
});

it('Product model fillable does NOT include the literal "title" column (regression guard)', function () {
    $fillable = (new Product)->getFillable();
    expect(in_array('title', $fillable, true))->toBeFalse(
        "Product model has no 'title' column; SEO 'title' field maps to Product.name. "
        . 'If you added a real title column, update SeoContentPatchApplier::FIELD_TO_PRODUCT_COLUMN.'
    );
});

it('SeoContentPatchApplier source contains the literal title=>name mapping (P12 fence)', function () {
    $path = base_path('app/Domain/Agents/Appliers/SeoContentPatchApplier.php');
    expect(is_file($path))->toBeTrue();
    $src = (string) file_get_contents($path);
    expect($src)->toContain("'title' => 'name'");
    expect($src)->toContain('FIELD_TO_PRODUCT_COLUMN');
    expect($src)->toContain('FIELD_TO_PIN_COLUMN');
});
