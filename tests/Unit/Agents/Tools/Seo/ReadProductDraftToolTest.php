<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Seo\ReadProductDraftTool;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 12 Plan 02 Task 1 — ReadProductDraftTool real-impl unit tests
|--------------------------------------------------------------------------
|
| Validates SEOAGT-02 read_product_draft tool body:
|   - Returns documented schema for an existing Product
|   - 4096-char per-field cap (matches AgentRun.tool_calls output cap)
|   - Non-existent SKU returns {error: 'not_found', sku: '...'} — never throws
|   - brand_slug resolution uses brands.slug (P12-C mitigation precursor)
*/

function invokeReadProductDraftTool(string $sku): string
{
    return app(ReadProductDraftTool::class)->asPrismTool()->handle($sku);
}

function ensureBrandsTable(): void
{
    if (! Schema::hasTable('brands')) {
        Schema::create('brands', function ($t): void {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
        });
    }
}

it('returns the documented schema for an existing Product (SEOAGT-02)', function () {
    ensureBrandsTable();
    DB::table('brands')->insert(['id' => 5, 'slug' => 'logitech', 'name' => 'Logitech, Inc.']);

    Product::factory()->create([
        'sku' => 'LOGI-MEETUP',
        'name' => 'Logitech MeetUp Conference Camera',
        'short_description' => 'All-in-one ConferenceCam for small huddle rooms.',
        'long_description' => 'Long supplier description goes here.',
        'meta_description' => 'Logitech MeetUp — huddle-room camera.',
        'brand_id' => 5,
        'category_id' => 12,
        'status' => 'publish',
        'auto_create_status' => 'pending_review',
        'completeness_score' => 64,
        'completeness_missing_fields' => ['long_description', 'meta_description'],
    ]);

    $payload = json_decode(invokeReadProductDraftTool('LOGI-MEETUP'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['sku'])->toBe('LOGI-MEETUP');
    expect($payload['name'])->toBe('Logitech MeetUp Conference Camera');
    expect($payload['short_description'])->toBe('All-in-one ConferenceCam for small huddle rooms.');
    expect($payload['long_description'])->toBe('Long supplier description goes here.');
    expect($payload['meta_description'])->toBe('Logitech MeetUp — huddle-room camera.');
    expect($payload['brand_id'])->toBe(5);
    expect($payload['brand_slug'])->toBe('logitech');
    expect($payload['category_id'])->toBe(12);
    expect($payload['completeness_score'])->toBe(64);
    expect($payload['completeness_missing_fields'])->toBe(['long_description', 'meta_description']);
});

it('returns {error: not_found, sku} for non-existent SKU — never throws', function () {
    $payload = json_decode(invokeReadProductDraftTool('NEVER-SEEN-SKU'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'error' => 'not_found',
        'sku' => 'NEVER-SEEN-SKU',
    ]);
});

it('caps each string field to 4096 chars via mb_substr', function () {
    ensureBrandsTable();

    $longText = str_repeat('a', 10_000);

    Product::factory()->create([
        'sku' => 'BIG-DESC',
        'name' => $longText,
        'short_description' => $longText,
        'long_description' => $longText,
        'meta_description' => $longText,
        'brand_id' => null,
        'category_id' => null,
        'status' => 'publish',
    ]);

    $payload = json_decode(invokeReadProductDraftTool('BIG-DESC'), true, flags: JSON_THROW_ON_ERROR);

    expect(mb_strlen($payload['name']))->toBe(4096);
    expect(mb_strlen($payload['short_description']))->toBe(4096);
    expect(mb_strlen($payload['long_description']))->toBe(4096);
    expect(mb_strlen($payload['meta_description']))->toBe(4096);
});

it('falls back brand_slug to (string) brand_id when no brands row matches (P12-C edge)', function () {
    ensureBrandsTable();
    // No brands row inserted — brand_id=99 has no matching slug
    Product::factory()->create([
        'sku' => 'NO-BRAND-MATCH',
        'name' => 'orphan',
        'brand_id' => 99,
        'category_id' => 12,
        'status' => 'publish',
    ]);

    $payload = json_decode(invokeReadProductDraftTool('NO-BRAND-MATCH'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['brand_id'])->toBe(99);
    expect($payload['brand_slug'])->toBe('99');
});

it('returns brand_slug=null when product brand_id is null', function () {
    Product::factory()->create([
        'sku' => 'NO-BRAND',
        'name' => 'no brand',
        'brand_id' => null,
        'category_id' => null,
        'status' => 'publish',
    ]);

    $payload = json_decode(invokeReadProductDraftTool('NO-BRAND'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['brand_id'])->toBeNull();
    expect($payload['brand_slug'])->toBeNull();
});
