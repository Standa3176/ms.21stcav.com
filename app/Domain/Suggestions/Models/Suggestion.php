<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Human-in-the-loop suggestion. Producers create these; admins approve/reject
 * via the Filament SuggestionResource. Approval enqueues ApplySuggestionJob which
 * resolves an applier (registered in AppServiceProvider by kind), runs it, and
 * flips status to 'applied'.
 *
 * ULID primary key — subject_id on integration_events is nullableUlidMorphs
 * (CHAR(26)) so cross-table joins work natively (iter-1 Warning 8).
 */
class Suggestion extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'kind',
        'status',
        'correlation_id',
        'payload',
        'evidence',
        'proposed_by_type',
        'proposed_by_id',
        'proposed_at',
        'resolved_by_user_id',
        'resolved_at',
        'rejection_reason',
        'applied_at',
        // Phase 10 Plan 05 D-09 — structured rejection feedback (top-level
        // column; column-canonical resolution per Plan 10-05 Step B).
        'agent_rejection_feedback',
    ];

    protected $casts = [
        'payload' => 'array',
        'evidence' => 'array',
        'proposed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'applied_at' => 'datetime',
        // Phase 10 Plan 05 D-09 — structured rejection feedback shape:
        //   { misleading: yes|no|partial, notes: string,
        //     rejected_by_user_id: int, rejected_at: ISO 8601,
        //     triaged_at?: ISO 8601, triage_note?: string,
        //     triaged_by_user_id?: int }
        'agent_rejection_feedback' => 'array',
    ];

    /**
     * Prod audit-write hardening — payload + correlation_id are NOT NULL in the
     * schema, but failure-audit hooks (CreateWooProductJob::failed,
     * ProcessAutoCreateImageJob) write Suggestions outside a correlated context
     * and without a payload. Default only when MISSING; explicit values pass
     * through unchanged.
     */
    protected static function booted(): void
    {
        static::creating(function (self $s): void {
            if ($s->payload === null) {
                $s->payload = [];
            }

            if ($s->correlation_id === null || $s->correlation_id === '') {
                $s->correlation_id = Context::get('correlation_id') ?? (string) Str::uuid();
            }
        });
    }

    public function proposedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * Quick task 260606-lhp — single source of truth for the
     * "high-confidence sourceable" predicate.
     *
     * A row is high-confidence-sourceable iff ALL of:
     *   - status = 'pending'
     *   - kind   = 'new_product_opportunity'
     *   - evidence.sku EXISTS in supplier_sku_cache (LOWER+TRIM match)
     *   - CAST(evidence.supporting_competitors) >= 3
     *
     * Consumed by:
     *   - SuggestionResource::getNavigationBadge() (sidebar attention count)
     *   - SuggestionResource::getNavigationBadgeTooltip() (3-tier breakdown)
     *   - SnapshotAggregator::computeSuggestionsTriageHealth() (Home tile)
     *
     * Drift-prevention: every consumer calls this scope; no inline whereRaw
     * duplicates of the 4-clause conjunction allowed elsewhere.
     *
     * Driver-aware JSON expression mirrors PruneOrphanSuggestionsCommand:
     *   - MySQL  : JSON_UNQUOTE(JSON_EXTRACT(...)) + CAST(... AS UNSIGNED)
     *   - SQLite : json_extract(...)                + CAST(... AS INTEGER)
     */
    public function scopeHighConfidenceSourceable(Builder $q): Builder
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        $skuExpr = $isSqlite
            ? "json_extract(suggestions.evidence, '$.sku')"
            : "JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku'))";

        $competitorsExpr = $isSqlite
            ? "CAST(json_extract(evidence, '$.supporting_competitors') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED)";

        return $q
            ->where('status', self::STATUS_PENDING)
            ->where('kind', 'new_product_opportunity')
            ->whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM({$skuExpr})))")
            ->whereRaw("{$competitorsExpr} >= 3");
    }
}
