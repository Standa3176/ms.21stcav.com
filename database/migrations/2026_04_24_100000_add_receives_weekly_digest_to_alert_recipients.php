<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 Plan 01 — D-08 opt-in flag for the weekly digest email.
 *
 * Adds `receives_weekly_digest` BOOLEAN nullable default TRUE to alert_recipients.
 * Unlike the Phase 2 receives_sync_reports / Phase 4 receives_crm_alerts columns,
 * the weekly digest default is TRUE (not FALSE) because it is an ambient ops
 * summary, not an incident alert — keeping every existing recipient on by default
 * matches CONTEXT.md D-08 ("default true for existing fallback ops@meetingstore.co.uk").
 *
 * Pitfall P6-D belt-and-braces: also runs an explicit UPDATE after ADD COLUMN so
 * any existing row whose column ended up NULL (e.g. historical fixtures that
 * predate the DEFAULT clause) still gets the default value.
 *
 * Operators opt individual recipients out via the Filament AlertRecipientResource
 * form toggle (Plan 07-04 extends the form with this 5th boolean).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table): void {
            $table->boolean('receives_weekly_digest')
                ->nullable()
                ->default(true)
                ->after('receives_auto_create_alerts');
        });

        // Pitfall P6-D — belt-and-braces UPDATE so every existing row lands on TRUE
        // even if the DEFAULT clause on ADD COLUMN was ignored for any reason.
        DB::table('alert_recipients')
            ->whereNull('receives_weekly_digest')
            ->update(['receives_weekly_digest' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $table): void {
            $table->dropColumn('receives_weekly_digest');
        });
    }
};
