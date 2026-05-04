<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quick task 260504-muq — per-supplier daily offer snapshot.
 *
 * One row per (sku, supplier_id, date) captures every supplier's price +
 * stock + rrp for a given local SKU on a given day. Written by
 * supplier:db-sync's syncSupplierOfferSnapshots() helper after the main
 * product update loop completes.
 *
 * sku is stored lowercase-trimmed (matchKey) to match the lookup pattern
 * used in the supplier-pull SQL — UI search reads with the same
 * normalization. product_id FK is nullable so feed entries for SKUs we
 * don't yet stock still land (forward-compat for Phase 6 auto-create).
 */
final class SupplierOfferSnapshot extends Model
{
    protected $fillable = [
        'sku',
        'product_id',
        'supplier_id',
        'supplier_name',
        'price',
        'stock',
        'rrp',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'date',
        'price' => 'decimal:4',
        'rrp' => 'decimal:4',
        'stock' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
