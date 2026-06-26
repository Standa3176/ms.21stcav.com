<?php

declare(strict_types=1);

use App\Domain\Sync\Filament\Resources\SupplierResource;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| Quick task 260626-phz — feed-age helpers (pure, deterministic)
|--------------------------------------------------------------------------
|
| Pins SupplierResource::workingDaysSince / feedAgeColor / feedAgeTooltip
| against a FIXED "now" (Carbon::setTestNow) so weekday counting and the
| 5-working-day colour boundaries are version-robust and non-flaky.
|
| "now" = Friday 2026-06-26. Working days exclude weekends. Colour contract:
|   > 5 working days  → danger   (older than 5 working days)
|   4–5 working days  → warning
|   ≤ 3 working days  → success
|   null (no data)    → gray
*/

beforeEach(function (): void {
    // Friday — see weekday-count table below.
    Carbon::setTestNow('2026-06-26');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts working days from a feed date to now (weekends excluded)', function (string $date, int $expected): void {
    expect(SupplierResource::workingDaysSince(Carbon::parse($date)))->toBe($expected);
})->with([
    'today (Fri 2026-06-26)' => ['2026-06-26', 0],
    'Thu 2026-06-25' => ['2026-06-25', 1],
    'Tue 2026-06-23' => ['2026-06-23', 3],
    'Mon 2026-06-22' => ['2026-06-22', 4],
    'prev Fri 2026-06-19' => ['2026-06-19', 5],
    'prev Thu 2026-06-18' => ['2026-06-18', 6],
    'Fri 2 weeks back 2026-06-12' => ['2026-06-12', 10],
]);

it('returns null working days when the feed date is null', function (): void {
    expect(SupplierResource::workingDaysSince(null))->toBeNull();
});

it('maps working-day age to a colour on the 5-working-day contract', function (?int $workingDays, string $color): void {
    expect(SupplierResource::feedAgeColor($workingDays))->toBe($color);
})->with([
    'null → gray' => [null, 'gray'],
    '0 → success' => [0, 'success'],
    '3 → success (upper success boundary)' => [3, 'success'],
    '4 → warning (lower warning boundary)' => [4, 'warning'],
    '5 → warning (upper warning boundary)' => [5, 'warning'],
    '6 → danger (older than 5 working days)' => [6, 'danger'],
    '10 → danger' => [10, 'danger'],
]);

it('colours the boundary feed dates correctly end-to-end', function (): void {
    // 2026-06-18 (6 working days) MUST be danger; 2026-06-22 (4) MUST be warning.
    expect(SupplierResource::feedAgeColor(SupplierResource::workingDaysSince(Carbon::parse('2026-06-18'))))
        ->toBe('danger');
    expect(SupplierResource::feedAgeColor(SupplierResource::workingDaysSince(Carbon::parse('2026-06-22'))))
        ->toBe('warning');
});

it('renders the working-day tooltip in words', function (?int $workingDays, ?string $tooltip): void {
    expect(SupplierResource::feedAgeTooltip($workingDays))->toBe($tooltip);
})->with([
    'null → no data' => [null, 'No feed data recorded yet'],
    '0 → Today' => [0, 'Today'],
    '1 → singular' => [1, '1 working day ago'],
    '6 → plural' => [6, '6 working days ago'],
]);
