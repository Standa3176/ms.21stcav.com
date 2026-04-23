<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Products\Models\Product;
use Database\Factories\Domain\Pricing\ProductOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 3 Plan 01 — ProductOverride (D-08, D-09).
 *
 * D-08: override semantics = margin % replacement, NOT a direct final-price
 *       override. The calculator receives the override's margin basis points
 *       instead of the matched rule's; the formula is unchanged. Keeps the
 *       golden-fixture shape identical.
 *
 * D-09: parent-only granularity. DB UNIQUE(product_id) enforces one row per
 *       product; variations inherit the parent's override margin. Forward-
 *       compatible with adding a nullable variant_id column in v2 (Pitfall 7
 *       — nullable columns added later don't break existing rows).
 *
 * Consumed by RuleResolver (Plan 02): if a ProductOverride exists for a given
 * product, its margin wins and the rule-sort is skipped entirely.
 */
final class ProductOverride extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'product_id',
        'margin_basis_points',
        'reason',
        'created_by_user_id',
        // Phase 6 Plan 01 — D-10 pin-per-field booleans (AUTO-10).
        'pin_title',
        'pin_short_description',
        'pin_long_description',
        'pin_meta_description',
        'pin_image',
        'pin_slug',
        'pin_brand',
        'pin_category',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'margin_basis_points' => 'integer',
        'created_by_user_id' => 'integer',
        // Phase 6 Plan 01 — pin casts.
        'pin_title' => 'bool',
        'pin_short_description' => 'bool',
        'pin_long_description' => 'bool',
        'pin_meta_description' => 'bool',
        'pin_image' => 'bool',
        'pin_slug' => 'bool',
        'pin_brand' => 'bool',
        'pin_category' => 'bool',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'product_id',
                'margin_basis_points',
                'reason',
                // Phase 6 Plan 01 D-12 — audit every pin toggle (AUTO-11 audit trail).
                'pin_title',
                'pin_short_description',
                'pin_long_description',
                'pin_meta_description',
                'pin_image',
                'pin_slug',
                'pin_brand',
                'pin_category',
            ])
            ->logOnlyDirty();
    }

    protected static function newFactory(): ProductOverrideFactory
    {
        return ProductOverrideFactory::new();
    }
}
