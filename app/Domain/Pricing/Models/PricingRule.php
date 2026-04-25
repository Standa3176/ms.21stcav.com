<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Observers\PricingRuleObserver;
use App\Domain\TradePricing\Models\CustomerGroup;
use Database\Factories\Domain\Pricing\PricingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 3 Plan 01 — PricingRule (D-06, D-07).
 *
 * Scope-driven margin rule. Consumed by RuleResolver (Plan 02) which sorts by:
 *   specificity DESC (brand+category > category > brand > default_tier)
 *   → priority DESC (D-07 user-set tiebreak)
 *   → id ASC         (final fallback)
 *
 * Audit via spatie/activitylog on the pricing-affecting columns only — the
 * `active` toggle and margin basis points matter; created_at drift does not.
 *
 * Scope enum constants are the single source of truth — code referring to
 * 'brand' as a magic string should use PricingRule::SCOPE_BRAND instead.
 *
 * Phase 9 Plan 01 (TRDE-01) — additive extension only:
 *   - customer_group_id (nullable BIGINT FK → customer_groups.id) added
 *     to $fillable + $casts + LogsActivity audited columns
 *   - customerGroup() BelongsTo relation added for TradeRuleResolver
 *     (Plan 09-02) and the additive PricingRuleResource Filament Select
 *     (Plan 09-04). null = retail rule (v1 byte-identical behaviour).
 */
#[ObservedBy(PricingRuleObserver::class)]
final class PricingRule extends Model
{
    use HasFactory;
    use LogsActivity;

    public const SCOPE_BRAND = 'brand';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_BRAND_CATEGORY = 'brand_category';
    public const SCOPE_DEFAULT_TIER = 'default_tier';

    protected $fillable = [
        'scope',
        'customer_group_id',
        'brand_id',
        'category_id',
        'margin_basis_points',
        'priority',
        'is_default_tier',
        'tier_min_pennies',
        'tier_max_pennies',
        'active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_default_tier' => 'bool',
        'active' => 'bool',
        'margin_basis_points' => 'integer',
        'priority' => 'integer',
        'tier_min_pennies' => 'integer',
        'tier_max_pennies' => 'integer',
        'customer_group_id' => 'integer',
        'brand_id' => 'integer',
        'category_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'scope',
                'customer_group_id',
                'brand_id',
                'category_id',
                'margin_basis_points',
                'priority',
                'is_default_tier',
                'tier_min_pennies',
                'tier_max_pennies',
                'active',
            ])
            ->logOnlyDirty();
    }

    protected static function newFactory(): PricingRuleFactory
    {
        return PricingRuleFactory::new();
    }
}
