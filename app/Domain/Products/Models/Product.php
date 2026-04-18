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
        'woo_product_id', 'sku', 'name', 'type', 'status', 'stock_status',
        'buy_price', 'sell_price', 'cost_price',
        'is_custom_ms', 'exclude_from_auto_update', 'tags',
        'last_synced_at', 'last_sync_run_id',
    ];

    protected $casts = [
        'is_custom_ms' => 'bool',
        'exclude_from_auto_update' => 'bool',
        'tags' => 'array',
        'last_synced_at' => 'datetime',
        'buy_price' => 'decimal:4',
        'sell_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
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
                'buy_price', 'sell_price',
                'is_custom_ms', 'exclude_from_auto_update',
            ])
            ->logOnlyDirty();
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
