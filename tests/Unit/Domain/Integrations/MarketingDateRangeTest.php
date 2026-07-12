<?php

declare(strict_types=1);

use App\Domain\Integrations\Support\MarketingDateRange;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| 260712-mdr Task 1 — MarketingDateRange resolver (single source of truth)
|--------------------------------------------------------------------------
|
| Maps (range, from, to) → concrete [from, to] Y-m-d window that BOTH the
| overview stats and the revenue-trend chart resolve IDENTICALLY. Pure +
| driver-portable (Y-m-d strings only). Rules:
|   7d/30d/90d = trailing N-1 days inclusive of today
|   ytd        = Jan 1 this year → today
|   all        = wide floor (2000-01-01) → today
|   custom     = the two pickers; blank/invalid → fall back to 90d
|   default    = 90d (more useful than 30 given sparse May–Jul data)
*/

beforeEach(function (): void {
    // Freeze "today" so trailing-window maths is deterministic.
    Carbon::setTestNow(Carbon::parse('2026-07-12'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('resolves 7d to a trailing 7-day window inclusive of today', function (): void {
    $r = MarketingDateRange::resolve('7d');

    expect($r->from)->toBe('2026-07-06')   // today - 6 days
        ->and($r->to)->toBe('2026-07-12')  // today
        ->and($r->range)->toBe('7d');
});

it('resolves 30d to a trailing 30-day window inclusive of today', function (): void {
    $r = MarketingDateRange::resolve('30d');

    expect($r->from)->toBe('2026-06-13')   // today - 29 days
        ->and($r->to)->toBe('2026-07-12');
});

it('resolves 90d to a trailing 90-day window inclusive of today', function (): void {
    $r = MarketingDateRange::resolve('90d');

    expect($r->from)->toBe('2026-04-14')   // today - 89 days
        ->and($r->to)->toBe('2026-07-12');
});

it('resolves ytd to Jan 1 of this year through today', function (): void {
    $r = MarketingDateRange::resolve('ytd');

    expect($r->from)->toBe('2026-01-01')
        ->and($r->to)->toBe('2026-07-12')
        ->and($r->range)->toBe('ytd');
});

it('resolves all to a wide floor through today', function (): void {
    $r = MarketingDateRange::resolve('all');

    expect($r->from)->toBe('2000-01-01')
        ->and($r->to)->toBe('2026-07-12')
        ->and($r->range)->toBe('all');
});

it('resolves custom to the two pickers when both are valid Y-m-d', function (): void {
    $r = MarketingDateRange::resolve('custom', '2026-05-01', '2026-06-15');

    expect($r->from)->toBe('2026-05-01')
        ->and($r->to)->toBe('2026-06-15')
        ->and($r->range)->toBe('custom');
});

it('swaps custom from/to when given in reverse order', function (): void {
    $r = MarketingDateRange::resolve('custom', '2026-06-15', '2026-05-01');

    expect($r->from)->toBe('2026-05-01')
        ->and($r->to)->toBe('2026-06-15');
});

it('falls back to 90d when custom is missing a bound', function (): void {
    $r = MarketingDateRange::resolve('custom', '2026-05-01', null);

    expect($r->range)->toBe('90d')
        ->and($r->from)->toBe('2026-04-14')
        ->and($r->to)->toBe('2026-07-12');
});

it('falls back to 90d when custom bounds are blank', function (): void {
    $r = MarketingDateRange::resolve('custom', '', '   ');

    expect($r->range)->toBe('90d')
        ->and($r->from)->toBe('2026-04-14');
});

it('falls back to 90d when a custom bound is not a valid date', function (): void {
    $r = MarketingDateRange::resolve('custom', '2026-13-40', '2026-06-15');

    expect($r->range)->toBe('90d')
        ->and($r->from)->toBe('2026-04-14');
});

it('defaults to 90d for a null range', function (): void {
    $r = MarketingDateRange::resolve(null);

    expect($r->range)->toBe('90d')
        ->and($r->from)->toBe('2026-04-14')
        ->and($r->to)->toBe('2026-07-12');
});

it('defaults to 90d for an unknown range key', function (): void {
    $r = MarketingDateRange::resolve('bananas');

    expect($r->range)->toBe('90d');
});

it('exposes a human label for each preset and a range for custom', function (): void {
    expect(MarketingDateRange::resolve('7d')->label)->toBe('Last 7 days')
        ->and(MarketingDateRange::resolve('ytd')->label)->toBe('This year')
        ->and(MarketingDateRange::resolve('all')->label)->toBe('All time')
        ->and(MarketingDateRange::resolve('custom', '2026-05-01', '2026-06-15')->label)
        ->toBe('2026-05-01 → 2026-06-15');
});

it('publishes the preset option map for the filters Select', function (): void {
    expect(MarketingDateRange::options())->toBe([
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'ytd' => 'This year',
        'all' => 'All time',
        'custom' => 'Custom',
    ]);
});
