<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Plan 01 — opt-in flag for competitor stale-feed + CSV-issue alerts
 * (Phase 2 D-08 + Phase 4 D-12 pattern).
 *
 * Default FALSE so new recipients are opt-in (competitor alert payloads reveal
 * supplier/margin data). Seeded fallback row (ops@meetingstore.co.uk) is
 * force-updated to TRUE by the migration so Pitfall M "no active recipient"
 * outage can't strand competitor alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->boolean('receives_competitor_alerts')->default(false)->after('receives_crm_alerts');
        });

        // Backfill the Pitfall M fallback recipient so competitor alerts land somewhere safe by default.
        DB::table('alert_recipients')
            ->where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_competitor_alerts' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->dropColumn('receives_competitor_alerts');
        });
    }
};
