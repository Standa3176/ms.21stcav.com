<?php

declare(strict_types=1);

namespace Database\Seeders\Phase3;

use App\Domain\Pricing\Models\PricingRule;
use Illuminate\Database\Seeder;

/**
 * Phase 3 Plan 01 — default pricing-tier seeder (D-06 boundaries).
 *
 * Three tier rows matching the legacy Stock Updater plugin's live production
 * margins:
 *   - <£100       → 35% margin (3500 bps)
 *   - £100-499    → 28% margin (2800 bps)
 *   - £500+       → 22% margin (2200 bps)  — null upper = open-ended
 *
 * These values ARE coupled to tests/Fixtures/Pricing/golden-fixtures.json:
 * when ops re-baselines from a live Woo DB snapshot, this seeder AND the
 * fixtures move in the SAME commit (D-04 re-baseline protocol). Same commit
 * message cites the reason.
 *
 * Priority 50 is lower than a user-created specific rule's default (100) so
 * RuleResolver's priority-DESC sort naturally prefers specific rules over
 * these defaults.
 *
 * Idempotent: firstOrCreate keyed on
 * (scope, is_default_tier, tier_min_pennies, tier_max_pennies). Re-running
 * the seeder (or migrate:fresh --seed on every dev/test boot) NEVER overwrites
 * admin-edited margins — it only fills empty slots. T-03-01-06 mitigation.
 */
class DefaultPricingTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            // <£100 — 35% margin
            [
                'tier_min_pennies' => 0,
                'tier_max_pennies' => 9999,
                'margin_basis_points' => 3500,
                'priority' => 50,
            ],
            // £100-499 — 28% margin
            [
                'tier_min_pennies' => 10000,
                'tier_max_pennies' => 49999,
                'margin_basis_points' => 2800,
                'priority' => 50,
            ],
            // £500+ — 22% margin (null upper = open-ended)
            [
                'tier_min_pennies' => 50000,
                'tier_max_pennies' => null,
                'margin_basis_points' => 2200,
                'priority' => 50,
            ],
        ];

        foreach ($tiers as $row) {
            PricingRule::firstOrCreate(
                [
                    'scope' => PricingRule::SCOPE_DEFAULT_TIER,
                    'is_default_tier' => true,
                    'tier_min_pennies' => $row['tier_min_pennies'],
                    'tier_max_pennies' => $row['tier_max_pennies'],
                ],
                [
                    'brand_id' => null,
                    'category_id' => null,
                    'margin_basis_points' => $row['margin_basis_points'],
                    'priority' => $row['priority'],
                    'active' => true,
                ],
            );
        }

        $this->command?->info(sprintf(
            'Default pricing tiers seeded: %d rows (is_default_tier=true)',
            PricingRule::where('is_default_tier', true)->count(),
        ));
    }
}
