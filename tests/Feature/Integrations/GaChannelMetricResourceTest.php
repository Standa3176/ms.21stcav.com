<?php

declare(strict_types=1);

use App\Domain\Integrations\Filament\Resources\GaChannelMetricResource;
use App\Domain\Integrations\Filament\Resources\GaChannelMetricResource\Pages\ListGaChannelMetrics;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15a-02 Task 4 — GaChannelMetricResource smoke test
|--------------------------------------------------------------------------
|
| READ-ONLY GA4 Channels viewer under the Marketing nav group. Light Filament/
| Livewire smoke: the list page renders for an admin and shows a seeded row;
| the resource is read-only (no create) and mutations are denied for everyone.
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function seedGaChannelMetric(): GaChannelMetric
{
    return GaChannelMetric::create([
        'date' => '2026-07-10',
        'channel_group' => 'Organic Search',
        'source_medium' => 'google / organic',
        'campaign' => '(not set)',
        'sessions' => 120,
        'key_events' => 8,
        'transactions' => 3,
        'purchase_revenue_pennies' => 123456,
        'pulled_at' => now(),
    ]);
}

it('renders the list page for an admin and shows a seeded row', function (): void {
    $row = seedGaChannelMetric();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListGaChannelMetrics::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$row]);
});

it('is read-only — canCreate returns false', function (): void {
    expect(GaChannelMetricResource::canCreate())->toBeFalse();
});

it('any authed workspace user can view; mutations denied for all', function (): void {
    $user = User::factory()->create();

    $row = seedGaChannelMetric();

    expect($user->can('viewAny', GaChannelMetric::class))->toBeTrue()
        ->and($user->can('view', $row))->toBeTrue()
        ->and($user->can('create', GaChannelMetric::class))->toBeFalse()
        ->and($user->can('update', $row))->toBeFalse()
        ->and($user->can('delete', $row))->toBeFalse();
});

it('is registered under the Marketing navigation group', function (): void {
    expect(GaChannelMetricResource::getNavigationGroup())->toBe('Marketing');
});
