<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Plan 01 — opt-in flag for auto-create-failed alerts
 * (Phase 2 D-08 + Phase 4 D-12 + Phase 5 Plan 01 pattern).
 *
 * Default FALSE so new recipients are opt-in. Seeded fallback row
 * (ops@meetingstore.co.uk via AlertRecipientSeeder) is force-updated to TRUE
 * by this migration so Pitfall M "no active recipient" outage cannot strand
 * auto-create-failed alerts (mirrors the Phase 4/5 backfill pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->boolean('receives_auto_create_alerts')
                ->default(false)
                ->after('receives_competitor_alerts');
        });

        // Pitfall M — ensure the fallback recipient always receives auto-create
        // alerts so a missed flag doesn't strand the alert pipeline on deploy.
        DB::table('alert_recipients')
            ->where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_auto_create_alerts' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->dropColumn('receives_auto_create_alerts');
        });
    }
};
