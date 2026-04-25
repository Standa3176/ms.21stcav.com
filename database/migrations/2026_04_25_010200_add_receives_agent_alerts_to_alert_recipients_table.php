<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Plan 01 — opt-in flag for agent alerts (mirrors Phase 2 D-08 +
 * Phase 4 D-12 + Phase 5 + Phase 6 + Phase 7 receives_* pattern).
 *
 * Default TRUE for new recipients (matches Phase 7 receives_weekly_digest
 * default) because monthly_budget_blocked + run failures + first-of-day
 * guardrail blocks are ambient ops signal — silent failure here strands
 * visibility. Operators opt individual recipients out via the Filament
 * AlertRecipientResource form toggle.
 *
 * Pitfall P6-D belt-and-braces: backfill sweep updates any pre-existing
 * NULL row to TRUE so the DEFAULT clause being ignored never strands an
 * existing recipient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->boolean('receives_agent_alerts')
                ->default(true)
                ->after('receives_weekly_digest');
        });

        // Pitfall P6-D — explicit UPDATE so existing rows whose column landed
        // NULL (e.g. fixtures from before the DEFAULT was introduced) flip TRUE.
        DB::table('alert_recipients')
            ->whereNull('receives_agent_alerts')
            ->update(['receives_agent_alerts' => true]);
    }

    public function down(): void
    {
        Schema::table('alert_recipients', function (Blueprint $t): void {
            $t->dropColumn('receives_agent_alerts');
        });
    }
};
