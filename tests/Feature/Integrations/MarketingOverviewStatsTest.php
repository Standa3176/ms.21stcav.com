<?php

declare(strict_types=1);

use App\Domain\Integrations\Filament\Pages\MarketingDashboardPage;
use App\Domain\Integrations\Filament\Widgets\MarketingOverviewStats;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Models\User;
use Illuminate\Support\Carbon;
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

it('excludes rows outside the default 90-day window from the aggregates', function (): void {
    marketingAdmin();

    // 100 days ago is outside the default 90d window (today-89 .. today).
    seedGaRow(['date' => now()->subDays(100)->toDateString(), 'sessions' => 999, 'purchase_revenue_pennies' => 999999]);

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

/*
|--------------------------------------------------------------------------
| 260712-mdr Task 3 — per-range window correctness (the meat)
|--------------------------------------------------------------------------
|
| Seed rows across a spread of dates, drive the page filter, and assert the
| tiles reflect ONLY the selected window via the shared MarketingDateRange
| resolver.
*/

it('aggregates ONLY the rows inside the selected 7d window', function (): void {
    marketingAdmin();
    Carbon::setTestNow(Carbon::parse('2026-07-12'));

    // Inside 7d (today-6 .. today).
    seedGaRow(['date' => '2026-07-10', 'sessions' => 40, 'transactions' => 1, 'purchase_revenue_pennies' => 10000]);
    // Outside 7d but inside 90d — must be excluded when 7d is chosen.
    seedGaRow(['date' => '2026-06-01', 'sessions' => 900, 'transactions' => 8, 'purchase_revenue_pennies' => 880000]);

    Livewire::test(MarketingOverviewStats::class, ['filters' => ['range' => '7d']])
        ->assertSuccessful()
        ->assertSee('40')        // only the in-window session count
        ->assertSee('£100.00')   // 10000 / 100
        ->assertDontSee('940')   // NOT 40 + 900
        ->assertDontSee('£8,900.00');

    Carbon::setTestNow();
});

it('widens to include the same rows when the 90d range is selected', function (): void {
    marketingAdmin();
    Carbon::setTestNow(Carbon::parse('2026-07-12'));

    seedGaRow(['date' => '2026-07-10', 'sessions' => 40, 'transactions' => 1, 'purchase_revenue_pennies' => 10000]);
    seedGaRow(['date' => '2026-06-01', 'sessions' => 900, 'transactions' => 8, 'purchase_revenue_pennies' => 880000]);

    Livewire::test(MarketingOverviewStats::class, ['filters' => ['range' => '90d']])
        ->assertSuccessful()
        ->assertSee('940')          // 40 + 900 sessions now both in-window
        ->assertSee('£8,900.00');   // (10000 + 880000) / 100

    Carbon::setTestNow();
});

it('honours a custom from/to window', function (): void {
    marketingAdmin();
    Carbon::setTestNow(Carbon::parse('2026-07-12'));

    seedGaRow(['date' => '2026-05-15', 'sessions' => 70, 'transactions' => 2, 'purchase_revenue_pennies' => 20000]);
    // Just outside the custom upper bound → excluded.
    seedGaRow(['date' => '2026-06-20', 'sessions' => 500, 'transactions' => 9, 'purchase_revenue_pennies' => 500000]);

    Livewire::test(MarketingOverviewStats::class, ['filters' => ['range' => 'custom', 'from' => '2026-05-01', 'to' => '2026-05-31']])
        ->assertSuccessful()
        ->assertSee('70')
        ->assertSee('£200.00')      // 20000 / 100 — in-window only
        ->assertDontSee('£5,000.00'); // the out-of-window row's revenue (500000)

    Carbon::setTestNow();
});

it('propagates the selected range from the page to the header widget data', function (): void {
    marketingAdmin();
    Carbon::setTestNow(Carbon::parse('2026-07-12'));

    // The header widgets are lazy-loaded, so assert the page hands them the
    // chosen range via getWidgetData() (the seam the reactive #[Reactive]
    // `filters` prop consumes); the direct-mount tests above prove the widget
    // then resolves that range correctly.
    $page = Livewire::test(MarketingDashboardPage::class)->assertSuccessful();

    expect($page->instance()->getWidgetData()['filters']['range'])->toBe('90d');

    $page->set('filters.range', '7d');

    expect($page->instance()->getWidgetData()['filters']['range'])->toBe('7d');

    Carbon::setTestNow();
});
