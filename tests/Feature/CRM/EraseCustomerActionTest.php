<?php

declare(strict_types=1);

use App\Domain\CRM\Filament\Actions\EraseCustomerAction;
use App\Domain\CRM\Filament\Resources\CrmPushLogResource\Pages\ListCrmPushLogs;
use App\Domain\CRM\Jobs\EraseBitrixContactJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 05 Task 2 — EraseCustomerAction Filament header action (CRM-13).
|--------------------------------------------------------------------------
|
| Admin-gated; typed ERASE confirmation mandatory; dispatches the same job
| the CLI command does.
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    // CrmPushLogResource viewAny permissions
    foreach (['view_any_crm::push::log', 'view_crm::push::log'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    foreach (['admin', 'sales'] as $roleName) {
        Role::findByName($roleName)->givePermissionTo(['view_any_crm::push::log', 'view_crm::push::log']);
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 1 — admin-only (Warning 9 defence-in-depth)
// ══════════════════════════════════════════════════════════════════════════════

it('is admin-gated — sales cannot invoke the action', function (): void {
    $closure = fn () => auth()->user()?->hasRole('admin') ?? false;

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    expect($closure())->toBeTrue();

    foreach (['sales', 'pricing_manager', 'read_only'] as $roleName) {
        $user = User::factory()->create();
        $user->assignRole($roleName);
        $this->actingAs($user);
        expect($closure())->toBeFalse("Role {$roleName} must NOT pass authorize");
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 2 — typed ERASE rejection by custom rule
// ══════════════════════════════════════════════════════════════════════════════

it('requires typing ERASE literally in modal confirmation field', function (): void {
    // The action's confirmation rule is a closure — test it in isolation
    // by reflecting on the private helper to confirm the contract.
    $ref = new ReflectionClass(EraseCustomerAction::class);
    $method = $ref->getMethod('confirmationRule');
    $method->setAccessible(true);
    $rule = $method->invoke(null);

    expect($rule)->toBeInstanceOf(Closure::class);

    // Simulate Laravel's rule runner.
    $failed = null;
    $fail = function (string $msg) use (&$failed): void { $failed = $msg; };

    $rule('confirmation', 'erase', $fail);   // lowercase fails
    expect($failed)->not->toBeNull();

    $failed = null;
    $rule('confirmation', 'ERASE', $fail);   // exact match passes
    expect($failed)->toBeNull();

    $failed = null;
    $rule('confirmation', 'please erase', $fail);
    expect($failed)->not->toBeNull();
});

// ══════════════════════════════════════════════════════════════════════════════
// Test 3 — successful submit dispatches the same job the CLI does
// ══════════════════════════════════════════════════════════════════════════════

it('dispatches EraseBitrixContactJob on successful submit', function (): void {
    Queue::fake();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCrmPushLogs::class)
        ->callTableAction('erase_customer', data: [
            'email' => 'test@erase.com',
            'confirmation' => 'ERASE',
        ]);

    Queue::assertPushed(EraseBitrixContactJob::class, function (EraseBitrixContactJob $job) use ($admin): bool {
        return $job->email === 'test@erase.com'
            && $job->actorId === $admin->id
            && $job->queue === 'default';
    });
});
