<?php

declare(strict_types=1);

namespace Database\Seeders;

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
        ]);

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
