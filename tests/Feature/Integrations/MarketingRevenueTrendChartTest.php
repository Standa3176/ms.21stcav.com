<?php

declare(strict_types=1);

use App\Domain\Integrations\Filament\Widgets\MarketingRevenueTrendChart;
use App\Domain\Integrations\Models\GaChannelMetric;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15b-02 Task 3 — MarketingRevenueTrendChart widget
|--------------------------------------------------------------------------
|
| PURE PRESENTATION over ga_channel_metrics_daily. Driver-portable daily
| revenue (£ from pennies) grouped by date; hard empty-state requirement
| (zero rows → empty dataset, no error).
*/

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function seedRevenueRow(string $date, int $pennies, string $channel = 'Paid Search'): GaChannelMetric
{
    return GaChannelMetric::create([
        'date' => $date,
        'channel_group' => $channel,
        'source_medium' => 'google / cpc',
        'campaign' => 'Brand UK',
        'sessions' => 10,
        'key_events' => 1,
        'transactions' => 1,
        'purchase_revenue_pennies' => $pennies,
        'pulled_at' => now(),
    ]);
}

/** getData() is protected — read it via reflection. */
function chartData(MarketingRevenueTrendChart $chart): array
{
    $m = new ReflectionMethod($chart, 'getData');
    $m->setAccessible(true);

    return $m->invoke($chart);
}

it('builds a daily revenue dataset (£ from pennies), one point per date, summed across channels', function (): void {
    $d1 = now()->subDays(3)->toDateString();
    $d2 = now()->subDays(1)->toDateString();

    // Two channels on the same day should be summed into one point.
    seedRevenueRow($d1, 50000, 'Organic Search');
    seedRevenueRow($d1, 25000, 'Paid Search');
    seedRevenueRow($d2, 100000, 'Paid Search');

    $data = chartData(new MarketingRevenueTrendChart);

    expect($data['labels'])->toBe([$d1, $d2])
        ->and($data['datasets'])->toHaveCount(1)
        ->and($data['datasets'][0]['data'])->toBe([750.0, 1000.0]); // (50000+25000)/100, 100000/100
});

it('returns an empty dataset with zero rows (no error)', function (): void {
    $data = chartData(new MarketingRevenueTrendChart);

    expect($data)->toBe(['datasets' => [], 'labels' => []]);
});

it('renders successfully as a Livewire widget for an admin', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    test()->actingAs($admin);

    seedRevenueRow(now()->subDays(1)->toDateString(), 100000);

    Livewire::test(MarketingRevenueTrendChart::class)->assertSuccessful();
});
