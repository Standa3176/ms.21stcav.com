<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use Database\Factories\Domain\Sync\SyncRunItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 2 Plan 01 — SyncRunItem append-only per-SKU log.
 *
 * Source for the D-10 CSV report (11 columns + sync_run_id FK). forRun(id)
 * scope keyed for SyncReportCsvGenerator's chunked streaming.
 */
final class SyncRunItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    public const ACTION_UPDATED = 'updated';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_FAILED = 'failed';
    public const ACTION_MISSING = 'missing';
    public const ACTION_UNKNOWN_SKU = 'unknown_sku';

    protected $fillable = [
        'sync_run_id', 'sku', 'woo_product_id', 'woo_variation_id',
        'action', 'reason', 'old_price', 'new_price', 'old_stock', 'new_stock',
        'error_message', 'correlation_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'old_stock' => 'int',
        'new_stock' => 'int',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function scopeForRun(Builder $q, int $runId): Builder
    {
        return $q->where('sync_run_id', $runId);
    }

    protected static function newFactory(): SyncRunItemFactory
    {
        return SyncRunItemFactory::new();
    }
}
