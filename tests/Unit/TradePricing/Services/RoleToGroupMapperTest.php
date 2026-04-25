<?php

declare(strict_types=1);

use App\Domain\TradePricing\Models\CustomerGroup;
use App\Domain\TradePricing\Services\RoleToGroupMapper;
use Database\Seeders\Phase9\CustomerGroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 04 Task 2 — RoleToGroupMapper service tests (D-07)
|--------------------------------------------------------------------------
|
| Locks the Woo role -> CustomerGroup mapping contract:
|   - 4 whitelisted roles each resolve to the right slug
|   - unknown / null / empty roles fall through to null (retail)
|   - is_active=false filters out a deactivated group
|   - hot-swap via Config::set picks up new mappings without code change
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01..03.
*/

function skipIfMySqlOfflineRoleMapper(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    skipIfMySqlOfflineRoleMapper();
    // Seed the 4 D-01 customer groups (trade / reseller / education / nhs).
    $this->seed(CustomerGroupSeeder::class);
});

it('resolves wholesale_customer -> trade group', function (): void {
    $group = app(RoleToGroupMapper::class)->resolve('wholesale_customer');

    expect($group)->toBeInstanceOf(CustomerGroup::class);
    expect($group->slug)->toBe('trade');
});

it('resolves wholesale_b2b -> reseller group', function (): void {
    $group = app(RoleToGroupMapper::class)->resolve('wholesale_b2b');

    expect($group)->toBeInstanceOf(CustomerGroup::class);
    expect($group->slug)->toBe('reseller');
});

it('resolves edu_customer -> education group', function (): void {
    $group = app(RoleToGroupMapper::class)->resolve('edu_customer');

    expect($group)->toBeInstanceOf(CustomerGroup::class);
    expect($group->slug)->toBe('education');
});

it('resolves nhs_customer -> nhs group', function (): void {
    $group = app(RoleToGroupMapper::class)->resolve('nhs_customer');

    expect($group)->toBeInstanceOf(CustomerGroup::class);
    expect($group->slug)->toBe('nhs');
});

it('resolves customer (Woo default) -> null (retail)', function (): void {
    expect(app(RoleToGroupMapper::class)->resolve('customer'))->toBeNull();
});

it('resolves empty string -> null (retail)', function (): void {
    expect(app(RoleToGroupMapper::class)->resolve(''))->toBeNull();
});

it('resolves null -> null (retail)', function (): void {
    expect(app(RoleToGroupMapper::class)->resolve(null))->toBeNull();
});

it('resolves subscriber (unknown role) -> null (retail)', function (): void {
    expect(app(RoleToGroupMapper::class)->resolve('subscriber'))->toBeNull();
});

it('hot-swaps via Config::set with a 5th entry without code change', function (): void {
    // Add a brand-new group + a new role mapping at runtime.
    CustomerGroup::factory()->create([
        'slug' => 'public-sector',
        'name' => 'Public Sector',
        'is_active' => true,
        'display_order' => 50,
    ]);

    Config::set('b2b.role_to_group_map', [
        'wholesale_customer' => 'trade',
        'wholesale_b2b'      => 'reseller',
        'edu_customer'       => 'education',
        'nhs_customer'       => 'nhs',
        'public_sector'      => 'public-sector',
    ]);

    $group = app(RoleToGroupMapper::class)->resolve('public_sector');

    expect($group)->toBeInstanceOf(CustomerGroup::class);
    expect($group->slug)->toBe('public-sector');
});

it('returns null when the mapped group is is_active=false', function (): void {
    // Deactivate the trade group; mapping still exists but is gated.
    CustomerGroup::query()->where('slug', 'trade')->update(['is_active' => false]);

    expect(app(RoleToGroupMapper::class)->resolve('wholesale_customer'))->toBeNull();
});

it('mapToGroupId returns the integer id for a known role', function (): void {
    $expectedId = CustomerGroup::query()->where('slug', 'trade')->value('id');

    expect(app(RoleToGroupMapper::class)->mapToGroupId('wholesale_customer'))
        ->toBe($expectedId);
});

it('mapToGroupId returns null for an unknown role', function (): void {
    expect(app(RoleToGroupMapper::class)->mapToGroupId('subscriber'))->toBeNull();
});
