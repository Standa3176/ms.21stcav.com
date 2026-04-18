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
                'sku', 'buy_price', 'sell_price', 'stock_quantity', 'status',
            ])
            ->logOnlyDirty();
    }

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }
}
