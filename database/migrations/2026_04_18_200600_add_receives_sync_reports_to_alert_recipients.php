<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Plan 02-04 — D-08 opt-in for sync-report distribution.
 *
 * Adds `receives_sync_reports` BOOLEAN nullable default TRUE to alert_recipients.
 * Default true so existing seeded ops@meetingstore.co.uk fallback starts
 * receiving the daily CSV sync report without a manual toggle.
 *
 * Operators opt individual recipients out via the Filament AlertRecipientResource
 * form toggle (Plan 02-04 Task 1 update).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            // D-08: default TRUE so seeded fallback row opts-in automatically.
            $table->boolean('receives_sync_reports')->nullable()->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table) {
            $table->dropColumn('receives_sync_reports');
        });
    }
};
