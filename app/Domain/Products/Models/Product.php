<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Database\Factories\Domain\Products\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 2 Plan 01 — Product mirror of WooCommerce's product identity.
 *
 * woo_product_id is the canonical cross-system ID. Laravel's auto-increment id
 * is LOCAL-ONLY — never pass it to Woo writes (Pitfall P2-D once catalogued).
 *
 * For simple products, $sku is populated; for variable products, $sku is null
 * and the children carry unique per-variation SKUs (see ProductVariant).
 *
 * Soft-deletes on the parent preserve sync history through retention-prune
 * cycles. Hard-delete cascades to ProductVariant children via FK.
 */
final class Product extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'woo_product_id', 'sku', 'name', 'type', 'status', 'stock_status', 'stock_quantity',
        'brand_id', 'category_id',
        'buy_price', 'sell_price', 'cost_price',
        'is_custom_ms', 'exclude_from_auto_update', 'tags',
        'last_synced_at', 'last_sync_run_id',
        'last_sales_count_90d', 'last_sales_count_computed_at',
        // Phase 6 Plan 01 — auto-create + SEO + completeness columns.
        'slug',
        'short_description',
        'long_description',
        'meta_description',
        'image_url',
        'gallery_image_urls',
        'requires_manual_image_review',
        'auto_create_status',
        'completeness_score',
        'completeness_computed_at',
        'completeness_missing_fields',
    ];

    protected $casts = [
        'is_custom_ms' => 'bool',
        'exclude_from_auto_update' => 'bool',
        'tags' => 'array',
        'last_synced_at' => 'datetime',
        'buy_price' => 'decimal:4',
        'sell_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'last_sales_count_90d' => 'int',
        'stock_quantity' => 'integer',
        'last_sales_count_computed_at' => 'datetime',
        // Phase 6 Plan 01 — auto-create casts.
        'gallery_image_urls' => 'array',
        'requires_manual_image_review' => 'bool',
        'completeness_score' => 'int',
        'completeness_computed_at' => 'datetime',
        'completeness_missing_fields' => 'array',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'sku', 'name', 'type', 'status',
                'brand_id', 'category_id',
                'buy_price', 'sell_price',
                'is_custom_ms', 'exclude_from_auto_update',
            ])
            ->logOnlyDirty();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 3 Plan 02 — pricing-key accessors consumed by RuleResolver
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Return the brand_id used by the RuleResolver's brand / brand_category
     * filter paths. Null when the product has no brand mapped (falls through
     * to category or default_tier).
     */
    public function getPricingBrandId(): ?int
    {
        return $this->brand_id === null ? null : (int) $this->brand_id;
    }

    /**
     * Return the category_id used by the RuleResolver's category /
     * brand_category filter paths. Null when the product has no category
     * mapped (falls through to brand or default_tier).
     */
    public function getPricingCategoryId(): ?int
    {
        return $this->category_id === null ? null : (int) $this->category_id;
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
