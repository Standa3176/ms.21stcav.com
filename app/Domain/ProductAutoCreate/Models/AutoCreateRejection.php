<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Models;

use App\Domain\Products\Models\Product;
use Database\Factories\Domain\ProductAutoCreate\AutoCreateRejectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 6 Plan 01 — AutoCreateRejection (D-06).
 *
 * Append-only rejection audit record. Captures the 8-value reason enum +
 * optional free-text notes (mandatory at Form-Request layer when
 * reason='other' — enforced in Plan 06-04 Filament action). Rows survive
 * product hard-delete so rejection history is retention-indefinite per
 * Phase 1 D-04.
 *
 * No updated_at — rejections are append-only; an edit would obscure the
 * audit trail. Only created_at (set via DB default) is captured.
 */
final class AutoCreateRejection extends Model
{
    use HasFactory;
    use LogsActivity;

    public $timestamps = false;

    public const REASON_NOT_A_REAL_PRODUCT = 'not_a_real_product';

    public const REASON_DUPLICATE_OF_EXISTING = 'duplicate_of_existing';

    public const REASON_DISCONTINUED_BY_SUPPLIER = 'discontinued_by_supplier';

    public const REASON_SPARE_PART_OR_ACCESSORY = 'spare_part_or_accessory';

    public const REASON_POOR_QUALITY_DATA = 'poor_quality_data';

    public const REASON_MISCLASSIFIED_BRAND_OR_CATEGORY = 'misclassified_brand_or_category';

    public const REASON_BELOW_VIABILITY_THRESHOLD = 'below_viability_threshold';

    public const REASON_OTHER = 'other';

    protected $fillable = [
        'product_id',
        'reason',
        'notes',
        'rejected_by_user_id',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'rejected_by_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['product_id', 'reason', 'notes', 'rejected_by_user_id'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): AutoCreateRejectionFactory
    {
        return AutoCreateRejectionFactory::new();
    }
}
