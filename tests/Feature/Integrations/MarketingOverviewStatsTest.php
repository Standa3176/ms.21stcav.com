<?php

declare(strict_types=1);

use App\Domain\Integrations\Filament\Widgets\MarketingOverviewStats;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-02 Task 2 — MarketingOverviewStats widget
|--------------------------------------------------------------------------
|
| PURE PRESENTATION over ga_channel_metrics_daily. Verifies driver-portable
| SUM aggregates + top-channel-by-revenue, and the hard empty-state requirement
| (zero rows → 0 / £0.00 / — with no error, no divide-by-zero).
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function seedGaRow(array $overrides = []): GaChannelMetric
{
    return GaChannelMetric::create(array_merge([
        'date' => now()->subDays(2)->toDateString(),
        'channel_group' => 'Organic Search',
        'source_medium' => 'google / organic',
        'campaign' => '(not set)',
        'sessions' => 100,
        'key_events' => 5,
        'transactions' => 2,
        'purchase_revenue_pennies' => 50000,
        'pulled_at' => now(),
    ], $overrides));
}

function marketingAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    test()->actingAs($admin);

    return $admin;
}

it('renders and sums sessions/transactions/revenue with the top channel by revenue', function (): void {
    marketingAdmin();

    // Two channels — Paid Search has the higher revenue → top channel.
    seedGaRow(['channel_group' => 'Organic Search', 'sessions' => 100, 'transactions' => 2, 'purchase_revenue_pennies' => 50000]);
    seedGaRow(['channel_group' => 'Paid Search', 'sessions' => 300, 'transactions' => 5, 'purchase_revenue_pennies' => 150000]);

    Livewire::test(MarketingOverviewStats::class)
        ->assertSuccessful()
        ->assertSee('400')          // 100 + 300 sessions
        ->assertSee('7')            // 2 + 5 transactions
        ->assertSee('£2,000.00')    // (50000 + 150000) / 100
        ->assertSee('Paid Search'); // higher-revenue channel
});

it('renders a friendly empty-state with zero rows (no error, no divide-by-zero)', function (): void {
    marketingAdmin();

    Livewire::test(MarketingOverviewStats::class)
        ->assertSuccessful()
        ->assertSee('£0.00')
        ->assertSee('—');
});

it('excludes rows outside the 30-day window from the aggregates', function (): void {
    marketingAdmin();

    seedGaRow(['date' => now()->subDays(90)->toDateString(), 'sessions' => 999, 'purchase_revenue_pennies' => 999999]);

    Livewire::test(MarketingOverviewStats::class)
        ->assertSuccessful()
        ->assertSee('£0.00')       // the old row is outside the window
        ->assertDontSee('999');
});

it('is visible to any authed workspace user', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    expect(MarketingOverviewStats::canView())->toBeTrue();
});
