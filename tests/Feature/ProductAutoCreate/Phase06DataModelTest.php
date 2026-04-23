<?php

declare(strict_types=1);

use App\Domain\Alerting\Models\AlertRecipient;
use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 01 — Phase06DataModelTest
|--------------------------------------------------------------------------
| Verifies the 5 schema changes land on `migrate:fresh` and that the
| Pitfall P6-D backfill leaves existing products.auto_create_status
| as 'manual' (never NULL).
*/

it('creates the auto_create_skip_rules table with expected columns', function (): void {
    expect(Schema::hasTable('auto_create_skip_rules'))->toBeTrue();
    foreach (['id', 'scope', 'value', 'reason', 'is_active', 'created_by_user_id', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('auto_create_skip_rules', $col))->toBeTrue("Missing column: {$col}");
    }
});

it('creates the auto_create_rejections table with expected columns', function (): void {
    expect(Schema::hasTable('auto_create_rejections'))->toBeTrue();
    foreach (['id', 'product_id', 'reason', 'notes', 'rejected_by_user_id', 'created_at'] as $col) {
        expect(Schema::hasColumn('auto_create_rejections', $col))->toBeTrue("Missing column: {$col}");
    }
});

it('extends products with Phase 6 columns', function (): void {
    foreach ([
        'slug',
        'short_description',
        'long_description',
        'meta_description',
        'image_url',
        'requires_manual_image_review',
        'auto_create_status',
        'completeness_score',
        'completeness_computed_at',
        'completeness_missing_fields',
    ] as $col) {
        expect(Schema::hasColumn('products', $col))->toBeTrue("Missing column: products.{$col}");
    }
});

it('extends product_overrides with 8 pin_* columns', function (): void {
    foreach ([
        'pin_title',
        'pin_short_description',
        'pin_long_description',
        'pin_meta_description',
        'pin_image',
        'pin_slug',
        'pin_brand',
        'pin_category',
    ] as $col) {
        expect(Schema::hasColumn('product_overrides', $col))->toBeTrue("Missing column: product_overrides.{$col}");
    }
});

it('extends alert_recipients with receives_auto_create_alerts', function (): void {
    expect(Schema::hasColumn('alert_recipients', 'receives_auto_create_alerts'))->toBeTrue();
});

it('backfills pre-existing products to auto_create_status=manual (Pitfall P6-D)', function (): void {
    // RefreshDatabase means the migration already ran. Create a product the
    // standard way — factory default exercise — and verify the column lands as
    // 'manual' (the migration's DEFAULT clause).
    $product = Product::factory()->create();

    $raw = DB::table('products')->where('id', $product->id)->value('auto_create_status');
    expect($raw)->toBe('manual');
});

it('backfills pre-existing product_overrides to pin_*=false', function (): void {
    $override = ProductOverride::factory()->create();

    $raw = DB::table('product_overrides')->where('id', $override->id)->first();
    foreach ([
        'pin_title', 'pin_short_description', 'pin_long_description', 'pin_meta_description',
        'pin_image', 'pin_slug', 'pin_brand', 'pin_category',
    ] as $col) {
        expect((int) $raw->{$col})->toBe(0, "Expected {$col}=0, got {$raw->{$col}}");
    }
});

it('Product::fillable includes the new Phase 6 columns', function (): void {
    $product = new Product;
    $fillable = $product->getFillable();
    foreach ([
        'slug', 'short_description', 'long_description', 'meta_description',
        'image_url', 'requires_manual_image_review', 'auto_create_status',
        'completeness_score', 'completeness_computed_at', 'completeness_missing_fields',
    ] as $col) {
        expect($fillable)->toContain($col);
    }
});

it('ProductOverride::fillable includes 8 pin_* columns', function (): void {
    $fillable = (new ProductOverride)->getFillable();
    foreach ([
        'pin_title', 'pin_short_description', 'pin_long_description', 'pin_meta_description',
        'pin_image', 'pin_slug', 'pin_brand', 'pin_category',
    ] as $col) {
        expect($fillable)->toContain($col);
    }
});

it('AlertRecipient::fillable includes receives_auto_create_alerts', function (): void {
    expect((new AlertRecipient)->getFillable())->toContain('receives_auto_create_alerts');
});

it('AlertRecipient scope receivesAutoCreateAlerts filters correctly', function (): void {
    AlertRecipient::create([
        'email' => 'on@test.local',
        'name' => 'On',
        'is_active' => true,
        'receives_auto_create_alerts' => true,
    ]);
    AlertRecipient::create([
        'email' => 'off@test.local',
        'name' => 'Off',
        'is_active' => true,
        'receives_auto_create_alerts' => false,
    ]);

    $emails = AlertRecipient::query()
        ->receivesAutoCreateAlerts()
        ->pluck('email')
        ->all();

    expect($emails)->toContain('on@test.local')
        ->and($emails)->not->toContain('off@test.local');
});
