<?php

declare(strict_types=1);

use App\Domain\Integrations\Models\GaChannelMetric;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15a-02 Task 1 — GaChannelMetric model + migration
|--------------------------------------------------------------------------
|
| ga_channel_metrics_daily is the local snapshot of GA4 channel/campaign
| daily performance. Grain: date × channel_group × source_medium × campaign.
| Money (purchase_revenue) is stored as integer pennies (app convention).
| Driver-portable: the migration must run on SQLite (tests) + MariaDB (prod).
*/

it('creates the ga_channel_metrics_daily table on SQLite', function (): void {
    expect(Schema::hasTable('ga_channel_metrics_daily'))->toBeTrue();

    foreach ([
        'id', 'date', 'channel_group', 'source_medium', 'campaign',
        'sessions', 'key_events', 'transactions', 'purchase_revenue_pennies', 'pulled_at',
    ] as $column) {
        expect(Schema::hasColumn('ga_channel_metrics_daily', $column))->toBeTrue("missing column {$column}");
    }
});

it('round-trips casts (date, integer counts, integer pennies, datetime)', function (): void {
    $row = GaChannelMetric::create([
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

    $fresh = GaChannelMetric::findOrFail($row->id);

    expect($fresh->date)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->date->toDateString())->toBe('2026-07-10')
        ->and($fresh->sessions)->toBe(120)
        ->and($fresh->key_events)->toBe(8)
        ->and($fresh->transactions)->toBe(3)
        ->and($fresh->purchase_revenue_pennies)->toBe(123456)
        ->and($fresh->pulled_at)->toBeInstanceOf(CarbonInterface::class);
});

it('enforces the grain unique key (date × channel_group × source_medium × campaign)', function (): void {
    $attrs = [
        'date' => '2026-07-10',
        'channel_group' => 'Organic Search',
        'source_medium' => 'google / organic',
        'campaign' => 'summer-sale',
        'sessions' => 10,
        'key_events' => 1,
        'transactions' => 0,
        'purchase_revenue_pennies' => 0,
        'pulled_at' => now(),
    ];

    GaChannelMetric::create($attrs);

    expect(fn () => GaChannelMetric::create($attrs))->toThrow(QueryException::class);
});

it('allows the same grain across different dates', function (): void {
    $base = [
        'channel_group' => 'Organic Search',
        'source_medium' => 'google / organic',
        'campaign' => 'summer-sale',
        'sessions' => 10,
        'key_events' => 1,
        'transactions' => 0,
        'purchase_revenue_pennies' => 0,
        'pulled_at' => now(),
    ];

    GaChannelMetric::create(['date' => '2026-07-09'] + $base);
    GaChannelMetric::create(['date' => '2026-07-10'] + $base);

    expect(GaChannelMetric::count())->toBe(2);
});
