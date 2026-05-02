<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11.1 Plan 01 — alert_recipients.receives_competitor_ftp_alerts (D-12).
 *
 * Mirrors the v1.x-friendly extension pattern from:
 *   - Phase 4 (receives_crm_alerts)
 *   - Phase 5 (receives_competitor_alerts)
 *   - Phase 6 (receives_auto_create_alerts)
 *   - Phase 7 (receives_weekly_digest)
 *   - Phase 8 (receives_agent_alerts)
 *   - Phase 11 Plan 01 (receives_quote_alerts)
 *
 * When a CompetitorFtpSource fails 3 consecutive pulls, CompetitorFtpPullCommand
 * resolves recipients via `AlertRecipient::query()->active()->receivesCompetitorFtpAlerts()->get()`
 * and dispatches CompetitorFtpPullFailedNotification.
 *
 * Default FALSE (alerts are opt-in for non-essential incidents); the
 * seeded fallback row (ops@meetingstore.co.uk, Pitfall M) is force-updated
 * to TRUE so the "no active recipient" outage cannot strand FTP pull alerts.
 *
 * Column placement: AFTER receives_quote_alerts (Phase 11 Plan 01 — verified
 * latest column via $fillable ordering in app/Domain/Alerting/Models/AlertRecipient.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->boolean('receives_competitor_ftp_alerts')
                ->default(false)
                ->after('receives_quote_alerts');
        });

        // Force-update the seeded fallback row to TRUE so the Pitfall M
        // "no active recipient" outage can't strand FTP pull alerts.
        DB::table('alert_recipients')
            ->where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_competitor_ftp_alerts' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->dropColumn('receives_competitor_ftp_alerts');
        });
    }
};
