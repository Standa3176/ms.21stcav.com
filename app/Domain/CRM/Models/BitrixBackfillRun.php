<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Database\Factories\Domain\CRM\BitrixBackfillRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 4 Plan 01 — bitrix:backfill-orders run tracker (CRM-10).
 *
 * Progress table mirroring Phase 2's sync_runs shape. Written by Plan 04-05's
 * BackfillOrdersChunkJob as the backfill walks through historical Woo orders.
 *
 * No LogsActivity trait — this is operational progress data, not configuration.
 */
final class BitrixBackfillRun extends Model
{
    use HasFactory;

    public const MODE_DRY_RUN = 'dry-run';

    public const MODE_LIVE = 'live';

    public const MODE_ADOPT_LEGACY = 'adopt-legacy-deal-ids';

    protected $fillable = [
        'since_date',
        'mode',
        'started_at',
        'finished_at',
        'total_orders',
        'processed_orders',
        'skipped_orders',
        'failed_orders',
        'adopted_legacy_count',
        'last_cursor',
        'notes',
        'correlation_id',
    ];

    protected $casts = [
        'since_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total_orders' => 'integer',
        'processed_orders' => 'integer',
        'skipped_orders' => 'integer',
        'failed_orders' => 'integer',
        'adopted_legacy_count' => 'integer',
    ];

    protected static function newFactory(): BitrixBackfillRunFactory
    {
        return BitrixBackfillRunFactory::new();
    }
}
