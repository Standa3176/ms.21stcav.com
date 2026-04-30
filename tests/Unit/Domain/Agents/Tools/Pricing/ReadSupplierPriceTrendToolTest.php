<?php

declare(strict_types=1);

use App\Domain\Agents\Tools\Pricing\ReadSupplierPriceTrendTool;
use App\Domain\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 10 Plan 02 Task 1 — ReadSupplierPriceTrendTool real-impl unit tests
|--------------------------------------------------------------------------
|
| Validates:
|   - Option A (audit_log) — reads activity_log entries on Product where
|     properties.old.buy_price exists.
|   - Degraded fallback — when no per-product audit entries exist, returns
|     {data_points: [], current_buy_price_pennies: $product->buy_price * 100,
|      _note: ...} per RESEARCH §Tool 3 Option A fallback.
|   - 90d window enforcement (CONTEXT D-04).
|   - Unknown SKU returns empty payload, never throws.
*/

function invokeReadSupplierPriceTrendTool(string $sku): string
{
    // Prism v0.100.1 Tool::handle(...$args) takes variadic positional args
    return app(ReadSupplierPriceTrendTool::class)->asPrismTool()->handle($sku);
}

it('returns degraded fallback when no per-product audit entries exist', function () {
    Product::factory()->create([
        'sku' => 'NO-AUDIT-SKU',
        'buy_price' => 1200.00, // 120000 pennies
    ]);

    $payload = json_decode(invokeReadSupplierPriceTrendTool('NO-AUDIT-SKU'), true);

    expect($payload['sku'])->toBe('NO-AUDIT-SKU');
    expect($payload['window_days'])->toBe(90);
    expect($payload['data_points'])->toBe([]);
    expect($payload['current_buy_price_pennies'])->toBe(120000);
    expect($payload)->toHaveKey('_note');
});

it('reads activity_log entries on Product when audit trail exists (Option A)', function () {
    $product = Product::factory()->create([
        'sku' => 'WITH-AUDIT-SKU',
        'buy_price' => 1300.00,
    ]);

    // Manually log an activity entry simulating a buy_price change
    $product->name = $product->name . ' v2';
    $product->buy_price = 1300.00;
    $product->save();

    // Seed an Activity row directly (mimics what activitylog would do)
    Activity::create([
        'log_name' => 'default',
        'description' => 'updated',
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'event' => 'updated',
        'properties' => [
            'attributes' => ['buy_price' => 1300.00],
            'old' => ['buy_price' => 1200.00],
        ],
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);

    $payload = json_decode(invokeReadSupplierPriceTrendTool('WITH-AUDIT-SKU'), true);

    expect($payload['sku'])->toBe('WITH-AUDIT-SKU');
    expect($payload['window_days'])->toBe(90);
    expect($payload['data_points'])->toHaveCount(1);
    expect($payload['current_buy_price_pennies'])->toBe(130000);
});

it('enforces 90d window for audit_log entries', function () {
    $product = Product::factory()->create([
        'sku' => 'WINDOW-SKU',
        'buy_price' => 1500.00,
    ]);

    Activity::create([
        'log_name' => 'default',
        'description' => 'updated',
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'event' => 'updated',
        'properties' => [
            'attributes' => ['buy_price' => 1400.00],
            'old' => ['buy_price' => 1300.00],
        ],
        'created_at' => now()->subDays(100),
        'updated_at' => now()->subDays(100),
    ]);
    Activity::create([
        'log_name' => 'default',
        'description' => 'updated',
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'event' => 'updated',
        'properties' => [
            'attributes' => ['buy_price' => 1500.00],
            'old' => ['buy_price' => 1400.00],
        ],
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(30),
    ]);

    $payload = json_decode(invokeReadSupplierPriceTrendTool('WINDOW-SKU'), true);

    expect($payload['data_points'])->toHaveCount(1);
});

it('returns 0 buy_price for unknown SKU — never throws', function () {
    $payload = json_decode(invokeReadSupplierPriceTrendTool('NEVER-SEEN-SKU'), true);

    expect($payload['sku'])->toBe('NEVER-SEEN-SKU');
    expect($payload['data_points'])->toBe([]);
    expect($payload['current_buy_price_pennies'])->toBe(0);
});
