<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\UserSavedFilter;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 01 Task 2 — UserSavedFilter Eloquent model
|--------------------------------------------------------------------------
|
| Covers:
|   - belongsTo(User) relationship
|   - filter_payload_json array cast (nested arrays round-trip)
|   - Unique (user_id, resource_slug, filter_name) composite index
|   - FK cascade on user deletion
*/

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $filter = UserSavedFilter::factory()->create([
        'user_id' => $user->id,
    ]);

    expect($filter->user->id)->toBe($user->id);
});

it('casts filter_payload_json to an array with nested structures', function (): void {
    $user = User::factory()->create();
    $filter = UserSavedFilter::factory()->create([
        'user_id' => $user->id,
        'filter_payload_json' => [
            'status' => 'pending',
            'date_range' => [
                'start' => '2026-04-01',
                'end' => '2026-04-30',
            ],
            'brands' => ['LG', 'Samsung'],
        ],
    ]);

    $filter->refresh();

    expect($filter->filter_payload_json)->toBe([
        'status' => 'pending',
        'date_range' => [
            'start' => '2026-04-01',
            'end' => '2026-04-30',
        ],
        'brands' => ['LG', 'Samsung'],
    ]);
});

it('enforces unique (user_id, resource_slug, filter_name) at the DB level', function (): void {
    $user = User::factory()->create();

    UserSavedFilter::factory()->create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'Pending last 7d',
    ]);

    expect(fn () => UserSavedFilter::factory()->create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'Pending last 7d',
    ]))->toThrow(QueryException::class);
});

it('allows the same filter_name across different users', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    UserSavedFilter::factory()->create([
        'user_id' => $alice->id,
        'resource_slug' => 'products',
        'filter_name' => 'Draft only',
    ]);

    // Bob with the same filter_name + resource_slug must succeed.
    $bobFilter = UserSavedFilter::factory()->create([
        'user_id' => $bob->id,
        'resource_slug' => 'products',
        'filter_name' => 'Draft only',
    ]);

    expect($bobFilter->id)->not->toBeNull();
    expect(UserSavedFilter::where('filter_name', 'Draft only')->count())->toBe(2);
});

it('allows the same filter_name across different resources for one user', function (): void {
    $user = User::factory()->create();

    UserSavedFilter::factory()->create([
        'user_id' => $user->id,
        'resource_slug' => 'products',
        'filter_name' => 'Active',
    ]);

    $second = UserSavedFilter::factory()->create([
        'user_id' => $user->id,
        'resource_slug' => 'suggestions',
        'filter_name' => 'Active',
    ]);

    expect($second->id)->not->toBeNull();
});

it('cascades delete when the owning user is deleted', function (): void {
    $user = User::factory()->create();
    $filter = UserSavedFilter::factory()->create([
        'user_id' => $user->id,
    ]);
    $filterId = $filter->id;

    $user->delete();

    expect(UserSavedFilter::find($filterId))->toBeNull();
});
