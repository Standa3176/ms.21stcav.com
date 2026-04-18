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
            // Plan 04 adds: TestSuggestionSeeder
            // Plan 05 adds: AlertRecipientSeeder (admin email)
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
