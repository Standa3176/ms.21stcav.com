<?php

declare(strict_types=1);

use App\Domain\ProductAutoCreate\Filament\Pages\AutoCreateSettingsPage;
use App\Domain\ProductAutoCreate\Models\AutoCreateSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Livewire\livewire;

/*
|--------------------------------------------------------------------------
| Phase 6 Plan 04 — AutoCreateSettingsPage tests (AUTO-07, D-09)
|--------------------------------------------------------------------------
| Singleton Page, admin-only. Exercises mode/cta/threshold save path +
| denies non-admin access via canAccess + per-save abort_unless.
*/

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->pricingManager = User::factory()->create();
    $this->pricingManager->assignRole('pricing_manager');
});

it('seed migration creates one settings row with draft default', function (): void {
    expect(AutoCreateSetting::count())->toBe(1);
    $row = AutoCreateSetting::current();
    expect($row->mode)->toBe('draft');
    expect($row->completeness_threshold)->toBe(85);
});

it('admin can save mode=immediate_publish', function (): void {
    $this->actingAs($this->admin);

    livewire(AutoCreateSettingsPage::class)
        ->fillForm([
            'mode' => 'immediate_publish',
            'cta' => 'New CTA text',
            'optimize_images' => true,
            'completeness_threshold' => 90,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = AutoCreateSetting::current();
    expect($row->mode)->toBe('immediate_publish');
    expect($row->cta)->toBe('New CTA text');
    expect($row->completeness_threshold)->toBe(90);
});

it('save triggers activity_log entry', function (): void {
    $this->actingAs($this->admin);

    livewire(AutoCreateSettingsPage::class)
        ->fillForm([
            'mode' => 'immediate_publish',
            'cta' => 'Shop now at meetingstore.co.uk',
            'optimize_images' => true,
            'completeness_threshold' => 85,
        ])
        ->call('save');

    expect(
        \Spatie\Activitylog\Models\Activity::query()
            ->where('description', 'auto_create.settings.updated')
            ->exists()
    )->toBeTrue();
});

it('pricing_manager cannot access the settings page', function (): void {
    $this->actingAs($this->pricingManager);

    expect(AutoCreateSettingsPage::canAccess())->toBeFalse();
});

it('admin can access the settings page', function (): void {
    $this->actingAs($this->admin);

    expect(AutoCreateSettingsPage::canAccess())->toBeTrue();
});

it('completeness_threshold validates 0-100 bounds', function (): void {
    $this->actingAs($this->admin);

    livewire(AutoCreateSettingsPage::class)
        ->fillForm([
            'mode' => 'draft',
            'cta' => 'CTA',
            'optimize_images' => false,
            'completeness_threshold' => 150,  // out of bounds
        ])
        ->call('save')
        ->assertHasFormErrors(['completeness_threshold']);
});
