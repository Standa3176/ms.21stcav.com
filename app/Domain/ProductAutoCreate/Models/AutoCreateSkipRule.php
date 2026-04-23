<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Models;

use Database\Factories\Domain\ProductAutoCreate\AutoCreateSkipRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 6 Plan 01 — AutoCreateSkipRule (D-04).
 *
 * Admin-editable rule that causes HandleNewSupplierSku to short-circuit
 * auto-create dispatch for a matching SKU. 4 scope branches evaluated via
 * matches(string $sku, float $priceGbp): bool — the one load-bearing helper.
 *
 * Scope semantics:
 *   - brand        → case-insensitive match of the rule's value against the
 *                    candidate's supplier-supplied brand (passed in context)
 *   - category     → case-insensitive match against supplier category
 *   - sku_pattern  → `preg_match('/'.$value.'/', $sku)` with @error-suppress
 *                    on malformed regex; returns false on compile error
 *                    (T-06-01-01 catastrophic-backtracking mitigation). Values
 *                    must be capped at 256 chars by the admin Form Request
 *                    (Plan 04 Filament Resource).
 *   - price_range  → parses '<N' / '>N' / 'N-M' GBP strings (inclusive bounds
 *                    on ranges).
 *
 * reason enum mirrors auto_create_rejections for "why did it skip / reject"
 * analytics continuity.
 */
final class AutoCreateSkipRule extends Model
{
    use HasFactory;
    use LogsActivity;

    public const SCOPE_BRAND = 'brand';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_SKU_PATTERN = 'sku_pattern';

    public const SCOPE_PRICE_RANGE = 'price_range';

    protected $fillable = [
        'scope',
        'value',
        'reason',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'created_by_user_id' => 'integer',
    ];

    /** Only rows with is_active=true. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Evaluate the rule against a candidate SKU + supplier context.
     *
     * For brand/category scopes, pass the supplier-supplied brand/category via
     * $context['brand'] / $context['category']; a null or missing value is a
     * guaranteed non-match (never throws).
     *
     * @param  array{brand?: ?string, category?: ?string}  $context  optional
     *     supplier brand + category strings for brand/category-scoped rules
     */
    public function matches(string $sku, float $priceGbp, array $context = []): bool
    {
        $value = (string) $this->value;

        return match ($this->scope) {
            self::SCOPE_BRAND => $this->matchString($context['brand'] ?? null, $value),
            self::SCOPE_CATEGORY => $this->matchString($context['category'] ?? null, $value),
            self::SCOPE_SKU_PATTERN => $this->matchPattern($sku, $value),
            self::SCOPE_PRICE_RANGE => $this->matchPriceRange($priceGbp, $value),
            default => false,
        };
    }

    private function matchString(?string $candidate, string $ruleValue): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        return strcasecmp(trim($candidate), trim($ruleValue)) === 0;
    }

    /**
     * Regex match with catastrophic-backtracking guard (T-06-01-01).
     * `@` suppresses warnings on malformed patterns; the `===1` check
     * treats both false (error) and 0 (no match) as non-matches.
     */
    private function matchPattern(string $sku, string $pattern): bool
    {
        // Defensive 256-char cap (admin Form Request enforces; belt-and-braces).
        if (strlen($pattern) > 256) {
            return false;
        }
        $delimited = '/'.$pattern.'/';

        return @preg_match($delimited, $sku) === 1;
    }

    /**
     * Parse `<N` / `>N` / `N-M` price-range strings.
     *
     *   <25   → true when $priceGbp < 25.00
     *   >500  → true when $priceGbp > 500.00
     *   25-50 → true when 25.00 <= $priceGbp <= 50.00
     */
    private function matchPriceRange(float $priceGbp, string $value): bool
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '<')) {
            $n = (float) substr($trimmed, 1);

            return $priceGbp < $n;
        }

        if (str_starts_with($trimmed, '>')) {
            $n = (float) substr($trimmed, 1);

            return $priceGbp > $n;
        }

        if (str_contains($trimmed, '-')) {
            [$lo, $hi] = array_map('trim', explode('-', $trimmed, 2));
            $loF = (float) $lo;
            $hiF = (float) $hi;

            return $priceGbp >= $loF && $priceGbp <= $hiF;
        }

        return false;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['scope', 'value', 'reason', 'is_active', 'created_by_user_id'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): AutoCreateSkipRuleFactory
    {
        return AutoCreateSkipRuleFactory::new();
    }
}
