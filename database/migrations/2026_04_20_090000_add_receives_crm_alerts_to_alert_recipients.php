<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Plan 03 — opt-in flag for CRM DLQ alert distribution (D-12).
 *
 * Mirrors the Phase 2 Plan 04 D-08 `receives_sync_reports` pattern: a per-row
 * boolean toggle on alert_recipients so admins can pick which recipients get
 * pinged when a CRM push exhausts its retries.
 *
 * Default FALSE so new recipients are opt-in (CRM alerts include full order PII
 * snapshots in the downstream Suggestion evidence — operators must consciously
 * opt in). Seeded fallback row (ops@meetingstore.co.uk) is force-updated to TRUE
 * so failed pushes always have somewhere to land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->boolean('receives_crm_alerts')->default(false)->after('receives_sync_reports');
        });

        // Backfill the Pitfall M fallback recipient so CRM alerts have a safe default target.
        DB::table('alert_recipients')
            ->where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_crm_alerts' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->dropColumn('receives_crm_alerts');
        });
    }
};
