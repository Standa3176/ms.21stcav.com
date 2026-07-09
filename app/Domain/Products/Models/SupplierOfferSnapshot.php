<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Quick task 260608-g8x — canonical "exclude stale-supplier rows" filter.
     *
     * Filters down to rows whose `supplier_id` is in the supplied fresh-supplier
     * set. Three downstream consumers (AdCandidateScanner,
     * CompetitorPositionScanner.cheapestSupplierName, SupplierDbSyncCommand
     * buy-price selector) BYPASS this scope when their constructor flag is
     * false (back-compat with operator override).
     *
     * 260709-m3p — the freshness resolution is now performed by the CALLER
     * (which resolves App\Domain\Sync\Services\SupplierFreshnessResolver and
     * passes `->freshSupplierIds()->all()` in) rather than the model reaching
     * into the Sync service via `app(...)`. This inverts the Products→Sync
     * dependency (a Products MODEL must not depend on a Sync SERVICE) while
     * keeping the filtering behaviour byte-identical. Callers already hold the
     * singleton resolver, so the per-request classification cache is unaffected.
     *
     * Empty-whereIn safety: when zero suppliers are fresh, we render a
     * string sentinel ('__NO_FRESH_SUPPLIERS__') rather than letting
     * Eloquent silently drop the constraint. The 16-char string never
     * matches a real supplier_id (which is numeric in the supplier feed).
     *
     * @param  array<int, string>  $freshIds  supplier_ids the resolver classified 'fresh'
     */
    public function scopeFreshOnly(Builder $q, array $freshIds): Builder
    {
        return $q->whereIn(
            'supplier_id',
            $freshIds === [] ? ['__NO_FRESH_SUPPLIERS__'] : $freshIds,
        );
    }
}
