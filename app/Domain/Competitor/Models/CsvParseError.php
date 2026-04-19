<?php

declare(strict_types=1);

namespace App\Domain\Competitor\Models;

use Database\Factories\Domain\Competitor\CsvParseErrorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5 Plan 01 — CSV ingest triage row (COMP-05).
 *
 * Surfaces every parse failure as a resolvable row instead of a silent drop.
 * Filament CsvIngestIssuesPage (Plan 05-04) tabs on (issue_type, resolved_at).
 *
 * NO LogsActivity — this table is itself the audit trail. Mirroring edits
 * into activity_log would be redundant double-bookkeeping.
 */
final class CsvParseError extends Model
{
    use HasFactory;

    public const TYPE_AMBIGUOUS_MAPPING = 'ambiguous_mapping';

    public const TYPE_ENCODING_FAILURE = 'encoding_failure';

    public const TYPE_UNPARSEABLE_PRICE = 'unparseable_price';

    public const TYPE_INVALID_SKU_FORMAT = 'invalid_sku_format';

    public const TYPE_INVALID_FILENAME = 'invalid_filename';

    public const TYPE_ORPHAN_SKU = 'orphan_sku';

    protected $fillable = [
        'ingest_run_id',
        'competitor_id',
        'filename',
        'issue_type',
        'line_number',
        'raw_line',
        'context',
        'resolved_at',
    ];

    protected $casts = [
        'line_number' => 'int',
        'context' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(CompetitorIngestRun::class, 'ingest_run_id');
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('issue_type', $type);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    protected static function newFactory(): CsvParseErrorFactory
    {
        return CsvParseErrorFactory::new();
    }
}
