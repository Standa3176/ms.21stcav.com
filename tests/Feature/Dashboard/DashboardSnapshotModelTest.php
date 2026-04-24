<?php

declare(strict_types=1);

use App\Domain\Dashboard\Models\DashboardSnapshot;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Phase 7 Plan 01 Task 2 — DashboardSnapshot Eloquent model
|--------------------------------------------------------------------------
|
| Covers:
|   - JSON cast to array (metric_value_json round-trips)
|   - Unique metric_key constraint (second insert throws)
|   - computed_at useCurrent default + explicit override
|   - upsertByKey semantics (updateOrCreate by metric_key)
|   - isStale() respects config('dashboard.snapshot_ttl_minutes')
*/

it('casts metric_value_json to an array round-trip', function (): void {
    $snapshot = DashboardSnapshot::create([
        'metric_key' => 'round_trip_test',
        'metric_value_json' => ['count' => 5, 'label' => 'foo'],
    ]);

    $snapshot->refresh();

    expect($snapshot->metric_value_json)->toBe(['count' => 5, 'label' => 'foo']);
});

it('enforces metric_key uniqueness at the DB level', function (): void {
    DashboardSnapshot::create([
        'metric_key' => 'duplicate_key',
        'metric_value_json' => ['count' => 1],
    ]);

    expect(fn () => DashboardSnapshot::create([
        'metric_key' => 'duplicate_key',
        'metric_value_json' => ['count' => 2],
    ]))->toThrow(QueryException::class);
});

it('defaults computed_at via useCurrent but respects explicit override', function (): void {
    // Default path — no computed_at in payload.
    $defaulted = DashboardSnapshot::create([
        'metric_key' => 'default_time',
        'metric_value_json' => ['count' => 1],
    ]);
    $defaulted->refresh();
    expect($defaulted->computed_at)->not->toBeNull();

    // Explicit override.
    $explicit = DashboardSnapshot::create([
        'metric_key' => 'explicit_time',
        'metric_value_json' => ['count' => 2],
        'computed_at' => now()->subHours(2),
    ]);
    $explicit->refresh();
    expect($explicit->computed_at->diffInMinutes(now()))->toBeGreaterThanOrEqual(60);
});

it('upsertByKey creates on first call and updates on second call', function (): void {
    $a = DashboardSnapshot::upsertByKey('parity_pct', ['value' => 98.5]);
    $aId = $a->id;

    $b = DashboardSnapshot::upsertByKey('parity_pct', ['value' => 99.2]);

    // Same row — upsert semantics, not a fresh insert.
    expect($b->id)->toBe($aId);
    expect($b->metric_value_json)->toBe(['value' => 99.2]);
    expect(DashboardSnapshot::where('metric_key', 'parity_pct')->count())->toBe(1);
});

it('isStale returns false for a fresh snapshot and true for an aged one', function (): void {
    config()->set('dashboard.snapshot_ttl_minutes', 15);

    $fresh = DashboardSnapshot::factory()->fresh()->create();
    expect($fresh->isStale())->toBeFalse();

    $stale = DashboardSnapshot::factory()->stale()->create();
    expect($stale->isStale())->toBeTrue();
});

it('isStale reads the configurable TTL window', function (): void {
    $snapshot = DashboardSnapshot::factory()->create([
        'computed_at' => now()->subMinutes(20),
    ]);

    // TTL 10 minutes → 20-min-old snapshot is stale.
    config()->set('dashboard.snapshot_ttl_minutes', 10);
    expect($snapshot->isStale())->toBeTrue();

    // TTL 60 minutes → same 20-min-old snapshot is still fresh.
    config()->set('dashboard.snapshot_ttl_minutes', 60);
    expect($snapshot->isStale())->toBeFalse();
});
