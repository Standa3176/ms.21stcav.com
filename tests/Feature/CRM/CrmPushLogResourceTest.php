<?php

declare(strict_types=1);

use App\Domain\CRM\Filament\Resources\CrmPushLogResource;
use App\Domain\CRM\Filament\Resources\CrmPushLogResource\Pages\ListCrmPushLogs;
use App\Domain\CRM\Filament\Resources\CrmPushLogResource\Pages\ViewCrmPushLog;
use App\Foundation\Integration\Models\IntegrationEvent;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 04 Task 1 — CrmPushLogResource feature tests
|--------------------------------------------------------------------------
|
| Covers: channel='bitrix' scoping, read-only (no create/edit/delete),
| admin + sales access, read_only denial.
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    foreach (['view_any_crm::push::log', 'view_crm::push::log'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    Role::findByName('admin')->givePermissionTo(['view_any_crm::push::log', 'view_crm::push::log']);
    Role::findByName('sales')->givePermissionTo(['view_any_crm::push::log', 'view_crm::push::log']);
});

function makeIntegrationEvent(string $channel, string $status = 'success', string $operation = 'crm.deal.add'): IntegrationEvent
{
    $e = new IntegrationEvent;
    $e->channel = $channel;
    $e->direction = 'outbound';
    $e->operation = $operation;
    $e->endpoint = $operation;
    $e->method = 'POST';
    $e->correlation_id = (string) \Illuminate\Support\Str::uuid();
    $e->status = $status;
    $e->http_status = $status === 'success' ? 200 : 500;
    $e->latency_ms = 42;
    $e->attempt = 1;
    $e->created_at = now();
    $e->save();

    return $e;
}

it('filters integration_events to channel=bitrix', function (): void {
    $bitrix1 = makeIntegrationEvent('bitrix');
    $bitrix2 = makeIntegrationEvent('bitrix', 'failed');
    $woo = makeIntegrationEvent('woo');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCrmPushLogs::class)
        ->assertCanSeeTableRecords([$bitrix1, $bitrix2])
        ->assertCanNotSeeTableRecords([$woo]);
});

it('is read-only — canCreate returns false for all roles', function (): void {
    expect(CrmPushLogResource::canCreate())->toBeFalse();
});

it('admin can access the list', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCrmPushLogs::class)->assertSuccessful();
});

it('sales role can view the push log (CrmPushLogPolicy admin OR sales)', function (): void {
    $sales = User::factory()->create();
    $sales->assignRole('sales');

    expect($sales->can('viewAny', IntegrationEvent::class))->toBeTrue();
});

it('read_only role cannot view the push log', function (): void {
    $readOnly = User::factory()->create();
    $readOnly->assignRole('read_only');

    expect($readOnly->can('viewAny', IntegrationEvent::class))->toBeFalse();
});

it('pricing_manager role cannot view the push log', function (): void {
    $pm = User::factory()->create();
    $pm->assignRole('pricing_manager');

    expect($pm->can('viewAny', IntegrationEvent::class))->toBeFalse();
});

it('admin can open the View page for a bitrix row', function (): void {
    $row = makeIntegrationEvent('bitrix');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ViewCrmPushLog::class, ['record' => $row->getKey()])->assertSuccessful();
});

it('mutations denied for admin AND sales via CrmPushLogPolicy', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $sales = User::factory()->create();
    $sales->assignRole('sales');

    $event = makeIntegrationEvent('bitrix');

    foreach ([$admin, $sales] as $user) {
        expect($user->can('create', IntegrationEvent::class))->toBeFalse();
        expect($user->can('update', $event))->toBeFalse();
        expect($user->can('delete', $event))->toBeFalse();
    }
});
