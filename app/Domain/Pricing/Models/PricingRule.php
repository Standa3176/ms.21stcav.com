<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Observers\PricingRuleObserver;
use Database\Factories\Domain\Pricing\PricingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'brand_id' => 'integer',
        'category_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'scope',
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
