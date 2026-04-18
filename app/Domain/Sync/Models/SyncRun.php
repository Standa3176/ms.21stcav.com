<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Foundation\Audit\Services\Auditor;
use Database\Factories\Domain\Sync\SyncRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 2 Plan 01 — Orchestrator-owned state for a single `php artisan sync:supplier`
 * invocation.
 *
 * State machine:
 *   queued → running → completed | aborted | failed
 *
 * consecutive_failures is the D-06(b) Checker-blocker shared counter:
 * AbortGuard (Plan 02-03) reads + increments atomically via `SyncRun::increment`
 * so multi-worker supervisors on sync-woo-push share state across processes.
 *
 * cursor_page + cursor_sku persist so an aborted run can resume via
 * `findResumable($id)` + `--resume={run_id} --live` (D-07 + SYNC-03).
 */
final class SyncRun extends Model
{
    use HasFactory;
    use LogsActivity;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_FAILED = 'failed';

    public const ABORT_ERROR_RATE = 'error_rate';
    public const ABORT_CONSECUTIVE = 'consecutive_failures';
    public const ABORT_JWT_REFRESH = 'jwt_refresh';
    public const ABORT_MANUAL = 'manual';

    protected $fillable = [
        'started_at', 'completed_at', 'status', 'dry_run',
        'total_skus', 'updated_count', 'skipped_count',
        'failed_count', 'missing_count', 'unknown_sku_count',
        'consecutive_failures',   // D-06(b) shared counter — Checker blocker fix
        'abort_reason', 'abort_message',
        'cursor_page', 'cursor_sku', 'correlation_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dry_run' => 'bool',
        'total_skus' => 'int',
        'updated_count' => 'int',
        'skipped_count' => 'int',
        'failed_count' => 'int',
        'missing_count' => 'int',
        'unknown_sku_count' => 'int',
        'consecutive_failures' => 'int',
        'cursor_page' => 'int',
    ];

    public function errors(): HasMany
    {
        return $this->hasMany(SyncError::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncRunItem::class);
    }

    public function markRunning(): void
    {
        $this->update(['status' => self::STATUS_RUNNING]);

        app(Auditor::class)->record('sync.run.running', [
            'run_id' => $this->id,
        ]);
    }

    public function abort(string $reason, ?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_ABORTED,
            'abort_reason' => $reason,
            'abort_message' => $message,
            'completed_at' => now(),
        ]);

        app(Auditor::class)->record('sync.run.aborted', [
            'run_id' => $this->id,
            'reason' => $reason,
            'message' => $message,
        ]);
    }

    public function finalise(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        app(Auditor::class)->record('sync.run.completed', [
            'run_id' => $this->id,
            'stats' => $this->only([
                'total_skus', 'updated_count', 'skipped_count',
                'failed_count', 'missing_count', 'unknown_sku_count',
            ]),
        ]);
    }

    public function scopeResumable(Builder $q): Builder
    {
        return $q->whereIn('status', [
            self::STATUS_ABORTED,
            self::STATUS_FAILED,
            self::STATUS_RUNNING,
        ]);
    }

    public static function findResumable(int $id): self
    {
        $run = static::query()->resumable()->findOrFail($id);
        $run->update(['status' => self::STATUS_RUNNING]);

        return $run;
    }

    public function incrementCounter(string $action): void
    {
        $column = match ($action) {
            'updated' => 'updated_count',
            'skipped' => 'skipped_count',
            'failed' => 'failed_count',
            'missing' => 'missing_count',
            'unknown_sku' => 'unknown_sku_count',
            default => throw new \InvalidArgumentException("Unknown counter action: {$action}"),
        };

        $this->increment($column);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'abort_reason', 'updated_count', 'failed_count'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): SyncRunFactory
    {
        return SyncRunFactory::new();
    }
}
