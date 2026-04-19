<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Database\Factories\Domain\CRM\GdprErasureLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 4 Plan 05 Task 2 — GDPR erasure audit entry (CRM-13).
 *
 * Append-only from code — the model has no update/delete UI surface, and the
 * associated policy denies create/update/delete outright. Indefinite retention
 * (no prune command touches this table). Separate from activity_log so the
 * retention policy is unambiguously different from the 365-day audit cap.
 *
 * @property int $id
 * @property string $email_hash
 * @property string|null $contact_bitrix_id
 * @property array|null $deal_bitrix_ids
 * @property int|null $actor_id
 * @property string|null $correlation_id
 * @property int $fields_scrubbed_count
 * @property string $status  'applied' | 'no_match' | 'failed'
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $erased_at
 */
final class GdprErasureLogEntry extends Model
{
    use HasFactory;

    public const STATUS_APPLIED = 'applied';

    public const STATUS_NO_MATCH = 'no_match';

    public const STATUS_FAILED = 'failed';

    protected $table = 'gdpr_erasure_log';

    protected $fillable = [
        'email_hash',
        'contact_bitrix_id',
        'deal_bitrix_ids',
        'actor_id',
        'correlation_id',
        'fields_scrubbed_count',
        'status',
        'notes',
        'erased_at',
    ];

    protected $casts = [
        'deal_bitrix_ids' => 'array',
        'fields_scrubbed_count' => 'integer',
        'erased_at' => 'datetime',
    ];

    protected static function newFactory(): GdprErasureLogEntryFactory
    {
        return GdprErasureLogEntryFactory::new();
    }
}
