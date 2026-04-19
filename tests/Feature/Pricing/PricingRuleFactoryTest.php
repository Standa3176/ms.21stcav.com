<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Policies\PricingRulePolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Plan 03-01 PricingRule factory + schema + policy contract.
|--------------------------------------------------------------------------
*/

function pricingRuleRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

it('creates the pricing_rules table with every expected column (D-06, D-07)', function () {
    expect(Schema::hasTable('pricing_rules'))->toBeTrue();

    $expected = [
        'id', 'scope', 'brand_id', 'category_id',
        'margin_basis_points', 'priority', 'is_default_tier',
        'tier_min_pennies', 'tier_max_pennies', 'active',
        'created_by_user_id', 'created_at', 'updated_at',
    ];

    foreach ($expected as $column) {
        expect(Schema::hasColumn('pricing_rules', $column))
            ->toBeTrue("pricing_rules missing column: {$column}");
    }
});

it('PricingRule factory make() returns a filled model with default scope=brand, priority=100', function () {
    $rule = PricingRule::factory()->make();

    expect($rule->scope)->toBe(PricingRule::SCOPE_BRAND);
    expect($rule->priority)->toBe(100);
    expect($rule->margin_basis_points)->toBe(2500);
    expect($rule->is_default_tier)->toBeFalse();
    expect($rule->active)->toBeTrue();
});

it('defaultTier() state persists is_default_tier=true + tier bounds, brand+category NULL', function () {
    $rule = PricingRule::factory()->defaultTier()->create();

    expect($rule->is_default_tier)->toBeTrue();
    expect($rule->scope)->toBe(PricingRule::SCOPE_DEFAULT_TIER);
    expect($rule->tier_min_pennies)->toBe(0);
    expect($rule->tier_max_pennies)->toBe(9999);
    expect($rule->brand_id)->toBeNull();
    expect($rule->category_id)->toBeNull();
});

it('brandCategory() state persists both brand_id and category_id set', function () {
    $rule = PricingRule::factory()->brandCategory()->create();

    expect($rule->scope)->toBe(PricingRule::SCOPE_BRAND_CATEGORY);
    expect($rule->brand_id)->not->toBeNull();
    expect($rule->category_id)->not->toBeNull();
});

it('inactive() state sets active=false', function () {
    $rule = PricingRule::factory()->inactive()->create();

    expect($rule->active)->toBeFalse();
});

it('LogsActivity captures PricingRule::create on pricing-affecting columns', function () {
    $before = Activity::count();

    PricingRule::factory()->create([
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 42,
        'margin_basis_points' => 3000,
    ]);

    $after = Activity::count();
    expect($after)->toBeGreaterThan($before, 'activity_log should record PricingRule create event');
});

it('LogsActivity captures PricingRule::update when a logged column changes (logOnlyDirty)', function () {
    $rule = PricingRule::factory()->create(['margin_basis_points' => 2500]);
    $before = Activity::count();

    $rule->update(['margin_basis_points' => 3000]);

    $after = Activity::count();
    expect($after)->toBeGreaterThan($before);
});

it('gates PricingRulePolicy update: admin + pricing_manager allow; sales + read_only deny', function () {
    $policy = new PricingRulePolicy;
    $rule = PricingRule::factory()->create();

    expect($policy->viewAny(pricingRuleRoleUser('admin')))->toBeTrue();
    expect($policy->update(pricingRuleRoleUser('admin'), $rule))->toBeTrue();

    expect($policy->viewAny(pricingRuleRoleUser('pricing_manager')))->toBeTrue();
    expect($policy->update(pricingRuleRoleUser('pricing_manager'), $rule))->toBeTrue();

    expect($policy->viewAny(pricingRuleRoleUser('sales')))->toBeTrue();
    expect($policy->update(pricingRuleRoleUser('sales'), $rule))->toBeFalse();

    expect($policy->viewAny(pricingRuleRoleUser('read_only')))->toBeTrue();
    expect($policy->update(pricingRuleRoleUser('read_only'), $rule))->toBeFalse();
});

it('gates PricingRulePolicy delete: admin + pricing_manager allow; sales + read_only deny', function () {
    $policy = new PricingRulePolicy;
    $rule = PricingRule::factory()->create();

    expect($policy->delete(pricingRuleRoleUser('admin'), $rule))->toBeTrue();
    expect($policy->delete(pricingRuleRoleUser('pricing_manager'), $rule))->toBeTrue();
    expect($policy->delete(pricingRuleRoleUser('sales'), $rule))->toBeFalse();
    expect($policy->delete(pricingRuleRoleUser('read_only'), $rule))->toBeFalse();
});

it('registers PricingRulePolicy on the Gate facade via AppServiceProvider::boot', function () {
    expect(Gate::getPolicyFor(PricingRule::class))->toBeInstanceOf(PricingRulePolicy::class);
});
