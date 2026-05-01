<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Models;

use App\Domain\TradePricing\Models\CustomerGroup;
use App\Models\User;
use Database\Factories\Domain\Quotes\QuoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 11 Plan 01 — Quote (QUOT-01).
 *
 * v2 B2B quote model with line-price snapshot immutability that survives
 * subsequent PricingRule edits. ULID PK matches v2.x cross-domain reference
 * convention (Phase 1 D-16, Phase 8 AgentRun, future Phase 13 WhatsApp +
 * Phase 14 chatbot session IDs).
 *
 * Snapshot intent: every column suffixed `_at_quote` is set ONCE at quote
 * creation by Plan 11-02's PriceSnapshotter and NEVER mutates after status
 * leaves `draft`. The QuoteLineImmutabilityObserver (Plan 11-02) enforces by
 * throwing on `saving` when status != draft AND price/snapshot is dirty.
 *
 * Dual-mode customer (D-01):
 *   - user_id NULLABLE — anonymous-lead path supports Phase 13 WhatsApp +
 *     Phase 14 chatbot + cold sales calls
 *   - customer_group_id NULLABLE FK + customer_group_name_at_quote
 *     denormalised string (CONTEXT.md A9 — survives FK rename)
 *   - customer_email/customer_name + billing_address JSON denormalised at
 *     creation; populated from User on D-03 toggle ON
 *
 * Status state machine (v1.0 — D-04 + D-05 + D-07):
 *   draft → sent → (accepted | rejected | expired)
 *   sent → draft (admin-only revert within 5 min of send — D-05)
 *
 *   Reserved-but-unused in v1.0 (D-06): pending_approval, approved.
 *   Explicitly NO `withdrawn` case — sales overwrites by editing the draft.
 *
 * LogsActivity (cross-cutting invariant 1 + Phase 1 D-04):
 *   Only status + the 4 status timestamps + total_pence_at_quote flow into
 *   activity_log. customer_email / billing_address are EXCLUDED to avoid
 *   leaking PII into the audit table (T-11-01-04 mitigation — protect via
 *   Filament policy view/viewAny gates instead).
 *
 * @property string $id                              26-char ULID PK
 * @property int|null $user_id                       FK users.id ON DELETE SET NULL
 * @property int|null $customer_group_id             FK customer_groups.id ON DELETE RESTRICT
 * @property string|null $customer_group_name_at_quote
 * @property string $customer_email                  D-01 denormalised
 * @property string|null $customer_name              D-01 denormalised
 * @property array|null $billing_address             D-01 PII — Filament-gated
 * @property string $status                          enum QuoteStatus value
 * @property int $total_pence_at_quote               cached SUM(quote_lines.line_total) — recompute observer Plan 11-02
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $rejected_at
 * @property \Illuminate\Support\Carbon|null $expired_at
 * @property array|null $rejection_metadata          D-08 reason + optional note
 * @property string|null $correlation_id             cross-cutting invariant 6
 */
final class Quote extends Model
{
    use HasFactory;
    use HasUlids;
    use LogsActivity;

    /*
    |--------------------------------------------------------------------------
    | Status constants — mirror QuoteStatus enum string values
    |--------------------------------------------------------------------------
    |
    | Pattern from Phase 8 AgentRun: define string constants alongside the
    | enum so legacy raw-string queries continue to work. Code may use either
    | `where('status', Quote::STATUS_DRAFT)` or
    | `where('status', QuoteStatus::Draft->value)` — the enum is the source
    | of truth, the constants are convenience aliases.
    */
    public const STATUS_DRAFT = 'draft';

    /** D-05 + D-06 RESERVED — unused in v1.0 transitions. */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    /** D-05 + D-06 RESERVED — unused in v1.0 transitions. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'quotes';

    protected $fillable = [
        'id',
        'user_id',
        'customer_group_id',
        'customer_group_name_at_quote',
        'customer_email',
        'customer_name',
        'billing_address',
        'status',
        'total_pence_at_quote',
        'expires_at',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'expired_at',
        'rejection_metadata',
        'correlation_id',
    ];

    protected $casts = [
        'billing_address' => 'array',
        'rejection_metadata' => 'array',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expired_at' => 'datetime',
        'total_pence_at_quote' => 'integer',
        'user_id' => 'integer',
        'customer_group_id' => 'integer',
    ];

    /**
     * Phase 1 D-04 + cross-cutting invariant 1 — only the sales-relevant
     * status columns flow into activity_log. customer_email + billing_address
     * are EXCLUDED to avoid PII leakage (T-11-01-04). Use logOnlyDirty so
     * touch saves (re-renders / observer recomputes) don't pollute the log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status',
                'sent_at',
                'accepted_at',
                'rejected_at',
                'expired_at',
                'total_pence_at_quote',
            ])
            ->logOnlyDirty();
    }

    /**
     * D-13 — line snapshot immutability lives on the QuoteLine model itself.
     * This relation is the read-side; writes flow through Plan 11-02's
     * PriceSnapshotter (creates) + QuoteLineImmutabilityObserver (mutations).
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('sort_order');
    }

    /** D-01 — nullable BelongsTo when the quote is for a registered customer. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** D-02 — nullable BelongsTo; FK ON DELETE RESTRICT prevents orphaning. */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /**
     * UI display helper — first 8 chars of the 26-char ULID.
     * Per CONTEXT.md Claude's Discretion: PDF reference shows
     * "Quote #{ulid_short_8}" for human-friendly identifiers; ULIDs remain
     * unique enough at 8 chars for human reference + are sortable.
     */
    public function ulidShort(): string
    {
        return substr($this->id, 0, 8);
    }

    protected static function newFactory(): QuoteFactory
    {
        return QuoteFactory::new();
    }
}
