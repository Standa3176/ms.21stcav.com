<?php

declare(strict_types=1);

use App\Domain\Agents\Filament\Resources\AgentRunResource;
use App\Domain\Agents\Filament\Resources\AgentRunResource\Pages\ListAgentRuns;
use App\Domain\Agents\Filament\Resources\AgentRunResource\Pages\ViewAgentRun;
use App\Domain\Agents\Models\AgentRun;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 8 Plan 04 (AGNT-13) — AgentRunResource policy + read-only tests
|--------------------------------------------------------------------------
|
| AgentRunResource is admin-only and read-only. AgentRunPolicy (Plan 01) is
| the load-bearing layer; Shield permissions (Plan 04 manual) are the belt.
| Policy denies create/update/delete/restore/forceDelete unconditionally.
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    foreach (['view_any_agent_run', 'view_agent_run'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    Role::findByName('admin')->givePermissionTo(['view_any_agent_run', 'view_agent_run']);
});

it('canCreate returns false (read-only Resource)', function (): void {
    expect(AgentRunResource::canCreate())->toBeFalse();
});

it('admin can access the list page', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListAgentRuns::class)->assertSuccessful();
});

it('admin can access an AgentRun detail view', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $run = AgentRun::factory()->create();

    Livewire::test(ViewAgentRun::class, ['record' => $run->id])->assertSuccessful();
});

it('non-admin user is denied list access (sales role)', function (): void {
    $sales = User::factory()->create();
    $sales->assignRole('sales');
    $this->actingAs($sales);

    // Filament returns a 403 / forbidden when policy denies; Livewire test
    // assertForbidden checks the same. AgentRunPolicy::viewAny returns false
    // for any non-admin user.
    Livewire::test(ListAgentRuns::class)->assertForbidden();
});

it('non-admin user is denied detail view (read_only role)', function (): void {
    $readOnly = User::factory()->create();
    $readOnly->assignRole('read_only');
    $this->actingAs($readOnly);

    $run = AgentRun::factory()->create();

    Livewire::test(ViewAgentRun::class, ['record' => $run->id])->assertForbidden();
});

it('AgentRunPolicy denies create/update/delete for any role', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $policy = new \App\Domain\Agents\Policies\AgentRunPolicy;
    $run = AgentRun::factory()->create();

    expect($policy->create($admin))->toBeFalse();
    expect($policy->update($admin, $run))->toBeFalse();
    expect($policy->delete($admin, $run))->toBeFalse();
    expect($policy->deleteAny($admin))->toBeFalse();
    expect($policy->restore($admin, $run))->toBeFalse();
    expect($policy->forceDelete($admin, $run))->toBeFalse();
});

it('AgentRunPolicy grants viewAny + view to admin only', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $sales = User::factory()->create();
    $sales->assignRole('sales');

    $policy = new \App\Domain\Agents\Policies\AgentRunPolicy;
    $run = AgentRun::factory()->create();

    expect($policy->viewAny($admin))->toBeTrue();
    expect($policy->view($admin, $run))->toBeTrue();
    expect($policy->viewAny($sales))->toBeFalse();
    expect($policy->view($sales, $run))->toBeFalse();
});
