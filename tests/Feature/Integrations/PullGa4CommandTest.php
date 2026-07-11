<?php

declare(strict_types=1);

use App\Domain\Integrations\Clients\GoogleAnalyticsClient;
use App\Domain\Integrations\Models\GaChannelMetric;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Phase 15 Plan 15a-02 Task 3 — google:pull-ga4 command
|--------------------------------------------------------------------------
|
| The command maps GoogleAnalyticsClient::fetchChannelMetrics() rows into
| GaChannelMetric via updateOrCreate on the grain (idempotent). Money maps
| purchase_revenue (float) → purchase_revenue_pennies via (int) round(*100).
|
| Hard requirement: when fetchChannelMetrics() returns [] (GA4 unconfigured
| OR genuinely no rows) the command logs + exits 0 — NEVER errors. This is
| what makes it safe to schedule in prod before credentials exist.
|
| The client is swapped for a partial mock bound into the container so no
| network / no real GA4 is touched (15a-01's established seam).
*/

/**
 * Bind a GoogleAnalyticsClient partial mock whose fetchChannelMetrics()
 * returns the supplied rows, into the container so the command resolves it.
 */
function bindFakeGaClient(array $rows): void
{
    $mock = Mockery::mock(GoogleAnalyticsClient::class)->makePartial();
    $mock->shouldReceive('fetchChannelMetrics')->andReturn($rows);
    app()->instance(GoogleAnalyticsClient::class, $mock);
}

function gaRow(array $overrides = []): array
{
    return array_merge([
        'date' => '2026-07-10',
        'channel_group' => 'Organic Search',
        'source_medium' => 'google / organic',
        'campaign' => '(not set)',
        'sessions' => 120,
        'key_events' => 8,
        'transactions' => 3,
        'purchase_revenue' => 1234.56,
    ], $overrides);
}

it('exits 0 and writes nothing when fetchChannelMetrics returns [] (unconfigured no-op)', function (): void {
    bindFakeGaClient([]);

    $this->artisan('google:pull-ga4')
        ->assertExitCode(0);

    expect(GaChannelMetric::count())->toBe(0);
});

it('maps money float → integer pennies (1234.56 → 123456)', function (): void {
    bindFakeGaClient([gaRow(['purchase_revenue' => 1234.56])]);

    $this->artisan('google:pull-ga4')->assertExitCode(0);

    $row = GaChannelMetric::firstOrFail();
    expect($row->purchase_revenue_pennies)->toBe(123456);
});

it('persists rows to the snapshot table', function (): void {
    bindFakeGaClient([
        gaRow(['campaign' => '(not set)']),
        gaRow(['channel_group' => 'Paid Search', 'source_medium' => 'google / cpc', 'campaign' => 'summer', 'purchase_revenue' => 0.0]),
    ]);

    $this->artisan('google:pull-ga4')->assertExitCode(0);

    expect(GaChannelMetric::count())->toBe(2)
        ->and(GaChannelMetric::where('channel_group', 'Paid Search')->value('purchase_revenue_pennies'))->toBe(0);
});

it('is idempotent — a re-pull of an existing grain overwrites in place (no dupe)', function (): void {
    // Pre-existing row for the grain from an earlier pull (stale numbers).
    GaChannelMetric::create([
        'date' => '2026-07-10',
        'channel_group' => 'Organic Search',
        'source_medium' => 'google / organic',
        'campaign' => '(not set)',
        'sessions' => 100,
        'key_events' => 5,
        'transactions' => 1,
        'purchase_revenue_pennies' => 1000,
        'pulled_at' => now()->subDay(),
    ]);

    // Fresh pull returns the SAME grain with updated numbers.
    bindFakeGaClient([gaRow(['sessions' => 250, 'key_events' => 9, 'transactions' => 4, 'purchase_revenue' => 20.00])]);

    $this->artisan('google:pull-ga4')->assertExitCode(0);

    expect(GaChannelMetric::count())->toBe(1);
    $row = GaChannelMetric::firstOrFail();
    expect($row->sessions)->toBe(250)
        ->and($row->purchase_revenue_pennies)->toBe(2000);
});

it('re-running with the same rows produces no duplicate rows', function (): void {
    bindFakeGaClient([gaRow()]);

    $this->artisan('google:pull-ga4')->assertExitCode(0);
    $this->artisan('google:pull-ga4')->assertExitCode(0);

    expect(GaChannelMetric::count())->toBe(1);
});

it('--dry-run writes nothing but still exits 0', function (): void {
    bindFakeGaClient([gaRow()]);

    $this->artisan('google:pull-ga4', ['--dry-run' => true])->assertExitCode(0);

    expect(GaChannelMetric::count())->toBe(0);
});

it('accepts --from / --to and passes them through as dates', function (): void {
    $captured = [];
    $mock = Mockery::mock(GoogleAnalyticsClient::class)->makePartial();
    $mock->shouldReceive('fetchChannelMetrics')
        ->andReturnUsing(function (CarbonImmutable $from, CarbonImmutable $to) use (&$captured) {
            $captured = [$from->toDateString(), $to->toDateString()];

            return [];
        });
    app()->instance(GoogleAnalyticsClient::class, $mock);

    $this->artisan('google:pull-ga4', ['--from' => '2026-07-01', '--to' => '2026-07-07'])
        ->assertExitCode(0);

    expect($captured)->toBe(['2026-07-01', '2026-07-07']);
});
