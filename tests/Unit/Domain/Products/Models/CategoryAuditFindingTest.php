<?php

declare(strict_types=1);

use App\Domain\Products\Models\CategoryAuditFinding;
use App\Domain\Products\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick task 260607-t6w — CategoryAuditFinding model behaviour tests
|--------------------------------------------------------------------------
|
| Validates the four Eloquent contracts the AuditProductCategoriesCommand
| + CategoryAuditPage rely on:
|   1. audited_at hydrates as a Carbon\CarbonInterface (datetime cast).
|   2. severity casts to (int) — raw DB '1' string returns int 1.
|   3. issue_type stays a plain string (no enum cast — column-level only
|      per the migration's string(32) shape).
|   4. ->product() returns a BelongsTo wired to Product::class.
|
| Rows are inserted via DB::table so the test runs without a factory —
| the model itself is the unit under test here.
*/

it('CategoryAuditFinding hydrates audited_at as a CarbonInterface (datetime cast)', function (): void {
    $product = Product::factory()->create();

    DB::table('category_audit_findings')->insert([
        'run_id' => '01HX0000000000000000000000',
        'product_id' => $product->id,
        'sku' => (string) $product->sku,
        'brand_id' => null,
        'brand_name' => '',
        'category_id' => null,
        'category_name' => '',
        'issue_type' => 'missing',
        'severity' => 1,
        'audited_at' => '2026-06-07 22:00:00',
        'created_at' => '2026-06-07 22:00:00',
        'updated_at' => '2026-06-07 22:00:00',
    ]);

    $finding = CategoryAuditFinding::first();

    expect($finding->audited_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
    expect($finding->audited_at->format('Y-m-d H:i:s'))->toBe('2026-06-07 22:00:00');
});

it('CategoryAuditFinding casts severity to int (not string)', function (): void {
    $product = Product::factory()->create();

    DB::table('category_audit_findings')->insert([
        'run_id' => '01HX0000000000000000000000',
        'product_id' => $product->id,
        'sku' => (string) $product->sku,
        'brand_id' => null,
        'brand_name' => '',
        'category_id' => null,
        'category_name' => '',
        'issue_type' => 'missing',
        'severity' => 1,
        'audited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $finding = CategoryAuditFinding::first();

    expect($finding->severity)->toBeInt()->toBe(1);
});

it('CategoryAuditFinding keeps issue_type as a plain string (no enum cast)', function (): void {
    $product = Product::factory()->create();

    DB::table('category_audit_findings')->insert([
        'run_id' => '01HX0000000000000000000000',
        'product_id' => $product->id,
        'sku' => (string) $product->sku,
        'brand_id' => null,
        'brand_name' => '',
        'category_id' => null,
        'category_name' => '',
        'issue_type' => 'suspicious',
        'severity' => 4,
        'audited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $finding = CategoryAuditFinding::first();

    expect($finding->issue_type)->toBeString()->toBe('suspicious');
});

it('CategoryAuditFinding->product() returns a BelongsTo to Product', function (): void {
    $product = Product::factory()->create();

    DB::table('category_audit_findings')->insert([
        'run_id' => '01HX0000000000000000000000',
        'product_id' => $product->id,
        'sku' => (string) $product->sku,
        'brand_id' => null,
        'brand_name' => '',
        'category_id' => null,
        'category_name' => '',
        'issue_type' => 'orphaned',
        'severity' => 2,
        'audited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $finding = CategoryAuditFinding::first();

    expect($finding->product())->toBeInstanceOf(BelongsTo::class);
    expect($finding->product()->getRelated())->toBeInstanceOf(Product::class);
    expect($finding->product->id)->toBe($product->id);
});
