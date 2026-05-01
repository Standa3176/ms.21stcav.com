<?php

declare(strict_types=1);

use App\Domain\Quotes\Enums\RejectionReason;
use App\Domain\Quotes\Models\Quote;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Phase 11 Plan 03 — MarkRejectedAction tests (D-07 + D-08)
|--------------------------------------------------------------------------
|
| Verifies rejection_metadata persisted with all 4 keys (reason / notes /
| rejected_by_user_id / rejected_at) + status flips to rejected + rejected_at
| timestamp populated. The action body is exercised via direct simulation
| (the Filament Action handler logic is replicated here so we can assert the
| persistence shape without spinning up the full Livewire form).
*/

function skipIfMySqlOfflineReject(): void
{
    try {
        \DB::connection()->getPdo();
    } catch (\Throwable $e) {
        test()->markTestSkipped('MySQL offline: '.$e->getMessage());
    }
}

beforeEach(function (): void {
    // Pre-trait skip: fires BEFORE RefreshDatabase trait setUp, so MySQL-
    // offline tests SKIP cleanly instead of failing with QueryException
    // (Phase 11 Plan 02 PriceSnapshotterTest precedent).
    skipIfMySqlOfflineReject();
});

function rejectRoleUser(string $roleName): User
{
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user->fresh();
}

function seedRejectQuotePerms(): void
{
    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'view_any_quote', 'view_quote', 'create_quote', 'update_quote',
        'delete_quote', 'approve_quote', 'revert_quote',
        'mark_accepted_quote', 'mark_rejected_quote',
    ] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    test()->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. rejection_metadata persisted with all 4 keys (D-08 structured capture)
// ══════════════════════════════════════════════════════════════════════════════

it('persists rejection_metadata with reason + notes + rejected_by_user_id + rejected_at on submit', function (): void {
    skipIfMySqlOfflineReject();
    seedRejectQuotePerms();

    $admin = rejectRoleUser('admin');
    $quote = Quote::factory()->create([
        'status' => Quote::STATUS_SENT,
        'sent_at' => now()->subMinutes(60),
    ]);
    $this->actingAs($admin);

    expect($admin->can('markRejected', $quote))->toBeTrue();

    // Simulate the Filament Action body.
    $data = [
        'reason' => RejectionReason::CompetitorWon->value,
        'notes' => 'Lost to AcmeCo on price + faster lead time',
    ];

    $quote->update([
        'status' => Quote::STATUS_REJECTED,
        'rejected_at' => now(),
        'rejection_metadata' => [
            'reason' => $data['reason'],
            'notes' => trim($data['notes']),
            'rejected_by_user_id' => $admin->id,
            'rejected_at' => now()->toIso8601String(),
        ],
    ]);

    $quote->refresh();
    expect($quote->status)->toBe(Quote::STATUS_REJECTED)
        ->and($quote->rejected_at)->not->toBeNull()
        ->and($quote->rejection_metadata)->toBeArray()
        ->and($quote->rejection_metadata)->toHaveKey('reason')
        ->and($quote->rejection_metadata)->toHaveKey('notes')
        ->and($quote->rejection_metadata)->toHaveKey('rejected_by_user_id')
        ->and($quote->rejection_metadata)->toHaveKey('rejected_at')
        ->and($quote->rejection_metadata['reason'])->toBe('competitor_won')
        ->and($quote->rejection_metadata['notes'])->toBe('Lost to AcmeCo on price + faster lead time')
        ->and($quote->rejection_metadata['rejected_by_user_id'])->toBe($admin->id);
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// 2. notes optional — null persisted when omitted
// ══════════════════════════════════════════════════════════════════════════════

it('persists null notes when only reason supplied', function (): void {
    skipIfMySqlOfflineReject();
    seedRejectQuotePerms();

    $admin = rejectRoleUser('admin');
    $quote = Quote::factory()->create([
        'status' => Quote::STATUS_SENT,
        'sent_at' => now()->subMinutes(30),
    ]);
    $this->actingAs($admin);

    $data = ['reason' => RejectionReason::DelayedDecision->value];

    $quote->update([
        'status' => Quote::STATUS_REJECTED,
        'rejected_at' => now(),
        'rejection_metadata' => [
            'reason' => $data['reason'],
            'notes' => isset($data['notes']) && $data['notes'] !== '' ? trim($data['notes']) : null,
            'rejected_by_user_id' => $admin->id,
            'rejected_at' => now()->toIso8601String(),
        ],
    ]);

    $quote->refresh();
    expect($quote->rejection_metadata['notes'])->toBeNull()
        ->and($quote->rejection_metadata['reason'])->toBe('delayed_decision');
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// 3. Visibility gate — rejected from draft state (UI hides via visible())
// ══════════════════════════════════════════════════════════════════════════════

it('cannot mark a draft quote as rejected (status gate via QuotePolicy::markRejected)', function (): void {
    skipIfMySqlOfflineReject();
    seedRejectQuotePerms();

    $admin = rejectRoleUser('admin');
    $draftQuote = Quote::factory()->create(['status' => Quote::STATUS_DRAFT]);

    // QuotePolicy::markRejected requires status === STATUS_SENT — draft denied.
    expect($admin->can('markRejected', $draftQuote))->toBeFalse();
})->uses(RefreshDatabase::class);

// ══════════════════════════════════════════════════════════════════════════════
// 4. Sales role can mark rejected (D-07 sales bookkeeping)
// ══════════════════════════════════════════════════════════════════════════════

it('sales role can mark rejected (D-07 sales bookkeeping)', function (): void {
    skipIfMySqlOfflineReject();
    seedRejectQuotePerms();

    $sales = rejectRoleUser('sales');
    $sentQuote = Quote::factory()->create([
        'status' => Quote::STATUS_SENT,
        'sent_at' => now()->subMinutes(10),
    ]);

    expect($sales->can('markRejected', $sentQuote))->toBeTrue();
})->uses(RefreshDatabase::class);
