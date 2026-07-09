<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quick task 260709-m3p — Eloquent handle for `supplier_freshness_snapshots`.
 *
 * Mirrors the SupplierOfferSnapshot shape. Introduced so
 * CheckStaleSuppliersCommand can write its TRUNCATE-and-replace snapshot via
 * Eloquent (SupplierFreshnessSnapshot::query()->truncate()/->insert()) instead
 * of the banned Illuminate\Support\Facades\DB facade (SYNC-04 deny). The SQL is
 * byte-identical to the previous DB::table('supplier_freshness_snapshots')
 * path.
 *
 * Snapshot rows are immutable and carry only created_at (see the 260608-g8x
 * migration — no updated_at column), so $timestamps is disabled; created_at is
 * written explicitly by the command per-row.
 *
 * $guarded=[] because the command bulk-inserts pre-built row arrays (no
 * mass-assignment concern — the payload is machine-built, never user input).
 */
final class SupplierFreshnessSnapshot extends Model
{
    protected $table = 'supplier_freshness_snapshots';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'latest_recorded_at' => 'date',
        'days_since' => 'integer',
        'threshold_days' => 'integer',
        'created_at' => 'datetime',
    ];
}
