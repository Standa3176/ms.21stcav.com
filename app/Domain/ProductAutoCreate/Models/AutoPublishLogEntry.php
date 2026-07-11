<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Models;

use Database\Factories\Domain\ProductAutoCreate\AutoPublishLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Quick task 260711-aps Task 2 — auto_publish_log audit record.
 *
 * Append-only "what was pushed and when" log for the twice-weekly scheduled
 * auto-publish. ONE row per REAL successful live publish (never in shadow mode).
 * competitor_count captures the driving suggestion's supporting_competitors
 * (2 or 3 under the schedule) so the operator sees the split at a glance.
 *
 * published_at is the authoritative write timestamp — no created_at/updated_at
 * (matches the auto_create_rejections append-only audit convention).
 */
final class AutoPublishLogEntry extends Model
{
    use HasFactory;

    /** Default source label — the twice-weekly scheduled straight-to-live run. */
    public const SOURCE_SCHEDULED = 'scheduled_auto_publish';

    protected $table = 'auto_publish_log';

    public $timestamps = false;

    protected $fillable = [
        'sku',
        'product_id',
        'woo_product_id',
        'competitor_count',
        'supplier_count',
        'source',
        'batch_correlation_id',
        'published_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'woo_product_id' => 'integer',
        'competitor_count' => 'integer',
        'supplier_count' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function newFactory(): AutoPublishLogEntryFactory
    {
        return AutoPublishLogEntryFactory::new();
    }
}
