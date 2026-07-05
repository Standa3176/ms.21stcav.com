<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Resources\CompetitorResource;
use Carbon\Carbon;

/**
 * Quick task 260705-pw3 — PURE colour rule for the Competitor Feeds
 * "Last Ingest" column. RED (danger) when a competitor is behind the latest
 * feed run (null, or > $lagHours older than the newest active ingest),
 * GREEN (success) when it arrived with the latest run, GRAY when there's no
 * reference yet. No DB — Carbon fixtures only.
 */

it('returns gray when there is no reference run', function (): void {
    expect(CompetitorResource::freshnessColorFor(
        Carbon::parse('2026-07-05 09:00:00'),
        null,
        24,
    ))->toBe('gray');
});

it('returns danger when the reference exists but the feed never ingested', function (): void {
    expect(CompetitorResource::freshnessColorFor(
        null,
        Carbon::parse('2026-07-05 09:00:00'),
        24,
    ))->toBe('danger');
});

it('returns success when the feed arrived exactly with the latest run', function (): void {
    $latest = Carbon::parse('2026-07-05 09:00:00');

    expect(CompetitorResource::freshnessColorFor($latest->copy(), $latest, 24))
        ->toBe('success');
});

it('returns success when the feed is within the lag tolerance (2h behind, lag 24)', function (): void {
    $latest = Carbon::parse('2026-07-05 09:00:00');

    expect(CompetitorResource::freshnessColorFor($latest->copy()->subHours(2), $latest, 24))
        ->toBe('success');
});

it('returns danger when the feed missed the run (30h behind, lag 24)', function (): void {
    $latest = Carbon::parse('2026-07-05 09:00:00');

    expect(CompetitorResource::freshnessColorFor($latest->copy()->subHours(30), $latest, 24))
        ->toBe('danger');
});

it('treats the exact lag boundary as success (24h behind, lag 24 — strict lt)', function (): void {
    $latest = Carbon::parse('2026-07-05 09:00:00');

    expect(CompetitorResource::freshnessColorFor($latest->copy()->subHours(24), $latest, 24))
        ->toBe('success');
});

it('with lag 0, any feed strictly before the latest run is danger', function (): void {
    $latest = Carbon::parse('2026-07-05 09:00:00');

    expect(CompetitorResource::freshnessColorFor($latest->copy()->subSecond(), $latest, 0))
        ->toBe('danger');
});

it('with lag 0, a feed equal to the latest run is success', function (): void {
    $latest = Carbon::parse('2026-07-05 09:00:00');

    expect(CompetitorResource::freshnessColorFor($latest->copy(), $latest, 0))
        ->toBe('success');
});
