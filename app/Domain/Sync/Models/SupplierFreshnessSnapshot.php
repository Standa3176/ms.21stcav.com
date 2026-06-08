<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Quick task 260608-g8x — snapshot row written by `suppliers:check-stale`.
 *
 * TRUNCATE-and-replace semantics: every run of the command wipes the table
 * and re-INSERTs one row per known supplier under a fresh ULID `run_id`.
 * The latest run_id = "current" — exposed via the `current()` scope.
 *
 * Dashboard widgets + NotificationCentre `staleSuppliers()` bucket read
 * THIS model (not the resolver) so renders stay sub-ms per D-02 truth.
 *
 * Drift-prevention: ZERO classification logic in the model. The
 * SupplierFreshnessResolver is the only place that says fresh/amber/stale.
 */
final class SupplierFreshnessSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'supplier_id',
        'supplier_name',
        'latest_recorded_at',
        'days_since',
        'threshold_days',
        'status',
        'run_id',
        'created_at',
    ];

    protected $casts = [
        'latest_recorded_at' => 'date',
        'days_since' => 'integer',
        'threshold_days' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Rows for the latest run_id (the "current" snapshot). Resilient to
     * the multi-run case — only the last run wins.
     */
    public function scopeCurrent(Builder $q): Builder
    {
        // Sub-select gets the latest run_id; equality filter is index-aided
        // via the run_id single-column index from the migration.
        return $q->where('run_id', function ($sub): void {
            $sub->from('supplier_freshness_snapshots')
                ->select('run_id')
                ->orderByDesc('created_at')
                ->limit(1);
        });
    }
}
