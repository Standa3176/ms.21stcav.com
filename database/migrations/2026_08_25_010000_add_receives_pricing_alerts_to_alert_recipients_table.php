<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260825-n5v — alert_recipients.receives_pricing_alerts.
 *
 * Every pricing fault found in August 2026 was found by a human deciding to go
 * looking. The 5,319 lost price pushes (2026-08-18→22) surfaced only when
 * someone read failed_jobs by hand; the CP4 homonym had been live for weeks;
 * the projection-screen families were discovered while chasing an unrelated
 * question. There is a `pricing:audit-movements` command and a
 * `pricing:review-ceiling-blocks` command and NEITHER is scheduled.
 *
 * A separate flag rather than reusing receives_sync_reports: a sync report is
 * routine and skimmable, whereas "a live product is selling below the agreed
 * margin" is an alarm. Mixing them trains people to skim the alarms.
 *
 * Seeded TRUE on the fallback ops row for the same reason the FTP flag was
 * (Pitfall M): a pricing alarm with no active recipient is not an alarm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->boolean('receives_pricing_alerts')
                ->default(false)
                ->after('receives_competitor_ftp_alerts');
        });

        DB::table('alert_recipients')
            ->where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_pricing_alerts' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->dropColumn('receives_pricing_alerts');
        });
    }
};
