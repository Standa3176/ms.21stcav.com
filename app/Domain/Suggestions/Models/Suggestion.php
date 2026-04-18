<?php

declare(strict_types=1);

namespace App\Domain\Suggestions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
    ];

    protected $casts = [
        'payload' => 'array',
        'evidence' => 'array',
        'proposed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function proposedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
