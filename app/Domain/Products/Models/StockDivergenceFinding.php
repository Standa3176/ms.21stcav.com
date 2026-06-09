<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quick task 260609-nku — Eloquent model for the stock_divergence_findings
 * snapshot table.
 *
 * Schema lives in 2026_06_09_160718_create_stock_divergence_findings_table.
 * Snapshot semantics: TRUNCATE + re-INSERT every Mon 09:15 London run by
 * AuditStockDivergenceCommand. There is no history — the table reflects
 * "current phantom-stock divergences" only.
 *
 * status is intentionally kept as a plain string (column-level varchar(32)
 * constraint via the migration) rather than an Eloquent enum cast. Single
 * value today ('woo_overcount') with room for woo_undercount + woo_missing
 * later without migration churn or class round-trips.
 *
 * phantom_units = woo_stock_quantity - ms_stock_quantity. Stored at write
 * time so the Filament page's default sort can use a plain index instead
 * of computing the delta on every row.
 *
 * $guarded = [] is safe here because the only write path is the
 * AuditStockDivergenceCommand's bulk insert via DB::table (which bypasses
 * Eloquent mass-assignment entirely) and the per-row Filament Page reads
 * — no operator-shaped form ever hydrates this model.
 */
final class StockDivergenceFinding extends Model
{
    public const STATUS_WOO_OVERCOUNT = 'woo_overcount';

    protected $table = 'stock_divergence_findings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'woo_product_id' => 'int',
            'ms_stock_quantity' => 'int',
            'woo_stock_quantity' => 'int',
            'phantom_units' => 'int',
            'woo_last_modified' => 'datetime',
            'ms_last_synced_at' => 'datetime',
            'audited_at' => 'datetime',
        ];
    }
}
