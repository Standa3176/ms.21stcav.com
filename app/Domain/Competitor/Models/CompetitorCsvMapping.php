<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CompetitorCsvMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 5 Plan 01 — Per-competitor CSV column mapping (D-03).
 *
 * One mapping per competitor (enforced by UNIQUE(competitor_id)). Populated
 * on first-ingest by ColumnHeuristicDetector; subsequent ingests use this
 * row directly (fast-path) unless admin resets via Filament Plan 05-04.
 *
 * LogsActivity ON — mapping edits are admin-initiated and the outcome
 * changes how every future CSV for this competitor is parsed. Audit noise
 * here is the right cost for forensic traceability.
 */
final class CompetitorCsvMapping extends Model
{
    use HasFactory;
    use LogsActivity;

    public const FORMAT_DOT = 'dot';

    public const FORMAT_COMMA = 'comma';

    protected $fillable = [
        'competitor_id',
        'sku_column_index',
        'price_column_index',
        'decimal_format',
        'detected_at',
    ];

    protected $casts = [
        'sku_column_index' => 'int',
        'price_column_index' => 'int',
        'detected_at' => 'datetime',
    ];

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['competitor_id', 'sku_column_index', 'price_column_index', 'decimal_format'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): CompetitorCsvMappingFactory
    {
        return CompetitorCsvMappingFactory::new();
    }
}
