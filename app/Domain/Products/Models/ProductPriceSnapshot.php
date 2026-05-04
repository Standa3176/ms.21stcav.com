<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quick task 260504-muq — daily ProductPriceSnapshot.
 *
 * One row per Product per day captures the canonical sell/buy/stock trio
 * for 90-day price + stock history. Written by woo:import-products then
 * overwritten same-day by supplier:db-sync (idempotent on
 * unique(product_id, recorded_at)).
 */
final class ProductPriceSnapshot extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'woo_status',
        'sell_price',
        'buy_price',
        'stock_quantity',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'date',
        'sell_price' => 'decimal:4',
        'buy_price' => 'decimal:4',
        'stock_quantity' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
