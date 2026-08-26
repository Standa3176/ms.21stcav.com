<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260826-cpp — pause ONE competitor's influence on pricing, with an
 * expiry date baked in.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE CASE
 *
 * screenmoove stopped publishing on 2026-07-19. It held 176,132 of ~271,000
 * competitor price rows — 65% of everything. The pricing lookup only considers
 * rows inside `--max-age-days` (30), so on 2026-08-18 every screenmoove row
 * aged out at once and every product whose ONLY competitor was screenmoove
 * silently moved to cost-plus. That is what the Screen International screens
 * are: priced 1p under screenmoove from 2026-06-01, and orphaned last week.
 *
 * So a pause is NOT needed to stop screenmoove pricing today — age already
 * does that. It is needed for the moment the feed is FIXED: fresh rows re-enter
 * the window immediately and every affected price snaps back to undercut with
 * nobody reviewing it. A five-week-stale feed coming back online is exactly
 * when you want a beat to check the prices are sane first.
 *
 * WHY A DATE AND NOT A BOOLEAN
 *
 * A boolean would have to be remembered. Every temporary measure in this system
 * that relied on being remembered is still here — the 260824-w9k overrides are
 * a fortnight old. A date expires whether or not anyone comes back to it, so
 * the failure mode is "protection ends" rather than "competitor silently
 * ignored forever".
 *
 * NULL = not paused, which is the state of every competitor by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitors', function (Blueprint $t): void {
            $t->date('pricing_paused_until')
                ->nullable()
                ->after('is_active');

            $t->string('pricing_pause_reason', 255)->nullable()->after('pricing_paused_until');
        });
    }

    public function down(): void
    {
        Schema::table('competitors', function (Blueprint $t): void {
            $t->dropColumn(['pricing_paused_until', 'pricing_pause_reason']);
        });
    }
};
