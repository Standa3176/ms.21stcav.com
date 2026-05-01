<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Models;

use Database\Factories\Domain\Quotes\QuoteLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11 Plan 01 — QuoteLine (QUOT-02).
 *
 * Per-line snapshot of price + product context at quote creation. The
 * `_at_quote` suffix on price columns is load-bearing — these are NEVER
 * recomputed after quote creation (D-13 line snapshot immutability).
 * Plan 11-02's `QuoteLineImmutabilityObserver` enforces by throwing on
 * `saving` when status != draft AND price/snapshot is dirty.
 *
 * Phase 11 D-13 — VAT-INCLUSIVE storage convention:
 *   `unit_price_pence_at_quote` is stored VAT-INCLUSIVE (matches
 *   PriceCalculator::compute output). PDF strips VAT at render time via
 *   `PriceCalculator::stripVat()` inverse helper (Phase 3 D-05). NEVER
 *   store as float/decimal — Pitfall 1.
 *
 * Mass-assignment lock (T-11-01-01 mitigation):
 *   $fillable INCLUDES unit_price_pence_at_quote because Plan 11-02's
 *   PriceSnapshotter is the sole legitimate writer. The immutability
 *   observer (Plan 11-02) catches direct mutations after creation —
 *   not the fillable list. Tests in Plan 11-02 assert observer behaviour;
 *   Plan 11-01 ships the fillable shape only.
 *
 * @property string $id                              26-char ULID PK
 * @property string $quote_id                        FK quotes.id ON DELETE CASCADE
 * @property string $sku                             denormalised — no FK to products (D-10 manual SKU)
 * @property int $quantity_int                       D-12 validation 1..9999
 * @property int $unit_price_pence_at_quote          VAT-INCLUSIVE pence (D-13)
 * @property int $line_total_pence_at_quote          unit * qty (recalc on qty edit while draft)
 * @property array $product_snapshot                 {name, brand, category, image_url} at line creation
 * @property int $sort_order                         admin-ordered Filament Repeater + PDF render
 */
final class QuoteLine extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'quote_lines';

    protected $fillable = [
        'id',
        'quote_id',
        'sku',
        'quantity_int',
        'unit_price_pence_at_quote',
        'line_total_pence_at_quote',
        'product_snapshot',
        'sort_order',
    ];

    protected $casts = [
        'quantity_int' => 'integer',
        'unit_price_pence_at_quote' => 'integer',
        'line_total_pence_at_quote' => 'integer',
        'sort_order' => 'integer',
        'product_snapshot' => 'array',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    protected static function newFactory(): QuoteLineFactory
    {
        return QuoteLineFactory::new();
    }
}
