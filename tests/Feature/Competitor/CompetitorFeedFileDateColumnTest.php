<?php

declare(strict_types=1);

use App\Domain\Competitor\Filament\Resources\CompetitorResource;
use App\Domain\Competitor\Models\Competitor;
use App\Domain\Competitor\Models\CompetitorFtpFeed;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Quick task 260706-pfy — the NEW 'Feed file date' column on the Competitor
 * Feeds list shows the newest remote_file_date per competitor, coloured with
 * the pw3 behind-the-latest-run rule. These tests guard the NEW reference
 * query (latestActiveFeedFileDate) — max over ACTIVE competitors' feeds only,
 * ignoring inactive competitors — plus the danger/success/null-feed colour
 * paths (the pure colour rule itself is unit-tested in 260705-pw3).
 *
 * Fixed clock so 04:00/03:00 vs >24h-behind are deterministic.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-06 05:05:00'));

    // Fresh request between tests — reset the resource's memoised reference so
    // one test's cached value can't leak into the next.
    $reset = function (string $prop, $value): void {
        $ref = new ReflectionProperty(CompetitorResource::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    };
    $reset('latestActiveFeedFileDateMemo', null);
    $reset('latestActiveFeedFileDateLoaded', false);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('latestActiveFeedFileDate = newest remote_file_date over ACTIVE competitors, ignoring inactive', function (): void {
    $today = Carbon::parse('2026-07-06');

    // A — newest active feed file (today 04:00) → this is the reference.
    $a = Competitor::factory()->create();
    CompetitorFtpFeed::factory()->for($a)->create([
        'remote_file_date' => $today->copy()->setTime(4, 0),
    ]);

    // B — today 03:00 (within 24h of A → success).
    $b = Competitor::factory()->create();
    CompetitorFtpFeed::factory()->for($b)->create([
        'remote_file_date' => $today->copy()->setTime(3, 0),
    ]);

    // C — > 24h behind A → danger.
    $c = Competitor::factory()->create();
    CompetitorFtpFeed::factory()->for($c)->create([
        'remote_file_date' => $today->copy()->setTime(4, 0)->subHours(30),
    ]);

    // INACTIVE competitor with a very recent feed — MUST NOT raise the reference.
    $inactive = Competitor::factory()->inactive()->create();
    CompetitorFtpFeed::factory()->for($inactive)->create([
        'remote_file_date' => $today->copy()->setTime(4, 30),
    ]);

    $reference = CompetitorResource::latestActiveFeedFileDate();

    expect($reference)->not->toBeNull()
        ->and($reference->equalTo($today->copy()->setTime(4, 0)))->toBeTrue();
});

it('colours a >24h-behind feed danger, a within-24h feed success, and a null feed danger', function (): void {
    $today = Carbon::parse('2026-07-06');

    $a = Competitor::factory()->create();
    CompetitorFtpFeed::factory()->for($a)->create([
        'remote_file_date' => $today->copy()->setTime(4, 0),
    ]);

    $bDate = $today->copy()->setTime(3, 0);
    $cDate = $today->copy()->setTime(4, 0)->subHours(30);

    $reference = CompetitorResource::latestActiveFeedFileDate();
    $lag = (int) config('competitor.last_run_lag_hours', 24);

    // C — 30h behind the newest → danger.
    expect(CompetitorResource::freshnessColorFor($cDate, $reference, $lag))->toBe('danger');

    // B — 1h behind (within 24h) → success.
    expect(CompetitorResource::freshnessColorFor($bDate, $reference, $lag))->toBe('success');

    // No feed file at all → null → danger.
    expect(CompetitorResource::freshnessColorFor(null, $reference, $lag))->toBe('danger');
});
