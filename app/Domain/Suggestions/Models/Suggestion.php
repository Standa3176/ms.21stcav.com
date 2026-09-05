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
     * Quick task 260905-po7 — single source of truth for the "sourceable
     * pending" predicate.
     *
     * A row is sourceable-pending iff ALL of:
     *   - status = 'pending'
     *   - kind   = 'new_product_opportunity'
     *   - evidence.sku EXISTS in supplier_sku_cache (LOWER+TRIM match)
     *
     * This is the set the Suggestions list now OPENS on (the on_supplier_db
     * filter defaults to 'yes') and the set the bulk "Auto-create selected"
     * action will actually act on. The sidebar badge counts it so the number
     * matches what clicking the badge shows — before 260905-po7 the badge
     * counted high-confidence rows while the list showed all ~8k pending,
     * which is how an operator spent two days believing the bulk button was
     * broken when it was silently dropping non-sourceable rows.
     *
     * scopeHighConfidenceSourceable() composes onto this and adds the
     * >= 3 competitor gate; the Home dashboard tile stays on that narrower
     * scope because it is labelled "high-confidence".
     */
    public function scopeSourceablePending(Builder $q): Builder
    {
        $skuExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(suggestions.evidence, '$.sku')"
            : "JSON_UNQUOTE(JSON_EXTRACT(suggestions.evidence, '$.sku'))";

        return $q
            ->where('status', self::STATUS_PENDING)
            ->where('kind', 'new_product_opportunity')
            ->whereRaw("EXISTS (SELECT 1 FROM supplier_sku_cache c WHERE c.sku = LOWER(TRIM({$skuExpr})))");
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
     *   - SuggestionResource::getNavigationBadgeTooltip() (3-tier breakdown)
     *   - SnapshotAggregator::computeSuggestionsTriageHealth() (Home tile)
     *
     * NOT consumed by the sidebar badge any more — see scopeSourceablePending().
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
        $competitorsExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(json_extract(evidence, '$.supporting_competitors') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(evidence, '$.supporting_competitors')) AS UNSIGNED)";

        // 260905-po7 — status + kind + supplier-feed membership now come from
        // scopeSourceablePending(); only the competitor gate is added here.
        return $q
            ->sourceablePending()
            ->whereRaw("{$competitorsExpr} >= 3");
    }
}
