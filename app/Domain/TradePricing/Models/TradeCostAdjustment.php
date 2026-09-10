<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Models;

use Carbon\CarbonInterface;
use Database\Factories\TradeCostAdjustmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Quick task 260910-lwv — a trade-only discount off the fed supplier cost.
 *
 * See the migration for why this exists rather than editing buy_price.
 *
 * @property int $id
 * @property int|null $brand_id
 * @property string|null $sku
 * @property string|null $adjustment_pct
 * @property string|null $absolute_cost
 * @property CarbonInterface|null $valid_from
 * @property CarbonInterface|null $valid_until
 * @property string|null $reason
 * @property bool $is_active
 */
final class TradeCostAdjustment extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'brand_id', 'sku', 'adjustment_pct', 'absolute_cost', 'valid_from', 'valid_until', 'reason', 'is_active',
    ];

    protected $casts = [
        'brand_id' => 'integer',
        'adjustment_pct' => 'decimal:3',
        'absolute_cost' => 'decimal:4',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'bool',
    ];

    /**
     * Rows in force TODAY. A null bound is open-ended in that direction, so a
     * permanent arrangement (Yealink platinum) leaves both null while a dated
     * deal (Logitech, 2 months) sets valid_until and lapses on its own.
     */
    public function scopeInForce(Builder $q, ?CarbonInterface $on = null): Builder
    {
        $on = ($on ?? now())->toDateString();

        return $q->where('is_active', true)
            ->where(fn (Builder $w) => $w->whereNull('valid_from')->orWhere('valid_from', '<=', $on))
            ->where(fn (Builder $w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $on));
    }

    /** Fraction off feed cost — 12.5% stored becomes 0.125. Zero on a price-list row. */
    public function fraction(): float
    {
        return $this->adjustment_pct === null ? 0.0 : ((float) $this->adjustment_pct) / 100;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['brand_id', 'sku', 'adjustment_pct', 'absolute_cost', 'valid_from', 'valid_until', 'reason', 'is_active'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): TradeCostAdjustmentFactory
    {
        return TradeCostAdjustmentFactory::new();
    }
}
