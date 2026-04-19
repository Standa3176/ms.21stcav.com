<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use App\Domain\Products\Observers\ProductVariantObserver;
use Database\Factories\Domain\Products\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 2 Plan 01 — Variation-level Woo model (D-01 + D-03).
 *
 * One row per Woo variation. Each variation's SKU is globally unique in Woo;
 * we mirror that uniqueness in product_variants.sku.
 *
 * Observer (ProductVariantObserver) bumps parent Product.last_synced_at on save
 * — Pitfall P2-C mitigation (SyncRunResource drill-down would otherwise show
 * "parent last synced 3 days ago" when a variation was touched 5 minutes ago).
 */
#[ObservedBy([ProductVariantObserver::class])]
final class ProductVariant extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'product_id', 'woo_variation_id', 'sku', 'name',
        'brand_id', 'category_id',
        'buy_price', 'sell_price', 'old_buy_price', 'old_sell_price',
        'stock_quantity', 'old_stock_quantity',
        'status', 'attributes', 'last_synced_at',
    ];

    protected $casts = [
        'attributes' => 'array',
        'last_synced_at' => 'datetime',
        'buy_price' => 'decimal:4',
        'sell_price' => 'decimal:4',
        'old_buy_price' => 'decimal:4',
        'old_sell_price' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'sku', 'brand_id', 'category_id',
                'buy_price', 'sell_price', 'stock_quantity', 'status',
            ])
            ->logOnlyDirty();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Phase 3 Plan 02 — pricing-key accessors (fall back to parent Product)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Return this variant's brand_id, falling back to the parent Product's
     * brand_id when the variation does not carry its own override. RuleResolver
     * calls this instead of reading $product->brand_id directly so a future
     * per-variant override is a config-only change.
     */
    public function getPricingBrandId(): ?int
    {
        if ($this->brand_id !== null) {
            return (int) $this->brand_id;
        }

        return $this->product?->getPricingBrandId();
    }

    /**
     * Return this variant's category_id, falling back to the parent Product's
     * category_id. Mirrors getPricingBrandId() semantics — parent inheritance
     * keeps the common case (most variants have no per-variant override)
     * working without any variant-level write.
     */
    public function getPricingCategoryId(): ?int
    {
        if ($this->category_id !== null) {
            return (int) $this->category_id;
        }

        return $this->product?->getPricingCategoryId();
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
