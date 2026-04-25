<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\PricingRule;
use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 01 Task 1 — CustomerGroup model + FK contract (TRDE-01)
|--------------------------------------------------------------------------
|
| Locks the foundational invariants that every later Phase 9 plan consumes:
|
|   1. customer_groups schema shape: slug unique, is_active bool, display_order int.
|   2. PricingRule.customer_group_id is nullable (retail rules unaffected) and
|      enforces FK ON DELETE RESTRICT — deleting a group with rules is rejected.
|   3. LogsActivity audits the four sales-relevant columns (slug/name/is_active/
|      display_order) — created_at drift is NOT audited.
|   4. PricingRule->customerGroup() relation resolves bidirectionally with
|      CustomerGroup->pricingRules().
|
| Skip-on-MySQL-offline parity with Phase 6/7/8: these tests instantiate
| models against the live MySQL connection. RefreshDatabase wraps each test
| in a transaction against meetingstore_ops_testing — if that DB is refused
| (sandboxed code-executor environment) the entire suite is skipped via the
| `skipIfMySqlOffline()` helper invoked at the top of every `it()`.
| (The same posture v1 Phase 6/7/8 used; v2 Phase 8 inherits it.)
*/

function skipIfMySqlOffline(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

it('creates a CustomerGroup via factory with the expected default shape', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create([
        'slug' => 'trade-test',
        'name' => 'Trade Test',
        'is_active' => true,
        'display_order' => 100,
    ]);

    expect($group->exists)->toBeTrue();
    expect($group->slug)->toBe('trade-test');
    expect($group->name)->toBe('Trade Test');
    expect($group->is_active)->toBeTrue();
    expect($group->display_order)->toBe(100);
});

it('enforces unique slug at the database level', function (): void {
    skipIfMySqlOffline();

    CustomerGroup::factory()->create(['slug' => 'duplicate-slug']);

    expect(fn () => CustomerGroup::factory()->create(['slug' => 'duplicate-slug']))
        ->toThrow(QueryException::class);
});

it('casts is_active to bool (DB stores 0/1 — model returns true/false)', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create(['is_active' => 1]);
    expect($group->is_active)->toBeBool()->toBeTrue();

    $inactive = CustomerGroup::factory()->create(['slug' => 'inactive-test', 'is_active' => 0]);
    expect($inactive->is_active)->toBeBool()->toBeFalse();
});

it('casts display_order to integer', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create(['display_order' => '250']);
    expect($group->display_order)->toBeInt()->toBe(250);
});

it('exposes pricingRules() HasMany relation returning PricingRule collection', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create(['slug' => 'rel-test']);

    PricingRule::factory()->create([
        'customer_group_id' => $group->id,
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1,
        'category_id' => null,
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2500,
        'priority' => 100,
        'active' => true,
    ]);

    PricingRule::factory()->create([
        'customer_group_id' => $group->id,
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 2,
        'category_id' => null,
        'is_default_tier' => false,
        'tier_min_pennies' => null,
        'tier_max_pennies' => null,
        'margin_basis_points' => 2200,
        'priority' => 100,
        'active' => true,
    ]);

    expect($group->pricingRules)->toHaveCount(2);
    expect($group->pricingRules->first())->toBeInstanceOf(PricingRule::class);
});

it('LogsActivity records only [slug, name, is_active, display_order] dirty fields', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create([
        'slug' => 'audit-test',
        'name' => 'Original Name',
        'is_active' => true,
        'display_order' => 100,
    ]);

    // Dirty an audited field.
    $group->update(['name' => 'Updated Name']);

    $activity = Activity::query()
        ->where('subject_type', CustomerGroup::class)
        ->where('subject_id', $group->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull();
    $attrs = $activity->properties['attributes'] ?? [];
    expect($attrs)->toHaveKey('name')
        ->and($attrs)->not->toHaveKey('updated_at')
        ->and($attrs)->not->toHaveKey('created_at');
});

it('PricingRule->customerGroup() returns BelongsTo CustomerGroup', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create(['slug' => 'belongs-to-test']);

    $rule = PricingRule::factory()->create([
        'customer_group_id' => $group->id,
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1,
        'margin_basis_points' => 2500,
        'priority' => 100,
        'active' => true,
    ]);

    expect($rule->customerGroup)->toBeInstanceOf(CustomerGroup::class);
    expect($rule->customerGroup->id)->toBe($group->id);
});

it('saves PricingRule with customer_group_id=null (retail rule)', function (): void {
    skipIfMySqlOffline();

    $rule = PricingRule::factory()->create([
        'customer_group_id' => null,
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1,
        'margin_basis_points' => 2500,
        'priority' => 100,
        'active' => true,
    ]);

    expect($rule->exists)->toBeTrue();
    expect($rule->customer_group_id)->toBeNull();
});

it('saves PricingRule with valid customer_group_id (trade rule)', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create(['slug' => 'trade-rule']);

    $rule = PricingRule::factory()->create([
        'customer_group_id' => $group->id,
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1,
        'margin_basis_points' => 2200,
        'priority' => 200,
        'active' => true,
    ]);

    expect($rule->exists)->toBeTrue();
    expect($rule->customer_group_id)->toBe($group->id);
});

it('rejects deletion of a CustomerGroup with active rules (FK ON DELETE RESTRICT)', function (): void {
    skipIfMySqlOffline();

    $group = CustomerGroup::factory()->create(['slug' => 'restrict-test']);

    PricingRule::factory()->create([
        'customer_group_id' => $group->id,
        'scope' => PricingRule::SCOPE_BRAND,
        'brand_id' => 1,
        'margin_basis_points' => 2500,
        'priority' => 100,
        'active' => true,
    ]);

    expect(fn () => $group->delete())->toThrow(QueryException::class);
});
