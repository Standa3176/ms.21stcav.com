<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Products\Filament\Resources\ProductResource;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 04 — ProductResource Field Pins tab (AUTO-10, AUTO-11)
|--------------------------------------------------------------------------
| Exercises the saveFieldPins() authorisation + upsert path and the
| LogsActivity audit trail (D-12).
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->pricingManager = User::factory()->create();
    $this->pricingManager->assignRole('pricing_manager');
    $this->sales = User::factory()->create();
    $this->sales->assignRole('sales');
});

it('admin saving pin_title=true creates a ProductOverride row', function (): void {
    $this->actingAs($this->admin);

    $product = Product::factory()->create();

    ProductResource::saveFieldPins($product, ['pin_title' => true]);

    $override = ProductOverride::where('product_id', $product->id)->first();
    expect($override)->not->toBeNull();
    expect($override->pin_title)->toBeTrue();
    expect($override->pin_image)->toBeFalse();
    expect($override->pin_short_description)->toBeFalse();
});

it('admin toggling an existing override updates in place (no duplicates)', function (): void {
    $this->actingAs($this->admin);

    $product = Product::factory()->create();

    ProductOverride::create([
        'product_id' => $product->id,
        'margin_basis_points' => null,
        'reason' => 'Existing row',
        'created_by_user_id' => $this->admin->id,
        'pin_title' => false,
        'pin_image' => true,
    ]);

    ProductResource::saveFieldPins($product, [
        'pin_title' => true,
        'pin_image' => false,
    ]);

    expect(ProductOverride::where('product_id', $product->id)->count())->toBe(1);
    $row = ProductOverride::where('product_id', $product->id)->first();
    expect($row->pin_title)->toBeTrue();
    expect($row->pin_image)->toBeFalse();
});

it('pricing_manager saving pins is allowed (policy grants update)', function (): void {
    $this->actingAs($this->pricingManager);

    $product = Product::factory()->create();

    ProductResource::saveFieldPins($product, ['pin_slug' => true]);

    $override = ProductOverride::where('product_id', $product->id)->first();
    expect($override)->not->toBeNull();
    expect($override->pin_slug)->toBeTrue();
});

it('sales role cannot save pins (policy denies update)', function (): void {
    $this->actingAs($this->sales);

    $product = Product::factory()->create();

    ProductResource::saveFieldPins($product, ['pin_title' => true]);

    // Authorisation failure means no row was written.
    expect(ProductOverride::where('product_id', $product->id)->exists())->toBeFalse();
});

it('saveFieldPins writes activity_log entry (D-12 audit trail)', function (): void {
    $this->actingAs($this->admin);

    $product = Product::factory()->create();

    ProductResource::saveFieldPins($product, ['pin_title' => true]);

    // Spatie LogsActivity trait on ProductOverride captures pin_* diffs.
    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', ProductOverride::class)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
    $properties = is_array($activity->properties) ? $activity->properties : $activity->properties->toArray();
    expect($properties)->toHaveKey('attributes');
});
