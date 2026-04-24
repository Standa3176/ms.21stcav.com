<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Models;

use Database\Factories\Domain\Dashboard\DashboardSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7 Plan 01 — D-02 dashboard snapshot.
 *
 * One row per widget metric (identified by metric_key). The scheduled
 * `dashboard:refresh` command (Plan 07-02) upserts each row in place; the
 * unique index on metric_key enforces one-row-per-metric semantics so
 * widgets read a fixed small rowset instead of running live aggregations.
 *
 * Usage (read-side):
 *     $snapshot = DashboardSnapshot::where('metric_key', 'last_sync_run')->first();
 *     if ($snapshot?->isStale()) {
 *         dispatch(new RefreshDashboardSnapshotsJob());
 *     }
 *
 * Usage (write-side — Plan 07-02 + 07-05 commands):
 *     DashboardSnapshot::upsertByKey('last_sync_run', [
 *         'run_id' => $run->id,
 *         'duration_seconds' => $run->duration_seconds,
 *         'updated_count' => $run->updated_count,
 *         'failed_count' => $run->failed_count,
 *     ]);
 *
 * Writes are gated to admin-only via DashboardSnapshotPolicy (create/update/
 * delete all DENY to non-admins — the scheduled command runs under the
 * scheduler, not a user context, so user-gate policy is a defensive net
 * against accidental Filament CRUD wiring).
 */
final class DashboardSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'metric_key',
        'metric_value_json',
        'computed_at',
    ];

    protected $casts = [
        'metric_value_json' => 'array',
        'computed_at' => 'datetime',
    ];

    /**
     * Upsert a metric snapshot by key (D-02 one-row-per-metric semantics).
     * Used by Plan 07-02 dashboard:refresh + Plan 07-05 divergence scan.
     */
    public static function upsertByKey(string $key, array $value): self
    {
        return static::updateOrCreate(
            ['metric_key' => $key],
            ['metric_value_json' => $value, 'computed_at' => now()],
        );
    }

    /**
     * Is this snapshot older than the configured TTL (default 15m)?
     * Widgets use this to decide whether to show a stale-data amber border
     * and trigger a background refresh.
     */
    public function isStale(): bool
    {
        $ttl = (int) config('dashboard.snapshot_ttl_minutes', 15);

        return $this->computed_at->lt(now()->subMinutes($ttl));
    }

    protected static function newFactory(): DashboardSnapshotFactory
    {
        return DashboardSnapshotFactory::new();
    }
}
