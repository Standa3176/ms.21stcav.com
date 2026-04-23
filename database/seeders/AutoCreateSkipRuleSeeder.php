<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\ProductAutoCreate\Models\AutoCreateSkipRule;
use Illuminate\Database\Seeder;

/**
 * Phase 6 Plan 01 — AutoCreateSkipRuleSeeder (D-04).
 *
 * Seeds EXACTLY 3 default skip rules:
 *   1. brand         = SparesPlus        (supplier-kit / spares-only vendor)
 *   2. sku_pattern   = ^TEST-            (QA fixtures)
 *   3. price_range   = <25               (below-viability threshold)
 *
 * Idempotent: firstOrCreate keyed on (scope, value). Re-running the seeder
 * always yields exactly 3 rows. Is_active=true so rules are live as soon as
 * they seed.
 *
 * Note (MySQL `_` LIKE wildcard bug — Phase 5 05-04a lesson): this seeder
 * does NOT query Permission::where('name','like','%_skip_rule') — it uses
 * firstOrCreate on Eloquent models, so the LIKE pitfall doesn't apply here.
 * The RolePermissionSeeder lookup for AutoCreateSkipRule's Shield-generated
 * permissions is handled separately in Plan 06-04.
 */
class AutoCreateSkipRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'scope' => AutoCreateSkipRule::SCOPE_BRAND,
                'value' => 'SparesPlus',
                'reason' => 'spare_part_or_accessory',
            ],
            [
                'scope' => AutoCreateSkipRule::SCOPE_SKU_PATTERN,
                'value' => '^TEST-',
                'reason' => 'not_a_real_product',
            ],
            [
                'scope' => AutoCreateSkipRule::SCOPE_PRICE_RANGE,
                'value' => '<25',
                'reason' => 'below_viability_threshold',
            ],
        ];

        foreach ($rules as $row) {
            AutoCreateSkipRule::firstOrCreate(
                ['scope' => $row['scope'], 'value' => $row['value']],
                [
                    'reason' => $row['reason'],
                    'is_active' => true,
                    'created_by_user_id' => null,
                ]
            );
        }
    }
}
