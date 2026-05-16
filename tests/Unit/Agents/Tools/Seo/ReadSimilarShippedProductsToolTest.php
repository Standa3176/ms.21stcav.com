<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Seo\ReadSimilarShippedProductsTool;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 02 Task 2 — ReadSimilarShippedProductsTool unit tests
|--------------------------------------------------------------------------
|
| Validates SEOAGT-02 read_similar_shipped_products tool body:
|   - Option B eligibility query: status='publish' AND (completeness_score
|     >= 85 OR NULL) — covers Phase 2-synced manual rows AND AutoCreate-
|     published rows (RESEARCH §Tool 3)
|   - Per-category filter when products exist in that category
|   - P12-G global fallback: zero rows in category → drops category filter,
|     adds _fallback='global' hint so the agent knows the voice anchor is
|     cross-category
|   - long_description_first_500_chars: exactly first 500 chars
|   - 3-KB soft cap via TruncatingTool::capJson with _truncated:true +
|     _total_available:N hints when JSON exceeds 3072 bytes
*/

function invokeReadSimilarShipped(int $category, int $limit = 5): string
{
    return app(ReadSimilarShippedProductsTool::class)->asPrismTool()->handle($category, $limit);
}

it('returns up to N category-matching products with the documented schema', function () {
    // Seed 5 eligible products in category 12
    for ($i = 0; $i < 5; $i++) {
        Product::factory()->create([
            'sku' => "CAT12-{$i}",
            'name' => "Camera {$i}",
            'short_description' => "Short desc {$i}",
            'long_description' => "Long description body for product {$i}",
            'meta_description' => "Meta {$i}",
            'category_id' => 12,
            'status' => 'publish',
            'completeness_score' => 90,
        ]);
    }

    $payload = json_decode(invokeReadSimilarShipped(12, 5), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['category_id'])->toBe(12);
    expect($payload['limit'])->toBe(5);
    expect($payload['products'])->toHaveCount(5);
    expect($payload['products'][0])->toHaveKeys([
        'sku',
        'name',
        'short_description',
        'long_description_first_500_chars',
        'meta_description',
    ]);
    expect($payload)->not->toHaveKey('_fallback');
});

it('Option B query includes products with completeness_score NULL (legacy manual)', function () {
    // 3 publish + score>=85, 2 publish + null score (legacy), 1 draft (excluded)
    Product::factory()->create([
        'sku' => 'LEGACY-1',
        'name' => 'Legacy manual product 1',
        'long_description' => 'Long description',
        'category_id' => 12,
        'status' => 'publish',
        'completeness_score' => null,
    ]);
    Product::factory()->create([
        'sku' => 'NEW-PUB',
        'name' => 'New published product',
        'long_description' => 'Long description',
        'category_id' => 12,
        'status' => 'publish',
        'completeness_score' => 90,
    ]);
    Product::factory()->create([
        'sku' => 'LOW-SCORE',
        'name' => 'Low completeness',
        'long_description' => 'desc',
        'category_id' => 12,
        'status' => 'publish',
        'completeness_score' => 50,  // EXCLUDED (< 85 and not null)
    ]);
    Product::factory()->create([
        'sku' => 'DRAFT',
        'name' => 'Draft product',
        'long_description' => 'desc',
        'category_id' => 12,
        'status' => 'draft',
        'completeness_score' => 95,
    ]);

    $payload = json_decode(invokeReadSimilarShipped(12, 10), true, flags: JSON_THROW_ON_ERROR);

    $skus = array_column($payload['products'], 'sku');
    expect($skus)->toContain('LEGACY-1');
    expect($skus)->toContain('NEW-PUB');
    expect($skus)->not->toContain('LOW-SCORE');
    expect($skus)->not->toContain('DRAFT');
});

it('falls back to global examples with _fallback hint when category has zero eligible rows (P12-G)', function () {
    // Seed products in OTHER categories (no products in cat 99)
    for ($i = 0; $i < 5; $i++) {
        Product::factory()->create([
            'sku' => "GLOB-{$i}",
            'name' => "Global product {$i}",
            'long_description' => "Long desc {$i}",
            'category_id' => 12,
            'status' => 'publish',
            'completeness_score' => 90,
        ]);
    }

    $payload = json_decode(invokeReadSimilarShipped(99, 5), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['_fallback'])->toBe('global');
    expect($payload['products'])->toHaveCount(5);
});

it('long_description_first_500_chars is exactly the first 500 chars of long_description', function () {
    $longText = str_repeat('a', 800);
    Product::factory()->create([
        'sku' => 'LONG-DESC',
        'name' => 'Long desc product',
        'long_description' => $longText,
        'category_id' => 12,
        'status' => 'publish',
        'completeness_score' => 90,
    ]);

    $payload = json_decode(invokeReadSimilarShipped(12, 1), true, flags: JSON_THROW_ON_ERROR);

    expect(mb_strlen($payload['products'][0]['long_description_first_500_chars']))->toBe(500);
    expect($payload['products'][0]['long_description_first_500_chars'])->toBe(str_repeat('a', 500));
});

it('respects natural availability when fewer products than limit exist', function () {
    // Seed only 3 eligible
    for ($i = 0; $i < 3; $i++) {
        Product::factory()->create([
            'sku' => "FEW-{$i}",
            'name' => "Few product {$i}",
            'long_description' => 'desc',
            'category_id' => 12,
            'status' => 'publish',
            'completeness_score' => 90,
        ]);
    }

    $payload = json_decode(invokeReadSimilarShipped(12, 10), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['products'])->toHaveCount(3);
});

it('enforces 3-KB soft cap with _truncated hint when payload exceeds 3072 bytes', function () {
    // Seed 10 products with chunky long_descriptions to push past 3 KB
    $bigDesc = str_repeat('words and more words ', 50); // ~1000 chars per product
    for ($i = 0; $i < 10; $i++) {
        Product::factory()->create([
            'sku' => "BIG-{$i}",
            'name' => "Big product {$i}",
            'short_description' => str_repeat('z', 200),
            'long_description' => $bigDesc,
            'meta_description' => "Meta {$i}",
            'category_id' => 12,
            'status' => 'publish',
            'completeness_score' => 90,
        ]);
    }

    $json = invokeReadSimilarShipped(12, 10);

    expect(strlen($json))->toBeLessThanOrEqual(3500);  // 3072 + truncation hint overhead
    expect($json)->toContain('"_truncated":true');
    expect($json)->toContain('"_total_available"');
});

it('non-existent category with zero global products returns empty array without throwing', function () {
    // No products anywhere
    $payload = json_decode(invokeReadSimilarShipped(99, 5), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['products'])->toBe([]);
    expect($payload['category_id'])->toBe(99);
});
