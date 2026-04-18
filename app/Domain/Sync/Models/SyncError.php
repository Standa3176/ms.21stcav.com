<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use Database\Factories\Domain\Sync\SyncErrorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 2 Plan 01 — SyncError append-only log.
 *
 * Written by SyncChunkJob whenever a per-SKU write throws. $timestamps=false
 * because the migration uses ->useCurrent() on created_at and there's no
 * updated_at (append-only).
 */
final class SyncError extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'sync_run_id', 'sku', 'woo_product_id', 'woo_variation_id',
        'error_class', 'error_message', 'correlation_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'sync_run_id');
    }

    public function scopeForRun(Builder $q, int $runId): Builder
    {
        return $q->where('sync_run_id', $runId);
    }

    protected static function newFactory(): SyncErrorFactory
    {
        return SyncErrorFactory::new();
    }
}
