<?php

declare(strict_types=1);

use App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages\EditAlertRecipient;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 4 Plan 04 Task 1 — AlertRecipientResource receives_crm_alerts toggle
|--------------------------------------------------------------------------
|
| Confirms Plan 04-03's receives_crm_alerts column is surfaced in the Filament
| form as an editable Toggle, and saves correctly to the DB column.
*/

beforeEach(function (): void {
    foreach (['admin', 'pricing_manager', 'sales', 'read_only'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    }

    foreach (['view_any_alert::recipient', 'view_alert::recipient', 'update_alert::recipient'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }
    Role::findByName('admin')->givePermissionTo([
        'view_any_alert::recipient',
        'view_alert::recipient',
        'update_alert::recipient',
    ]);
});

it('admin sees the receives_crm_alerts toggle on the edit form and can save it to true', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $recipient = AlertRecipient::create([
        'email' => 'opt-in@example.com',
        'name' => 'Opt-in test',
        'is_active' => true,
        'receives_sync_reports' => false,
        'receives_crm_alerts' => false,
    ]);

    Livewire::test(EditAlertRecipient::class, ['record' => $recipient->getKey()])
        ->fillForm([
            'email' => 'opt-in@example.com',
            'name' => 'Opt-in test',
            'is_active' => true,
            'receives_sync_reports' => false,
            'receives_crm_alerts' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($recipient->fresh()->receives_crm_alerts)->toBeTrue();
});

it('AlertRecipientResource source declares the receives_crm_alerts Toggle + column', function (): void {
    $source = file_get_contents(app_path('Domain/Alerting/Filament/Resources/AlertRecipientResource.php'));

    expect($source)->toContain("Toggle::make('receives_crm_alerts')");
    expect($source)->toContain("IconColumn::make('receives_crm_alerts')");
});
