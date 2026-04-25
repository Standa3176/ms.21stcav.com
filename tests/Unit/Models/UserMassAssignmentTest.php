<?php

declare(strict_types=1);

use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 Plan 04 Task 1 — User mass-assignment hardening (B-02 invariant)
|--------------------------------------------------------------------------
|
| Locks the structural impossibility of mass-assigning customer_group_id
| via Breeze ProfileController + RegisteredUserController + future API
| forms. The listener (Plan 09-04 Task 3) and backfill command (Plan 09-06
| Task 1) both use ->forceFill([...])->save(), so $fillable is unnecessary;
| omitting it eliminates the mass-assignment vector entirely.
|
| Skip-on-MySQL-offline parity with Phase 6/7/8 + Plan 09-01..09-03.
*/

function skipIfMySqlOfflineUserMassAssignment(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

it('does not allow mass-assignment of customer_group_id via fill', function (): void {
    skipIfMySqlOfflineUserMassAssignment();

    $user = (new User)->fill(['customer_group_id' => 99]);
    expect($user->customer_group_id)->toBeNull();
});

it('does not allow mass-assignment of customer_group_id via User::create', function (): void {
    skipIfMySqlOfflineUserMassAssignment();

    $user = User::create([
        'name' => 'Alice',
        'email' => 'alice-mass@example.com',
        'password' => bcrypt('secret'),
        'customer_group_id' => 99,
    ]);

    expect($user->fresh()->customer_group_id)->toBeNull();
});

it('forceFill DOES persist customer_group_id (listener path)', function (): void {
    skipIfMySqlOfflineUserMassAssignment();

    $group = CustomerGroup::factory()->create();

    $user = User::create([
        'name' => 'Bob',
        'email' => 'bob-force@example.com',
        'password' => bcrypt('secret'),
    ]);

    $user->forceFill(['customer_group_id' => $group->id])->save();

    expect($user->fresh()->customer_group_id)->toBe($group->id);
});
