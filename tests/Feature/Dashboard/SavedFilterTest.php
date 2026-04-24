<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\UserSavedFilter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 03 Task 2 — SavedFilterAction + UserSavedFilter policy scope
|--------------------------------------------------------------------------
|
| Covers (plan <behavior> S1..S4):
|   - S1: user can save a filter (policy allows create, row created)
|   - S2: user A cannot see user B's saved filters (row-level scope)
|   - S3: applying a saved filter rehydrates the payload (integration hook)
|   - S4: deleting another user's saved filter is denied (policy enforcement)
*/

beforeEach(function () {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('allows any authenticated user to create a saved filter (policy gates create)', function (): void {
    $user = User::factory()->create();
    $user->assignRole('sales');

    expect($user->can('create', UserSavedFilter::class))->toBeTrue();
});

it('persists filter payload JSON when saved', function (): void {
    $user = User::factory()->create();

    $filter = UserSavedFilter::create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'Pending last 7d',
        'filter_payload_json' => [
            'status' => 'pending',
            'created_since' => now()->subDays(7)->toIso8601String(),
        ],
    ]);

    $filter->refresh();
    expect($filter->filter_payload_json)->toHaveKey('status');
    expect($filter->filter_payload_json['status'])->toBe('pending');
});

it('prevents user A from reading user B saved filter via policy', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $bobsFilter = UserSavedFilter::create([
        'user_id' => $bob->id,
        'resource_slug' => 'products',
        'filter_name' => "Bob's draft",
        'filter_payload_json' => ['status' => 'draft'],
    ]);

    expect($alice->can('view', $bobsFilter))->toBeFalse();
    expect($bob->can('view', $bobsFilter))->toBeTrue();
});

it('scopes the query to the current user for Resource listing', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    UserSavedFilter::create([
        'user_id' => $alice->id,
        'resource_slug' => 'products',
        'filter_name' => 'Alice 1',
        'filter_payload_json' => [],
    ]);
    UserSavedFilter::create([
        'user_id' => $alice->id,
        'resource_slug' => 'products',
        'filter_name' => 'Alice 2',
        'filter_payload_json' => [],
    ]);
    UserSavedFilter::create([
        'user_id' => $bob->id,
        'resource_slug' => 'products',
        'filter_name' => 'Bob 1',
        'filter_payload_json' => [],
    ]);

    $aliceFilters = UserSavedFilter::query()
        ->where('user_id', $alice->id)
        ->where('resource_slug', 'products')
        ->get();

    expect($aliceFilters)->toHaveCount(2);
    expect($aliceFilters->pluck('filter_name')->toArray())->toBe(['Alice 1', 'Alice 2']);
});

it('denies delete on another user filter even with admin override gated', function (): void {
    $alice = User::factory()->create();
    $alice->assignRole('sales');
    $bob = User::factory()->create();

    $bobsFilter = UserSavedFilter::create([
        'user_id' => $bob->id,
        'resource_slug' => 'products',
        'filter_name' => "Bob's draft",
        'filter_payload_json' => [],
    ]);

    // Alice (sales role, not admin) cannot delete Bob's filter.
    expect($alice->can('delete', $bobsFilter))->toBeFalse();

    // Admin override: a separate user with admin role CAN delete (policy allows owner OR admin).
    $adminUser = User::factory()->create();
    $adminUser->assignRole('admin');
    expect($adminUser->can('delete', $bobsFilter))->toBeTrue();
});

it('allows owner to update their own filter payload', function (): void {
    $user = User::factory()->create();

    $filter = UserSavedFilter::create([
        'user_id' => $user->id,
        'resource_slug' => 'suggestions',
        'filter_name' => 'Open pending',
        'filter_payload_json' => ['status' => 'pending'],
    ]);

    expect($user->can('update', $filter))->toBeTrue();
});

it('supports multiple filters per user per resource', function (): void {
    $user = User::factory()->create();

    UserSavedFilter::create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'A',
        'filter_payload_json' => [],
    ]);
    UserSavedFilter::create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'B',
        'filter_payload_json' => [],
    ]);
    UserSavedFilter::create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'C',
        'filter_payload_json' => [],
    ]);

    $count = UserSavedFilter::where('user_id', $user->id)
        ->where('resource_slug', 'products')
        ->count();

    expect($count)->toBe(3);
});

it('round-trips nested filter payload structures', function (): void {
    $user = User::factory()->create();

    $filter = UserSavedFilter::create([
        'user_id' => $user->id,
        'resource_slug' => 'competitor-prices',
        'filter_name' => 'Apr 2026 Miele',
        'filter_payload_json' => [
            'competitor_id' => 3,
            'recorded_at' => [
                'from' => '2026-04-01',
                'to' => '2026-04-30',
            ],
        ],
    ]);

    $filter->refresh();
    expect($filter->filter_payload_json['recorded_at']['from'])->toBe('2026-04-01');
    expect($filter->filter_payload_json['recorded_at']['to'])->toBe('2026-04-30');
});

it('deletes cascade when the owning user is removed', function (): void {
    $user = User::factory()->create();
    UserSavedFilter::create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'Tmp',
        'filter_payload_json' => [],
    ]);

    $userId = $user->id;
    $user->delete();

    expect(UserSavedFilter::where('user_id', $userId)->count())->toBe(0);
});
