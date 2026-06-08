<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quick task 260608-g8x — local-side supplier metadata.
 *
 * The `suppliers` table is populated on every run of `suppliers:check-stale`
 * by discovering distinct supplier_ids in `supplier_offer_snapshots` and
 * upserting one row per. Operators can then override per-supplier behaviour:
 *
 *   - `stale_after_days`: per-supplier window override. NULL → falls back to
 *     `config('supplier.default_stale_after_days', 7)` in
 *     SupplierFreshnessResolver::thresholdDaysFor().
 *   - `is_active`: dormant a supplier without deleting.
 *   - `notes`: free-form operator context.
 *
 * Lives under App\Domain\Sync\Models because suppliers are a Sync-domain
 * concern — `supplier_db:sync` writes the upstream supplier offer snapshots
 * this table overlays.
 */
final class Supplier extends Model
{
    protected $fillable = [
        'supplier_id',
        'name',
        'stale_after_days',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'stale_after_days' => 'integer',
        'is_active' => 'boolean',
    ];
}
