<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\ProductOverride;
use App\Domain\Pricing\Policies\ProductOverridePolicy;
use App\Domain\Products\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Plan 03-01 ProductOverride factory + schema + policy contract.
|--------------------------------------------------------------------------
*/

function productOverrideRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('creates the product_overrides table with UNIQUE product_id (D-08, D-09)', function () {
    expect(Schema::hasTable('product_overrides'))->toBeTrue();

    $expected = [
        'id', 'product_id', 'margin_basis_points', 'reason',
        'created_by_user_id', 'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('product_overrides', $column))
            ->toBeTrue("product_overrides missing column: {$column}");
    }

    // D-09: parent-only — no variant_id column in v1.
    expect(Schema::hasColumn('product_overrides', 'variant_id'))
        ->toBeFalse('v1 is parent-only; variant_id is a v2 forward-compat addition');
});

it('ProductOverride factory ->for($product)->create() persists', function () {
    $product = Product::factory()->create();
    $override = ProductOverride::factory()->for($product)->create();

    expect($override->id)->not->toBeNull();
    expect($override->product_id)->toBe($product->id);
    expect($override->margin_basis_points)->toBe(4000);
});

it('UNIQUE(product_id) prevents a second override for the same product (D-08)', function () {
    $product = Product::factory()->create();
    ProductOverride::factory()->for($product)->create();

    // Second override for the same product must fail.
    expect(fn () => ProductOverride::factory()->for($product)->create())
        ->toThrow(QueryException::class);
});

it('product() relation resolves to the parent Product', function () {
    $product = Product::factory()->create();
    $override = ProductOverride::factory()->for($product)->create();

    expect($override->product)->toBeInstanceOf(Product::class);
    expect($override->product->id)->toBe($product->id);
});

it('LogsActivity captures ProductOverride::create', function () {
    $before = Activity::count();

    ProductOverride::factory()->create();

    $after = Activity::count();
    expect($after)->toBeGreaterThan($before);
});

it('gates ProductOverridePolicy update: admin + pricing_manager allow; sales + read_only deny', function () {
    $policy = new ProductOverridePolicy;
    $override = ProductOverride::factory()->create();

    expect($policy->viewAny(productOverrideRoleUser('admin')))->toBeTrue();
    expect($policy->update(productOverrideRoleUser('admin'), $override))->toBeTrue();

    expect($policy->viewAny(productOverrideRoleUser('pricing_manager')))->toBeTrue();
    expect($policy->update(productOverrideRoleUser('pricing_manager'), $override))->toBeTrue();

    expect($policy->viewAny(productOverrideRoleUser('sales')))->toBeTrue();
    expect($policy->update(productOverrideRoleUser('sales'), $override))->toBeFalse();

    expect($policy->viewAny(productOverrideRoleUser('read_only')))->toBeTrue();
    expect($policy->update(productOverrideRoleUser('read_only'), $override))->toBeFalse();
});

it('registers ProductOverridePolicy on the Gate facade via AppServiceProvider::boot', function () {
    expect(Gate::getPolicyFor(ProductOverride::class))->toBeInstanceOf(ProductOverridePolicy::class);
});
