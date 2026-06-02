<?php

declare(strict_types=1);

namespace App\Domain\Products\Models;

use App\Models\User;
use Database\Factories\Domain\Products\ProductExceptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Operator-managed allowlist preserving publish status on Woo for SKUs the
 * supplier sync would otherwise demote. See migration docblock for
 * background.
 *
 * Filament UI lives at /admin/product-exceptions (admin + pricing_manager).
 * FlagProductsMissingBuyPriceCommand reads `active()` rows on every run.
 *
 * audit: every change captured via spatie/laravel-activitylog (project
 * "audit everything" constraint).
 */
final class ProductException extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'sku',
        'reason',
        'is_paused',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_paused' => 'bool',
    ];

    /**
     * Active (non-paused) exceptions — the FlagProductsMissingBuyPriceCommand
     * scope. Paused rows preserve audit + reason but are ignored by the sync.
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_paused', false);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['sku', 'reason', 'is_paused', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static function newFactory(): ProductExceptionFactory
    {
        return ProductExceptionFactory::new();
    }
}
