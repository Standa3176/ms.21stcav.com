<?php

declare(strict_types=1);

use App\Domain\Integrations\Filament\Pages\MarketingDashboardPage;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-02 Tasks 1 & 5 — MarketingDashboardPage render + empty-state
|--------------------------------------------------------------------------
|
| PURE PRESENTATION. The page renders for an authed workspace user; the
| empty-state callout is present with ZERO GA4 rows and absent once rows exist.
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function seedDashboardGaRow(): GaChannelMetric
{
    return GaChannelMetric::create([
        'date' => now()->subDays(1)->toDateString(),
        'channel_group' => 'Paid Search',
        'source_medium' => 'google / cpc',
        'campaign' => 'Brand UK',
        'sessions' => 100,
        'key_events' => 5,
        'transactions' => 2,
        'purchase_revenue_pennies' => 120000,
        'pulled_at' => now(),
    ]);
}

function loginDashboardAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    test()->actingAs($admin);

    return $admin;
}

it('renders the page for an admin', function (): void {
    loginDashboardAdmin();

    Livewire::test(MarketingDashboardPage::class)->assertSuccessful();
});

it('shows the empty-state callout when there are zero GA4 rows', function (): void {
    loginDashboardAdmin();

    Livewire::test(MarketingDashboardPage::class)
        ->assertSuccessful()
        ->assertSee('No Google Analytics 4 data yet')
        ->assertSee('Integration Credentials');
});

it('hides the empty-state callout once GA4 rows exist', function (): void {
    loginDashboardAdmin();
    seedDashboardGaRow();

    Livewire::test(MarketingDashboardPage::class)
        ->assertSuccessful()
        ->assertDontSee('No Google Analytics 4 data yet');
});

it('is accessible to any authed workspace user and registered under Marketing', function (): void {
    loginDashboardAdmin();

    expect(MarketingDashboardPage::canAccess())->toBeTrue()
        ->and(MarketingDashboardPage::getNavigationGroup())->toBe('Marketing')
        ->and(MarketingDashboardPage::getSlug())->toBe('marketing-dashboard');
});

/*
|--------------------------------------------------------------------------
| 260712-mdr Task 2 — date-range filters form
|--------------------------------------------------------------------------
*/

it('mounts with the default 90d range in the filters form', function (): void {
    loginDashboardAdmin();

    Livewire::test(MarketingDashboardPage::class)
        ->assertSuccessful()
        ->assertSet('filters.range', '90d');
});

it('renders the range preset options in the filters form', function (): void {
    loginDashboardAdmin();

    Livewire::test(MarketingDashboardPage::class)
        ->assertSuccessful()
        ->assertSee('Last 7 days')
        ->assertSee('Last 90 days')
        ->assertSee('This year')
        ->assertSee('All time');
});

it('accepts a range change through the filters form', function (): void {
    loginDashboardAdmin();

    Livewire::test(MarketingDashboardPage::class)
        ->set('filters.range', '7d')
        ->assertSuccessful()
        ->assertSet('filters.range', '7d');
});

it('keeps the "Review with Claude" header action available alongside the filter', function (): void {
    loginDashboardAdmin();

    Livewire::test(MarketingDashboardPage::class)
        ->assertSuccessful()
        ->assertActionExists('review_with_claude');
});
