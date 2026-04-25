<?php

declare(strict_types=1);

namespace App\Domain\TradePricing\Models;

use App\Domain\Pricing\Models\PricingRule;
use Database\Factories\Domain\TradePricing\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Phase 9 Plan 01 — CustomerGroup (D-01, D-02).
 *
 * Admin-managed B2B customer segment. Four groups seeded by
 * Database\Seeders\Phase9\CustomerGroupSeeder (trade / reseller / education /
 * nhs); admin adds new groups via Filament's CustomerGroupResource (D-10).
 *
 * Audit shape mirrors PricingRule's LogsActivity contract — only the
 * sales-relevant columns flow into the activity log; created_at /
 * updated_at drift is NOT audited so dev-environment seeder reruns
 * don't pollute the audit trail.
 *
 * pricingRules() relation is the inverse of PricingRule->customerGroup().
 * Used by the FK ON DELETE RESTRICT path: deleting a group with rules
 * trips the QueryException so admin must either deactivate the group
 * or migrate rules to another group before destroying the row.
 */
final class CustomerGroup extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['slug', 'name', 'is_active', 'display_order'];

    protected $casts = [
        'is_active' => 'bool',
        'display_order' => 'integer',
    ];

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'name', 'is_active', 'display_order'])
            ->logOnlyDirty();
    }

    protected static function newFactory(): CustomerGroupFactory
    {
        return CustomerGroupFactory::new();
    }
}
