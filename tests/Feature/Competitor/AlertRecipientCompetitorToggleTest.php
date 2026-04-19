<?php

declare(strict_types=1);

use App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages\CreateAlertRecipient;
use App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages\EditAlertRecipient;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Models\User;
use Livewire\Livewire;

/**
 * Phase 5 Plan 04a Task 2 — receives_competitor_alerts toggle persistence.
 *
 * Verifies the Filament form Toggle added to AlertRecipientResource persists
 * the boolean to the column added in Plan 05-01. Admin-only access via
 * AlertRecipientPolicy (no change from Phase 4).
 */
beforeEach(function (): void {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);
});

it('saves receives_competitor_alerts=true when admin creates an AlertRecipient with the toggle on', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test(CreateAlertRecipient::class)
        ->fillForm([
            'email' => 'new-comp-recipient@example.test',
            'name' => 'Comp Recipient',
            'is_active' => true,
            'receives_sync_reports' => false,
            'receives_crm_alerts' => false,
            'receives_competitor_alerts' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('alert_recipients', [
        'email' => 'new-comp-recipient@example.test',
        'receives_competitor_alerts' => true,
    ]);
});

it('defaults receives_competitor_alerts to false when not toggled on', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test(CreateAlertRecipient::class)
        ->fillForm([
            'email' => 'no-comp@example.test',
            'name' => 'Plain',
            'is_active' => true,
            // receives_competitor_alerts left at default (false)
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('alert_recipients', [
        'email' => 'no-comp@example.test',
        'receives_competitor_alerts' => false,
    ]);
});

it('admin can flip receives_competitor_alerts from false to true on edit', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $recipient = AlertRecipient::create([
        'email' => 'flip@example.test',
        'name' => 'Flip',
        'is_active' => true,
        'receives_sync_reports' => false,
        'receives_crm_alerts' => false,
        'receives_competitor_alerts' => false,
    ]);

    Livewire::actingAs($admin)
        ->test(EditAlertRecipient::class, ['record' => $recipient->getKey()])
        ->fillForm(['receives_competitor_alerts' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $recipient->refresh();
    expect($recipient->receives_competitor_alerts)->toBeTrue();
});
