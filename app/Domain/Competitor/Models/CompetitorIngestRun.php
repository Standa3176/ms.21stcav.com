<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CompetitorIngestRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 5 Plan 01 — Per-CSV-file ingest run (mirrors Phase 2 SyncRun).
 *
 * Correlation-id threaded through watcher → chunk jobs → prices →
 * csv_parse_errors so a full trace is one ID lookup away.
 *
 * State machine: started → completed | failed.
 *
 * No `aborted` mid-state (COMP-07 idempotent-on-replay means "crashed then
 * re-run" simply re-dedups via the unique index; no separate abort semantics
 * are needed unlike sync_runs D-06 multi-worker rate guarding).
 */
final class CompetitorIngestRun extends Model
{
    use HasFactory;
    use LogsActivity;

    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'competitor_id',
        'filename',
        'rows_total',
        'rows_written',
        'rows_errored',
        'rows_orphaned',
        'status',
        'started_at',
        'completed_at',
        'correlation_id',
        'error_message',
    ];

    protected $casts = [
        'rows_total' => 'int',
        'rows_written' => 'int',
        'rows_errored' => 'int',
        'rows_orphaned' => 'int',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(CompetitorPrice::class, 'ingest_run_id');
    }

    public function parseErrors(): HasMany
    {
        return $this->hasMany(CsvParseError::class, 'ingest_run_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'rows_written', 'rows_errored', 'rows_orphaned', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): CompetitorIngestRunFactory
    {
        return CompetitorIngestRunFactory::new();
    }
}
