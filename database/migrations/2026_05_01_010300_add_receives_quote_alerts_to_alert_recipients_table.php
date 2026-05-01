<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Plan 01 — alert_recipients.receives_quote_alerts (QUOT-04 forward-compat).
 *
 * Mirrors the v1.x-friendly extension pattern from:
 *   - Phase 4 (receives_crm_alerts)
 *   - Phase 5 (receives_competitor_alerts)
 *   - Phase 6 (receives_auto_create_alerts)
 *   - Phase 7 (receives_weekly_digest)
 *   - Phase 8 (receives_agent_alerts)
 *
 * When ops asks "alert me when a quote expires" or "alert me on Bitrix
 * push failure", the channel is already wired — Plan 11-04 / 11-05 just
 * resolve recipients via `AlertRecipient::query()->active()->receivesQuoteAlerts()->get()`.
 *
 * Default FALSE (alerts are opt-in for non-essential incidents); the
 * seeded fallback row (ops@meetingstore.co.uk, Pitfall M) is force-updated
 * to TRUE so the "no active recipient" outage cannot strand quote alerts.
 * Same pattern as Phase 4 plan 03 + Phase 5 plan 01 + Phase 8 plan 01.
 *
 * Column placement: AFTER receives_agent_alerts (Phase 8 P05) — verified
 * via `app/Domain/Alerting/Models/AlertRecipient.php $fillable` ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->boolean('receives_quote_alerts')
                ->default(false)
                ->after('receives_agent_alerts');
        });

        // Force-update the seeded fallback row to TRUE so the Pitfall M
        // "no active recipient" outage can't strand quote alerts. Mirrors
        // Phase 4 / Phase 5 / Phase 8 alert-extension pattern.
        DB::table('alert_recipients')
            ->where('email', 'ops@meetingstore.co.uk')
            ->update(['receives_quote_alerts' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->dropColumn('receives_quote_alerts');
        });
    }
};
