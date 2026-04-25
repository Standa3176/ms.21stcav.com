<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Alerting\Models\AlertRecipient;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * 1. Roles + permissions (D-03 — idempotent, runs on every deploy).
     * 2. Test admin user so `/admin/login` is immediately usable on first boot.
     *    Password is `password` — CHANGE IN PRODUCTION via:
     *      php artisan tinker
     *      > User::where('email','admin@meetingstore.co.uk')->first()->update(['password' => bcrypt('…')])
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            TestSuggestionSeeder::class,
            AlertRecipientSeeder::class,
            \Database\Seeders\Phase3\DefaultPricingTierSeeder::class,
            // Phase 4 Plan 01 — 7 Woo-status rows with placeholder Bitrix stage labels.
            // Admin replaces labels with real STAGE_IDs via Plan 04-04 Filament UI.
            \Database\Seeders\Phase4\CrmStatusMappingSeeder::class,
            // Phase 4 Plan 03 — 40 default Woo↔Bitrix field mappings ported from
            // the legacy itgalaxy plugin's CrmFields.php. Idempotent firstOrCreate.
            \Database\Seeders\Phase4\CrmFieldMappingSeeder::class,
            // Phase 6 Plan 01 — 3 default auto-create skip rules (D-04).
            // Idempotent firstOrCreate keyed on (scope, value). Re-runs always
            // yield exactly 3 rows. Registered explicitly (NOT glob) per Phase 5
            // 05-04a MySQL `_` LIKE wildcard lesson.
            AutoCreateSkipRuleSeeder::class,
            // Phase 9 Plan 01 — 4 customer groups (trade=10, reseller=20,
            // education=30, nhs=40). Idempotent firstOrCreate keyed on slug.
            // Admin adds/deactivates groups via Filament CustomerGroupResource
            // (Plan 09-04); seeder only fills the D-01 baseline.
            \Database\Seeders\Phase9\CustomerGroupSeeder::class,
        ]);

        // Phase 5 Plan 04a — belt-and-braces promotion of the Pitfall M fallback
        // recipient to receive competitor alerts. The AlertRecipientSeeder sets
        // this on firstOrCreate, but if the row existed pre-Phase-5 (common on
        // long-lived dev DBs) the flag stays FALSE. This UPDATE force-promotes.
        // Idempotent: running twice has the same effect as running once.
        AlertRecipient::where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_competitor_alerts' => true]);

        // Phase 5 Plan 04b — demo fixture for the human-verify walkthrough
        // (T-05-04b-05 gate: local/testing only — seeder is otherwise inert).
        // Idempotent: safe to call on every deploy.
        if (app()->environment(['local', 'testing'])) {
            $this->call(CompetitorDemoSeeder::class);
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@meetingstore.co.uk'],
            [
                'name' => 'Ops Admin',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }
    }
}
