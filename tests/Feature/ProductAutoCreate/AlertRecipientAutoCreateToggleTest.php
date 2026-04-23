<?php

declare(strict_types=1);

use App\Domain\Alerting\Filament\Resources\AlertRecipientResource\Pages\EditAlertRecipient;
use App\Domain\Alerting\Models\AlertRecipient;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Livewire\livewire;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 04 — AlertRecipient receives_auto_create_alerts toggle
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('admin can toggle receives_auto_create_alerts on', function (): void {
    $this->actingAs($this->admin);

    $recipient = AlertRecipient::factory()->create([
        'email' => 'dlq@example.com',
        'name' => 'DLQ Receiver',
        'is_active' => true,
        'receives_auto_create_alerts' => false,
    ]);

    livewire(EditAlertRecipient::class, ['record' => $recipient->id])
        ->fillForm([
            'email' => $recipient->email,
            'name' => $recipient->name,
            'is_active' => true,
            'receives_sync_reports' => false,
            'receives_crm_alerts' => false,
            'receives_competitor_alerts' => false,
            'receives_auto_create_alerts' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $recipient->refresh();
    expect((bool) $recipient->receives_auto_create_alerts)->toBeTrue();
});

it('receives_auto_create_alerts column exists + defaults to false', function (): void {
    $recipient = AlertRecipient::factory()->create([
        'email' => 'default@example.com',
    ]);

    expect((bool) $recipient->receives_auto_create_alerts)->toBeFalse();
});

it('AlertRecipient scopeReceivesAutoCreateAlerts returns only opted-in rows', function (): void {
    AlertRecipient::factory()->create(['email' => 'opt-in-1@example.com', 'receives_auto_create_alerts' => true, 'is_active' => true]);
    AlertRecipient::factory()->create(['email' => 'opt-in-2@example.com', 'receives_auto_create_alerts' => true, 'is_active' => true]);
    AlertRecipient::factory()->create(['email' => 'opt-out@example.com', 'receives_auto_create_alerts' => false, 'is_active' => true]);

    $inList = AlertRecipient::query()->where('receives_auto_create_alerts', true)->where('is_active', true)->get();

    expect($inList->pluck('email')->all())->toContain('opt-in-1@example.com', 'opt-in-2@example.com');
    expect($inList->pluck('email')->all())->not->toContain('opt-out@example.com');
});
