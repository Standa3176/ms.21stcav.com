<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Products\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quick task 261027-duz — operator-curated per-(sku, supplier) offer block.
 *
 * Makes ONE supplier's offer for ONE match key invisible to cost and stock in
 * supplier:db-sync (buildBestOfferMap) and products:hydrate-stock-from-offers.
 * The same supplier still counts for every other key. Used for supplier SKU
 * collisions (incident 2026-10-27: Midwich's code 1301222 is an unrelated £7.20
 * item; local 1301222 is a £88.20 Yealink T74U sourced from Nimans).
 *
 * `sku` is stored normalised (lowercase + trim) — it is the match key, not
 * necessarily the product SKU. `product_id` is display-only, resolved from the
 * sku at write time; matching never uses it.
 */
final class ProductSupplierBlock extends Model
{
    protected $fillable = [
        'sku',
        'product_id',
        'supplier_id',
        'reason',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        self::saving(static function (self $block): void {
            $block->sku = strtolower(trim((string) $block->sku));
            $block->supplier_id = trim((string) $block->supplier_id);

            if ($block->product_id === null && $block->sku !== '') {
                $block->product_id = Product::query()
                    ->whereRaw('LOWER(TRIM(sku)) = ?', [$block->sku])
                    ->value('id');
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
