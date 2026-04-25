<?php

declare(strict_types=1);

namespace Database\Seeders\Phase9;

use App\Domain\TradePricing\Models\CustomerGroup;
use Illuminate\Database\Seeder;

/**
 * Phase 9 Plan 01 — CustomerGroupSeeder (D-01 — 4 admin-managed groups).
 *
 * Mirrors Phase3\DefaultPricingTierSeeder firstOrCreate idempotency: re-running
 * (or migrate:fresh --seed on every dev/test boot) NEVER overwrites admin-edited
 * names — it only fills empty slots keyed by slug.
 *
 * display_order spaced 10/20/30/40 so admin can insert new groups (e.g.
 * "Charity") between rows via Filament without renumbering everything.
 *
 * is_active=true on all 4 — admin can deactivate via CustomerGroupResource
 * (D-10) if a group becomes unused; deactivation does NOT delete
 * (FK ON DELETE RESTRICT — see Plan 09-01 second migration).
 */
class CustomerGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['slug' => 'trade',     'name' => 'Trade Customer',          'display_order' => 10],
            ['slug' => 'reseller',  'name' => 'Reseller / Distributor',  'display_order' => 20],
            ['slug' => 'education', 'name' => 'Education Sector',        'display_order' => 30],
            ['slug' => 'nhs',       'name' => 'NHS / Healthcare',        'display_order' => 40],
        ];

        foreach ($groups as $row) {
            CustomerGroup::firstOrCreate(
                ['slug' => $row['slug']],
                $row + ['is_active' => true],
            );
        }

        $this->command?->info(sprintf(
            'CustomerGroupSeeder: %d groups present (4 expected)',
            CustomerGroup::count(),
        ));
    }
}
